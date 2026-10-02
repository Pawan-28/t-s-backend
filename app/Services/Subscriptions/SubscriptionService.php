<?php

namespace App\Services\Subscriptions;

use App\Enums\Role;
use App\Models\Article;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Checkout / verification / webhook / lifecycle. One-time payments only: a renewal is
 * a NEW subscription + payment after the current one expires, never an "update".
 * State changes run in transactions with row locks, so verify + webhook racing or
 * replaying can never activate twice or extend twice.
 */
class SubscriptionService
{
    public function __construct(private RazorpayClient $razorpay, private SubscriptionNotifier $notifier) {}

    // ---------------------------------------------------------------- reads

    public static function currentFor(User $user): ?Subscription
    {
        return Subscription::query()->with('plan')->where('user_id', $user->id)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    // ------------------------------------------------------------- checkout

    /**
     * @return array{subscription_id:int,order_id:string,amount:int,currency:string,key_id:string,prefill:array{email:string,contact:string}}
     *
     * @throws CheckoutException
     */
    public function startCheckout(User $user, string $planSlug, string $contactEmail, string $contactPhone): array
    {
        $plan = SubscriptionPlan::query()->where('slug', $planSlug)->where('is_active', true)->first();
        if (! $plan) {
            throw new CheckoutException('No active subscription plan with that slug.');
        }
        if (! $this->razorpay->isConfigured()) {
            throw new CheckoutException('Could not start checkout: payments are not configured.');
        }

        // The price ALWAYS comes from the plan row, never from the client.
        $amountPaise = Money::toMinorUnits((string) $plan->price_amount); // exact integer math, never float

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::PENDING,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
        ]);

        try {
            $order = $this->razorpay->createOrder($amountPaise, $plan->price_currency, 'sub-'.$subscription->id);
            Payment::create([
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'razorpay_order_id' => $order['id'],
                'amount' => $plan->price_amount,
                'currency' => $plan->price_currency,
                'status' => Payment::CREATED,
            ]);
        } catch (\Throwable $e) {
            $subscription->delete(); // nothing was charged; do not leave a dangling PENDING row
            if ($e instanceof CheckoutException) {
                throw $e;
            }
            report($e);
            throw new CheckoutException('Could not start checkout: please try again.');
        }

        // Fill a blank account phone from the checkout contact (left unverified). Never
        // overwrite an existing phone, never touch the login e-mail, respect uniqueness.
        if ($contactPhone !== '' && ! $user->phone
            && ! User::query()->where('phone', $contactPhone)->whereKeyNot($user->id)->exists()) {
            $user->forceFill(['phone' => $contactPhone])->save();
        }

