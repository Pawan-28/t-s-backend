<?php

namespace App\Services\Otp;

use App\Models\PhoneOtp;
use App\Models\User;
use App\Services\Wati\WatiClient;
use Illuminate\Support\Facades\DB;

/**
 * App-generated WhatsApp OTP (WATI is only the delivery channel). The raw code
 * is never stored, returned, queued or logged: only an HMAC (keyed with APP_KEY,
 * bound to user+phone) is persisted, compared in constant time, single use.
 */
class OtpService
{
    public function __construct(private WatiClient $wati) {}

    /** Creates a fresh OTP (older pending ones are invalidated) and sends it. Always returns the row, even if WATI fails. */
    public function request(User $user, string $phone): PhoneOtp
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $otp = DB::transaction(function () use ($user, $phone, $code) {
            PhoneOtp::query()
                ->where('user_id', $user->id)
                ->where('is_verified', false)
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);

            return PhoneOtp::create([
                'user_id' => $user->id,
                'phone' => $phone,
                'code_hash' => $this->hash($user->id, $phone, $code),
                'expires_at' => now()->addMinutes((int) config('portal.otp.ttl_minutes', 10)),
                'attempts' => 0,
                'is_verified' => false,
            ]);
        }, 3);

        // Sent inline (not queued): a queue payload would persist the plaintext code in Redis.
        // The approved WATI template is positional: parameter name "1" (do not rename).
        $this->wati->sendTemplate($phone, (string) config('portal.wati.templates.otp'), [['name' => '1', 'value' => $code]]);

        return $otp;
    }

    /** @throws OtpException with the Django error text */
    public function verify(User $user, string $code): void
    {
        $result = DB::transaction(function () use ($user, $code) {
            $otp = PhoneOtp::query()
                ->where('user_id', $user->id)
                ->where('is_verified', false)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if (! $otp) {
                return 'none';
            }
            // Expiry is decided by the database (same clock/format as the write), not by re-parsing the column in PHP.
            if (! PhoneOtp::query()->whereKey($otp->id)->where('expires_at', '>=', now())->exists()) {
                return 'expired';
            }
            if ($otp->attempts >= (int) config('portal.otp.max_attempts', 5)) {
                return 'locked';
            }

            // The attempt is counted before comparing (committed even when the code is wrong).
            $otp->increment('attempts');

            if (! hash_equals($otp->code_hash, $this->hash($user->id, $otp->phone, $code))) {
                return 'wrong';
            }

            if (User::query()->where('phone', $otp->phone)->whereKeyNot($user->id)->exists()) {
                $otp->update(['expires_at' => now()]);

                return 'taken';
            }

            $otp->update(['is_verified' => true]);
            $user->forceFill(['phone' => $otp->phone, 'phone_verified_at' => now()])->save();

            return 'ok';
        }, 3); // deadlock / lock-wait retry; the attempt counter is re-read under the lock, so a retry never double-counts

        $message = match ($result) {
            'none' => 'No pending OTP request found. Request a new code.',
            'expired' => 'This code has expired. Request a new one.',
            'locked' => 'Too many incorrect attempts. Request a new code.',
            'wrong' => 'Incorrect code.',
            'taken' => 'This phone number is already linked to another account.',
            default => null,
        };
        if ($message !== null) {
            throw new OtpException($message);
        }
    }

    public function hash(int $userId, string $phone, string $code): string
    {
        return hash_hmac('sha256', $userId.'|'.$phone.'|'.$code, (string) config('app.key'));
    }
}
