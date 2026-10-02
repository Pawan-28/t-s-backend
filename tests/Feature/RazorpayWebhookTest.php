<?php

namespace Tests\Feature;

use App\Mail\SubscriptionNoticeMail;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\PaymentsTestHelpers;
use Tests\TestCase;

class RazorpayWebhookTest extends TestCase
{
    use PaymentsTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePayments();
        $this->fakeExternal();
        Mail::fake();
    }

    private function captured(string $order = 'order_TESTORDER1', string $pay = 'pay_W1', int $amount = 49900, string $event = 'payment.captured'): array
    {
        return ['event' => $event, 'payload' => ['payment' => ['entity' => ['id' => $pay, 'order_id' => $order, 'amount' => $amount, 'currency' => 'INR']]]];
    }

    public function test_invalid_or_missing_signature_is_400_and_changes_nothing(): void
    {
        $user = User::factory()->create();
        [$sub, $pay] = $this->pendingCheckout($user);
        $raw = json_encode($this->captured());

        $this->postRaw('/api/subscriptions/webhook/', $raw, ['X-Razorpay-Signature' => 'bad'])->assertStatus(400)->assertJsonPath('detail', 'Invalid webhook signature.');
        $this->postRaw('/api/subscriptions/webhook/', $raw, [])->assertStatus(400);
        [, $h] = $this->signedWebhook($this->captured(), 'another-secret');
        $this->postRaw('/api/subscriptions/webhook/', $raw, $h)->assertStatus(400);

        $this->assertSame(Payment::CREATED, $pay->refresh()->status);
        $this->assertSame(Subscription::PENDING, $sub->refresh()->status);
    }

    public function test_signature_covers_the_raw_body(): void
    {
        $user = User::factory()->create();
        $this->pendingCheckout($user);
        [$raw, $h] = $this->signedWebhook($this->captured());
        $this->postRaw('/api/subscriptions/webhook/', $raw.' ', $h)->assertStatus(400); // any byte change breaks it
    }

    public function test_empty_webhook_secret_rejects_even_a_correctly_computed_signature(): void
    {
        config()->set('portal.razorpay.webhook_secret', '');
        [$raw, $h] = $this->signedWebhook($this->captured(), '');
        $this->postRaw('/api/subscriptions/webhook/', $raw, $h)->assertStatus(400);
    }

    public function test_captured_activates_without_user_auth_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        [$sub, $pay] = $this->pendingCheckout($user);
        [$raw, $h] = $this->signedWebhook($this->captured());

        $this->postRaw('/api/subscriptions/webhook/', $raw, $h)->assertOk();
        $this->assertSame(Subscription::ACTIVE, $sub->refresh()->status);
        $this->assertSame(Payment::PAID, $pay->refresh()->status);
        $this->assertSame('pay_W1', $pay->razorpay_payment_id);
        $expires = $sub->expires_at->timestamp;

        $this->travel(3)->days();
        $this->postRaw('/api/subscriptions/webhook/', $raw, $h)->assertOk();
        $this->assertSame($expires, $sub->refresh()->expires_at->timestamp);
        $this->assertSame(1, Notification::where('notification_type', 'SUBSCRIPTION_ACTIVATED')->count());
        Mail::assertQueued(SubscriptionNoticeMail::class, 1);
    }

    public function test_order_paid_event_activates(): void
    {
        $user = User::factory()->create();
        [$sub] = $this->pendingCheckout($user);
        $payload = ['event' => 'order.paid', 'payload' => [
            'payment' => ['entity' => ['id' => 'pay_O1', 'order_id' => 'order_TESTORDER1', 'amount' => 49900, 'currency' => 'INR']],
            'order' => ['entity' => ['id' => 'order_TESTORDER1']],
        ]];
        [$raw, $h] = $this->signedWebhook($payload);
        $this->postRaw('/api/subscriptions/webhook/', $raw, $h)->assertOk();
        $this->assertSame(Subscription::ACTIVE, $sub->refresh()->status);
    }

    public function test_verify_then_webhook_never_double_activates(): void
    {
        $user = User::factory()->create();
        [$sub] = $this->pendingCheckout($user);
        $this->actingAsUser($user)->postJson('/api/subscriptions/verify/', ['razorpay_order_id' => 'order_TESTORDER1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $this->sign('order_TESTORDER1', 'pay_1')])->assertOk();
        $expires = $sub->refresh()->expires_at->timestamp;
        [$raw, $h] = $this->signedWebhook($this->captured());
        $this->travel(1)->days();
        $this->postRaw('/api/subscriptions/webhook/', $raw, $h)->assertOk();
        $this->assertSame($expires, $sub->refresh()->expires_at->timestamp);
        $this->assertSame(1, Notification::where('notification_type', 'SUBSCRIPTION_ACTIVATED')->count());
    }

    public function test_amount_or_currency_mismatch_does_not_activate(): void
    {
        $user = User::factory()->create();
        [$sub] = $this->pendingCheckout($user);
        [$raw, $h] = $this->signedWebhook($this->captured(amount: 100));
        $this->postRaw('/api/subscriptions/webhook/', $raw, $h)->assertOk();
        $this->assertSame(Subscription::PENDING, $sub->refresh()->status);
    }

    public function test_failed_event_marks_failed_notifies_once_and_never_downgrades_paid(): void
    {
        $user = User::factory()->create();
        [$sub, $pay] = $this->pendingCheckout($user);
        $p = $this->captured(event: 'payment.failed');
        $p['payload']['payment']['entity']['error_description'] = 'Card declined';
        [$raw, $h] = $this->signedWebhook($p);

        $this->postRaw('/api/subscriptions/webhook/', $raw, $h)->assertOk();
        $this->postRaw('/api/subscriptions/webhook/', $raw, $h)->assertOk();
        $this->assertSame(Payment::FAILED, $pay->refresh()->status);
        $this->assertSame('Card declined', $pay->failure_reason);
        $this->assertSame(Subscription::PENDING, $sub->refresh()->status);
        $this->assertSame(1, Notification::where('notification_type', 'PAYMENT_FAILED')->count());

        // Razorpay allows a retry on the same order: a later capture activates...
        [$raw2, $h2] = $this->signedWebhook($this->captured());
        $this->postRaw('/api/subscriptions/webhook/', $raw2, $h2)->assertOk();
        $this->assertSame(Payment::PAID, $pay->refresh()->status);
        // ...and a late/replayed failure event can no longer downgrade it.
        $this->postRaw('/api/subscriptions/webhook/', $raw, $h)->assertOk();
        $this->assertSame(Payment::PAID, $pay->refresh()->status);
        $this->assertSame(Subscription::ACTIVE, $sub->refresh()->status);
    }

    public function test_unknown_order_unknown_event_and_garbage_body_are_acknowledged(): void
    {
        foreach ([$this->captured('order_NOPE'), ['event' => 'refund.created'], ['x' => 1]] as $payload) {
            [$raw, $h] = $this->signedWebhook($payload);
            $this->postRaw('/api/subscriptions/webhook/', $raw, $h)->assertOk();
        }
        $raw = 'not json';
        $h = ['X-Razorpay-Signature' => hash_hmac('sha256', $raw, $this->webhookSecret)];
        $this->postRaw('/api/subscriptions/webhook/', $raw, $h)->assertOk();
    }
}