        return [
            'subscription_id' => $subscription->id,
            'order_id' => $order['id'],
            'amount' => $amountPaise,
            'currency' => $plan->price_currency,
            'key_id' => $this->razorpay->keyId(),
            'prefill' => ['email' => $contactEmail, 'contact' => $contactPhone],
        ];
    }

    // --------------------------------------------------------------- verify

    /** @throws CheckoutException */
    public function verifyAndActivate(User $user, string $orderId, string $paymentId, string $signature): Subscription
    {
        $payment = Payment::query()->where('razorpay_order_id', $orderId)->where('user_id', $user->id)->first();
        if (! $payment) {
            throw new CheckoutException('No matching payment for that order id.');
        }

        if (! $this->razorpay->verifyPaymentSignature($orderId, $paymentId, $signature)) {
            // A bad signature can never flip a PAID payment; on the first failure notify once.
            if ($this->markFailed($orderId, $paymentId, 'Signature verification failed.')) {
                $this->notifier->paymentFailed($payment->subscription()->with('plan', 'user')->first());
            }
            throw new CheckoutException('Payment signature verification failed.');
        }

        [$subscription, $activatedNow] = $this->activate($orderId, $paymentId, $signature, $user);
        if ($activatedNow) {
            $this->notifier->activated($subscription);
        }

        return $subscription->load('plan');
    }

    // -------------------------------------------------------------- webhook

    /**
     * Authenticated-by-signature Razorpay event (already verified by the controller).
     * Always safe to call repeatedly; unknown orders/events are ignored.
     */
    public function handleWebhookEvent(array $payload): void
    {
        $event = (string) ($payload['event'] ?? '');
        $paymentEntity = $payload['payload']['payment']['entity'] ?? [];
        $orderEntity = $payload['payload']['order']['entity'] ?? [];
        $paymentEntity = is_array($paymentEntity) ? $paymentEntity : [];
        $orderEntity = is_array($orderEntity) ? $orderEntity : [];

        $orderId = $paymentEntity['order_id'] ?? ($orderEntity['id'] ?? null);
        $paymentId = $paymentEntity['id'] ?? null;
        if (! is_string($orderId) || $orderId === '' || ! is_string($paymentId) || $paymentId === '') {
            return;
        }
        $payment = Payment::query()->where('razorpay_order_id', $orderId)->first();
        if (! $payment) {
            return;
        }

        if (in_array($event, ['payment.captured', 'order.paid'], true)) {
            // Never activate when Razorpay reports a different amount/currency than the order we created.
            $amount = $paymentEntity['amount'] ?? null;
            $currency = $paymentEntity['currency'] ?? null;
            if (($amount !== null && ! Money::paiseEquals($amount, (string) $payment->amount))
                || ($currency !== null && strtoupper((string) $currency) !== strtoupper($payment->currency))) {
                Log::warning('Razorpay webhook amount/currency mismatch; ignored.', ['payment_id_row' => $payment->id]);

                return;
            }
            [$subscription, $activatedNow] = $this->activate($orderId, mb_substr($paymentId, 0, 100), '', null);
            if ($activatedNow) {
                $this->notifier->activated($subscription);
            }
        } elseif ($event === 'payment.failed') {
            $reason = mb_substr((string) ($paymentEntity['error_description'] ?? 'Payment failed at the gateway.'), 0, 255);
            if ($this->markFailed($orderId, mb_substr($paymentId, 0, 100), $reason)) {
                $this->notifier->paymentFailed($payment->subscription()->with('plan', 'user')->first());
            }
        }
    }

    // ------------------------------------------------------------ internals

    /** @return array{0:Subscription,1:bool} [subscription, activatedByThisCall] */
    private function activate(string $orderId, string $paymentId, string $signature, ?User $user): array
    {
        return DB::transaction(function () use ($orderId, $paymentId, $signature, $user) {
            $payment = Payment::query()->where('razorpay_order_id', $orderId)
                ->when($user, fn ($q) => $q->where('user_id', $user->id))
                ->lockForUpdate()->first();
            if (! $payment) {
                throw new CheckoutException('No matching payment for that order id.');
            }
            $subscription = Subscription::query()->with(['plan', 'user'])->lockForUpdate()->findOrFail($payment->subscription_id);

            if ($payment->status === Payment::PAID) {
                return [$subscription, false]; // replay: no second activation / extension
            }

            $payment->forceFill([
                'status' => Payment::PAID,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $signature,
                'failure_reason' => '',
            ])->save();

            $now = now();
            $subscription->forceFill([
                'status' => Subscription::ACTIVE,
                'started_at' => $now,
                'expires_at' => $now->copy()->addDays((int) $subscription->plan->duration_days),
            ])->save();

            // Promote USER -> SUBSCRIBER only; never downgrade an ADMIN/REPORTER who subscribes.
            if ($subscription->user->role === Role::USER) {
                $subscription->user->forceFill(['role' => Role::SUBSCRIBER])->save();
            }

            return [$subscription, true];
        }, 3); // retried on InnoDB deadlock (1213) / lock wait timeout (1205); lock order: payment -> subscription -> user
    }

    /** @return bool true when this call performed the first transition to FAILED */
    private function markFailed(string $orderId, string $paymentId, string $reason): bool
    {
        return DB::transaction(function () use ($orderId, $paymentId, $reason) {
            $payment = Payment::query()->where('razorpay_order_id', $orderId)->lockForUpdate()->first();
            if (! $payment || $payment->status === Payment::PAID) {
                return false;
            }
            $first = $payment->status !== Payment::FAILED;
            $payment->forceFill([
                'status' => Payment::FAILED,
                'razorpay_payment_id' => mb_substr($paymentId, 0, 100),
                'failure_reason' => $reason,
            ])->save();

            return $first;
        }, 3);
    }

    // ------------------------------------------------------------ lifecycle

    /** ACTIVE past expires_at -> EXPIRED, once. @return int number expired */
    public function expireOverdue(): int
    {
        $count = 0;
        Subscription::query()->where('status', Subscription::ACTIVE)->where('expires_at', '<=', now())
            ->orderBy('id')->select('id')->chunkById(100, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $claimed = DB::transaction(function () use ($row) {
                        // Atomic claim: only one runner flips the row and sets the once-flag.
                        $n = Subscription::query()->whereKey($row->id)
                            ->where('status', Subscription::ACTIVE)->where('expires_at', '<=', now())
                            ->update(['status' => Subscription::EXPIRED, 'expired_notified_at' => now(), 'updated_at' => now()]);
                        if ($n !== 1) {
                            return null;
                        }
                        $sub = Subscription::query()->with(['plan', 'user'])->find($row->id);
                        if ($sub->user->role === Role::SUBSCRIBER && ! Subscription::query()
                            ->where('user_id', $sub->user_id)->where('status', Subscription::ACTIVE)
                            ->where('expires_at', '>', now())->exists()) {
                            $sub->user->forceFill(['role' => Role::USER])->save();
                        }

                        return $sub;
                    }, 3);
                    if ($claimed) {
                        $this->notifier->expired($claimed);
                        $count++;
                    }
                }
            });

        return $count;
    }

    /** Reminders for ACTIVE subscriptions expiring within N days, once each. @return int number reminded */
    public function remindExpiring(?int $days = null): int
    {
        $days ??= (int) config('portal.subscriptions.expiry_reminder_days', 3);
        $count = 0;
        Subscription::query()->where('status', Subscription::ACTIVE)
            ->where('expires_at', '>', now())->where('expires_at', '<=', now()->addDays($days))
            ->whereNull('expiring_notified_at')
            ->orderBy('id')->select('id')->chunkById(100, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $n = Subscription::query()->whereKey($row->id)->whereNull('expiring_notified_at')
                        ->where('status', Subscription::ACTIVE)
                        ->update(['expiring_notified_at' => now()]);
                    if ($n === 1) {
                        $this->notifier->expiring(Subscription::query()->with(['plan', 'user'])->find($row->id));
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Newly published non-public articles -> WhatsApp/e-mail to active subscribers (Django Celery
     * task). Articles older than 24h are stamped without sending, so an import/first run never
     * mass-notifies about old content. @return int articles processed
     */
    public function notifySubscribersOfPublishedArticles(): int
    {
        $articles = Article::query()->where('status', 'PUBLISHED')->where('access_level', '!=', 'PUBLIC')
            ->whereNull('subscribers_notified_at')->orderBy('id')->get();
        if ($articles->isEmpty()) {
            return 0;
        }
        $subs = Subscription::query()->with('user')->where('status', Subscription::ACTIVE)
            ->where('expires_at', '>', now())->get()->unique('user_id');

        foreach ($articles as $article) {
            $claimed = Article::query()->whereKey($article->id)->whereNull('subscribers_notified_at')
                ->update(['subscribers_notified_at' => now()]);
            if ($claimed !== 1 || ($article->published_at && $article->published_at->lt(now()->subDay()))) {
                continue;
            }
            foreach ($subs as $sub) {
                $this->notifier->articlePublished($sub, $article);
            }
        }

        return $articles->count();
    }
}
