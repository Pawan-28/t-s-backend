<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Otp\OtpException;
use App\Services\Otp\OtpService;
use App\Services\Wati\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Optional WhatsApp phone verification. The code is never part of any response. */
class OtpController extends Controller
{
    public function __construct(private OtpService $otp) {}

    /** POST /subscriptions/otp/request/ - always 200 (a WATI outage is not a caller error). */
    public function request(Request $request): JsonResponse
    {
        $this->stringify($request, 'phone');
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20', function (string $attr, mixed $value, \Closure $fail) {
                if (! PhoneNumber::looksValid(PhoneNumber::normalize((string) $value))) {
                    $fail('Enter a valid phone number, e.g. 9876543210 (no country code needed).');
                }
            }],
        ], SubscriptionController::MESSAGES);

        $phone = PhoneNumber::normalize($data['phone']);
        $response = response()->json(['detail' => 'If WhatsApp delivery is configured, a code has been sent.']);

        // Per-destination cap (any account): a free account must not be able to use us to spam a third
        // party's WhatsApp number. Over the cap we answer exactly like a success but send nothing.
        // Fails open if the limiter backend is down (same policy as ScopeThrottle).
        try {
            $key = 'otp-phone:'.hash('sha256', $phone);
            if (RateLimiter::tooManyAttempts($key, (int) config('portal.otp.max_requests_per_phone_hour', 5))) {
                return $response;
            }
            RateLimiter::hit($key, 3600);
        } catch (\Throwable) {
            // fail open
        }

        $this->otp->request($request->user(), $phone);

        return $response;
    }

    /** POST /subscriptions/otp/verify/ -> 200 {detail} | 400 {detail}. */
    public function verify(Request $request): JsonResponse
    {
        $this->stringify($request, 'code');
        $data = $request->validate(['code' => ['required', 'string', 'max:10']], SubscriptionController::MESSAGES);

        try {
            $this->otp->verify($request->user(), $data['code']);
        } catch (OtpException $e) {
            return response()->json(['detail' => $e->getMessage()], 400);
        }

        return response()->json(['detail' => 'Phone verified.']);
    }

    /** DRF CharField accepts numbers; keep that (a JSON 123456 is treated as "123456"). */
    private function stringify(Request $request, string $key): void
    {
        $v = $request->input($key);
        if (is_int($v) || is_float($v)) {
            $request->merge([$key => (string) $v]);
        }
    }
}
