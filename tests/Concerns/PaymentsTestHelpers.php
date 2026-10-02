<?php

namespace Tests\Concerns;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Factories\SubscriptionPlanFactory;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

trait PaymentsTestHelpers
{
    protected string $keySecret = 'test_key_secret_value';

    protected string $webhookSecret = 'test_webhook_secret_value';

    protected function configurePayments(): void
    {
        config()->set('portal.razorpay.key_id', 'rzp_test_KEYID');
        config()->set('portal.razorpay.key_secret', $this->keySecret);
        config()->set('portal.razorpay.webhook_secret', $this->webhookSecret);
        config()->set('portal.razorpay.api_base', 'https://api.razorpay.test/v1');
        config()->set('portal.wati.endpoint', 'https://wati.test');
        config()->set('portal.wati.token', 'Bearer wati-secret-token');
        config()->set('portal.wati.templates', [
            'otp' => 'otp_verification', 'subscription_activated' => 'sub_activated', 'payment_failed' => 'pay_failed',
            'subscription_expiring' => 'sub_expiring', 'subscription_expired' => 'sub_expired', 'article_published' => 'art_pub',
        ]);
    }

    protected function fakeExternal(string $orderId = 'order_TESTORDER1', int $razorpayStatus = 200, array $razorpayBody = []): void
    {
        Http::swap(new Factory);
        Http::fake([
            'api.razorpay.test/*' => Http::response($razorpayBody ?: ['id' => $orderId, 'amount' => 49900, 'currency' => 'INR'], $razorpayStatus),
            'wati.test/*' => Http::response(['result' => true], 200),
        ]);
    }

    protected function plan(array $attrs = []): SubscriptionPlan
    {
        return SubscriptionPlanFactory::new()->create($attrs);
    }

    protected function sign(string $orderId, string $paymentId, ?string $secret = null): string
    {
        return hash_hmac('sha256', $orderId.'|'.$paymentId, $secret ?? $this->keySecret);
    }

    /** @return array{0:Subscription,1:Payment} PENDING subscription with a CREATED payment */
    protected function pendingCheckout(User $user, ?SubscriptionPlan $plan = null, string $order = 'order_TESTORDER1'): array
    {
        $plan ??= $this->plan();
        $sub = Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => Subscription::PENDING, 'contact_email' => 'buyer@example.com', 'contact_phone' => '919876543210']);
        $pay = Payment::create(['subscription_id' => $sub->id, 'user_id' => $user->id, 'razorpay_order_id' => $order, 'amount' => $plan->price_amount, 'currency' => $plan->price_currency, 'status' => Payment::CREATED]);

        return [$sub, $pay];
    }

    protected function signedWebhook(array $payload, ?string $secret = null): array
    {
        $raw = json_encode($payload);

        return [$raw, ['X-Razorpay-Signature' => hash_hmac('sha256', $raw, $secret ?? $this->webhookSecret), 'Content-Type' => 'application/json']];
    }

    protected function postRaw(string $uri, string $raw, array $headers)
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $server['CONTENT_TYPE'] = 'application/json';

        return $this->call('POST', $uri, [], [], [], $server, $raw);
    }
}
