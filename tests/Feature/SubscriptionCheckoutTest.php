<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\SubscriptionNoticeMail;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PhoneOtp;
use App\Models\Subscription;
use App\Models\User;
use Database\Factories\PaymentFactory;
use Database\Factories\SubscriptionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\PaymentsTestHelpers;
use Tests\TestCase;

class SubscriptionCheckoutTest extends TestCase
{
    use PaymentsTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePayments();
        $this->fakeExternal();
        Mail::fake();
    }

    private function payload(string $slug, array $over = []): array
    {
        return array_merge(['plan_slug' => $slug, 'email' => 'Buyer@Example.com', 'phone' => '9876543210'], $over);
    }

    public function test_plans_are_public_paginated_active_only_and_ordered_by_price(): void
    {
        $this->plan(['name' => 'Yearly', 'price_amount' => '4999.00', 'duration_days' => 365]);
        $this->plan(['name' => 'Monthly', 'price_amount' => '499.00']);
        $this->plan(['name' => 'Hidden', 'is_active' => false]);

        $r = $this->getJson('/api/subscriptions/plans/')->assertOk();
        $r->assertJsonPath('count', 2)->assertJsonPath('results.0.name', 'Monthly')->assertJsonPath('results.0.price_amount', '499.00');
        $r->assertJsonStructure(['count', 'next', 'previous', 'results' => [['id', 'name', 'slug', 'description', 'price_amount', 'price_currency', 'duration_days']]]);
        $this->assertArrayNotHasKey('is_active', $r->json('results.0'));
    }

    public function test_checkout_requires_authentication(): void
    {
        $this->postJson('/api/subscriptions/checkout/', $this->payload('x'))->assertStatus(401)
            ->assertJsonPath('detail', 'Authentication credentials were not provided.');
    }

    public function test_checkout_requires_contact_email_and_phone_and_creates_nothing(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $this->actingAsUser($user);

        $this->postJson('/api/subscriptions/checkout/', ['plan_slug' => $plan->slug])->assertStatus(400)->assertJsonStructure(['email', 'phone']);
        $this->postJson('/api/subscriptions/checkout/', $this->payload($plan->slug, ['email' => 'not-an-email']))->assertStatus(400)->assertJsonStructure(['email']);
        $this->postJson('/api/subscriptions/checkout/', $this->payload($plan->slug, ['phone' => '12345']))->assertStatus(400)->assertJsonStructure(['phone']);
        $this->postJson('/api/subscriptions/checkout/', $this->payload($plan->slug, ['email' => '', 'phone' => '']))->assertStatus(400)->assertJsonStructure(['email', 'phone']);

        $this->assertSame(0, Subscription::count());
        Http::assertNothingSent();
    }

    public function test_checkout_creates_pending_subscription_with_contact_snapshot_and_server_side_price(): void
    {
        $user = User::factory()->create(['email' => 'login@example.com']);
        $plan = $this->plan(['price_amount' => '499.00']);
        $this->actingAsUser($user);

        // Client-supplied amount/price is ignored.
        $r = $this->postJson('/api/subscriptions/checkout/', $this->payload($plan->slug, ['amount' => 1, 'price_amount' => '1.00']))->assertStatus(201);
        $r->assertJsonPath('order_id', 'order_TESTORDER1')->assertJsonPath('amount', 49900)->assertJsonPath('currency', 'INR')
            ->assertJsonPath('key_id', 'rzp_test_KEYID')->assertJsonPath('prefill.email', 'buyer@example.com')->assertJsonPath('prefill.contact', '919876543210');
        $this->assertStringNotContainsString($this->keySecret, $r->getContent());

        Http::assertSent(function (HttpRequest $req) {
            return str_ends_with($req->url(), '/v1/orders')
                && $req->data()['amount'] === 49900 && $req->data()['currency'] === 'INR'
                && $req->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_KEYID:'.$this->keySecret));
        });

        $sub = Subscription::firstOrFail();
        $this->assertSame(Subscription::PENDING, $sub->status);
        $this->assertSame('buyer@example.com', $sub->contact_email);
        $this->assertSame('919876543210', $sub->contact_phone);
        $payment = Payment::firstOrFail();
        $this->assertSame('499.00', (string) $payment->amount);
        $this->assertSame(Payment::CREATED, $payment->status);

        $user->refresh();
        $this->assertSame('login@example.com', $user->email);      // login email untouched
        $this->assertSame('919876543210', $user->phone);           // blank phone filled...
        $this->assertNull($user->phone_verified_at);               // ...but not verified
        $this->assertSame(Role::USER, $user->role);                // not a subscriber until paid
    }

    public function test_existing_account_phone_is_not_overwritten(): void
    {
        $user = User::factory()->create(['phone' => '919000000001']);
        $plan = $this->plan();
        $this->actingAsUser($user)->postJson('/api/subscriptions/checkout/', $this->payload($plan->slug))->assertStatus(201);
        $this->assertSame('919000000001', $user->refresh()->phone);
    }

    public function test_unknown_or_inactive_plan_is_400_and_provider_failure_leaves_no_pending_row(): void
    {
        $user = User::factory()->create();
        $inactive = $this->plan(['is_active' => false]);
        $this->actingAsUser($user);
        $this->postJson('/api/subscriptions/checkout/', $this->payload('nope'))->assertStatus(400)->assertJsonPath('detail', 'No active subscription plan with that slug.');
        $this->postJson('/api/subscriptions/checkout/', $this->payload($inactive->slug))->assertStatus(400);

        $active = $this->plan();
        $this->fakeExternal('x', 500, ['error' => ['description' => 'secret detail']]);
        $r = $this->postJson('/api/subscriptions/checkout/', $this->payload($active->slug))->assertStatus(400);
        $this->assertStringContainsString('Could not start checkout', $r->json('detail'));
        $this->assertStringNotContainsString('secret detail', $r->getContent());
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_checkout_is_throttled(): void
    {
        config()->set('portal.throttle.checkout.rate', '2/minute');
        $user = User::factory()->create();
        $plan = $this->plan();
        $this->actingAsUser($user);
        $this->assertSame(201, $this->postJson('/api/subscriptions/checkout/', $this->payload($plan->slug))->status());
        $this->fakeExternal('order_TWO');
        $this->assertSame(201, $this->postJson('/api/subscriptions/checkout/', $this->payload($plan->slug))->status());
        $this->postJson('/api/subscriptions/checkout/', $this->payload($plan->slug))->assertStatus(429);
    }

    public function test_verify_with_valid_signature_activates_subscription_and_promotes_role(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(['duration_days' => 30]);
        [$sub, $pay] = $this->pendingCheckout($user, $plan);
        $this->travelTo(now()->startOfSecond());
        $this->actingAsUser($user);

        $r = $this->postJson('/api/subscriptions/verify/', [
            'razorpay_order_id' => 'order_TESTORDER1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $this->sign('order_TESTORDER1', 'pay_1'),
        ])->assertOk();
        $r->assertJsonPath('status', 'ACTIVE')->assertJsonPath('is_active_now', true)->assertJsonPath('plan.slug', $plan->slug);
        $this->assertArrayNotHasKey('razorpay_signature', $r->json());

        $sub->refresh();
        $this->assertSame(Subscription::ACTIVE, $sub->status);
        // (absolute instants are not compared: see the DB session timezone note in the report)
        $this->assertSame(30 * 86400, $sub->expires_at->timestamp - $sub->started_at->timestamp);
        $this->assertSame(Payment::PAID, $pay->refresh()->status);
        $this->assertSame('pay_1', $pay->razorpay_payment_id);
        $this->assertSame(Role::SUBSCRIBER, $user->refresh()->role);
        $this->assertDatabaseHas('notifications', ['recipient_id' => $user->id, 'notification_type' => 'SUBSCRIPTION_ACTIVATED']);
        $this->drainQueue();
        Mail::assertQueued(SubscriptionNoticeMail::class, fn ($m) => $m->hasTo('buyer@example.com') && $m->noticeSubject === 'Your subscription is active');
        Http::assertSent(fn (HttpRequest $q) => str_contains($q->url(), 'wati.test/api/v1/sendTemplateMessage')
            && $q->data()['template_name'] === 'sub_activated' && $q->data()['parameters'] === [['name' => 'plan_name', 'value' => $plan->name]]);
    }

    public function test_verify_replay_is_idempotent_and_never_extends(): void
    {
        $user = User::factory()->create();
        $this->pendingCheckout($user);
        $this->actingAsUser($user);
        $body = ['razorpay_order_id' => 'order_TESTORDER1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $this->sign('order_TESTORDER1', 'pay_1')];

        $this->postJson('/api/subscriptions/verify/', $body)->assertOk();
        $sub = Subscription::firstOrFail();
        $expires = $sub->expires_at->copy();
        $this->travel(2)->days();
        $this->postJson('/api/subscriptions/verify/', $body)->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->assertSame($expires->timestamp, $sub->refresh()->expires_at->timestamp);
        $this->assertSame(1, Notification::where('notification_type', 'SUBSCRIPTION_ACTIVATED')->count());
    }

    public function test_verify_with_invalid_signature_fails_payment_notifies_once_and_does_not_activate(): void
    {
        $user = User::factory()->create(['email' => 'acct@example.com']);
        [$sub, $pay] = $this->pendingCheckout($user);
        $this->actingAsUser($user);
        $body = ['razorpay_order_id' => 'order_TESTORDER1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => str_repeat('a', 64)];

        $this->postJson('/api/subscriptions/verify/', $body)->assertStatus(400)->assertJsonPath('detail', 'Payment signature verification failed.');
        $this->postJson('/api/subscriptions/verify/', $body)->assertStatus(400);

        $this->assertSame(Subscription::PENDING, $sub->refresh()->status);
        $this->assertSame(Payment::FAILED, $pay->refresh()->status);
        $this->assertSame(Role::USER, $user->refresh()->role);
        $this->assertSame(1, Notification::where(['recipient_id' => $user->id, 'notification_type' => 'PAYMENT_FAILED'])->count());
        Mail::assertQueued(SubscriptionNoticeMail::class, 1);
        Mail::assertQueued(SubscriptionNoticeMail::class, fn ($m) => $m->hasTo('acct@example.com')); // account address, never the checkout contact
    }

    public function test_valid_signature_after_failure_still_activates_and_paid_is_never_downgraded_by_bad_signature(): void
    {
        $user = User::factory()->create();
        [$sub, $pay] = $this->pendingCheckout($user);
        $this->actingAsUser($user);
        $bad = ['razorpay_order_id' => 'order_TESTORDER1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => 'x'];
        $good = ['razorpay_order_id' => 'order_TESTORDER1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $this->sign('order_TESTORDER1', 'pay_1')];

        $this->postJson('/api/subscriptions/verify/', $bad)->assertStatus(400);
        $this->postJson('/api/subscriptions/verify/', $good)->assertOk();
        $this->postJson('/api/subscriptions/verify/', $bad)->assertStatus(400);
        $this->assertSame(Payment::PAID, $pay->refresh()->status);
        $this->assertSame(Subscription::ACTIVE, $sub->refresh()->status);
    }

    public function test_signature_is_bound_to_order_and_payment_and_secret(): void
    {
        $user = User::factory()->create();
        $this->pendingCheckout($user);
        $this->actingAsUser($user);
        foreach ([$this->sign('order_TESTORDER1', 'pay_OTHER'), $this->sign('order_TESTORDER1', 'pay_1', 'wrong-secret'), $this->sign('order_X', 'pay_1')] as $sig) {
            $this->postJson('/api/subscriptions/verify/', ['razorpay_order_id' => 'order_TESTORDER1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $sig])->assertStatus(400);
        }
        $this->assertSame(0, Subscription::where('status', 'ACTIVE')->count());
    }

    public function test_empty_key_secret_fails_closed(): void
    {
        config()->set('portal.razorpay.key_secret', '');
        $user = User::factory()->create();
        $this->pendingCheckout($user);
        $this->actingAsUser($user);
        $this->postJson('/api/subscriptions/verify/', ['razorpay_order_id' => 'order_TESTORDER1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => hash_hmac('sha256', 'order_TESTORDER1|pay_1', '')])->assertStatus(400);
    }

    public function test_verify_validation_and_unknown_order(): void
    {
        $user = User::factory()->create();
        $this->actingAsUser($user);
        $this->postJson('/api/subscriptions/verify/', [])->assertStatus(400)->assertJsonStructure(['razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature']);
        $this->postJson('/api/subscriptions/verify/', ['razorpay_order_id' => 'nope', 'razorpay_payment_id' => 'p', 'razorpay_signature' => 's'])
            ->assertStatus(400)->assertJsonPath('detail', 'No matching payment for that order id.');
    }

    public function test_verify_cannot_activate_another_users_payment(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        [$sub, $pay] = $this->pendingCheckout($owner);
        $this->actingAsUser($attacker);
        // Even with a perfectly valid signature the payment belongs to someone else.
        $this->postJson('/api/subscriptions/verify/', ['razorpay_order_id' => 'order_TESTORDER1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $this->sign('order_TESTORDER1', 'pay_1')])
            ->assertStatus(400)->assertJsonPath('detail', 'No matching payment for that order id.');
        $this->assertSame(Subscription::PENDING, $sub->refresh()->status);
        $this->assertSame(Payment::CREATED, $pay->refresh()->status);
    }

    public function test_activation_never_downgrades_admin_or_reporter(): void
    {
        foreach (['admin', 'reporter'] as $i => $state) {
            $user = User::factory()->{$state}()->create();
            $this->pendingCheckout($user, null, 'order_R'.$i);
            $this->app['auth']->forgetGuards();
            $this->actingAsUser($user)->postJson('/api/subscriptions/verify/', ['razorpay_order_id' => 'order_R'.$i, 'razorpay_payment_id' => 'pay_'.$i, 'razorpay_signature' => $this->sign('order_R'.$i, 'pay_'.$i)])->assertOk();
            $this->assertSame($state === 'admin' ? Role::ADMIN : Role::REPORTER, $user->refresh()->role);
        }
    }

    public function test_verify_is_throttled(): void
    {
        config()->set('portal.throttle.verify_payment.rate', '2/minute');
        $this->actingAsUser(User::factory()->create());
        $body = ['razorpay_order_id' => 'nope', 'razorpay_payment_id' => 'p', 'razorpay_signature' => 's'];
        $this->postJson('/api/subscriptions/verify/', $body)->assertStatus(400);
        $this->postJson('/api/subscriptions/verify/', $body)->assertStatus(400);
        $this->postJson('/api/subscriptions/verify/', $body)->assertStatus(429);
    }

    public function test_me_returns_latest_subscription_or_null_and_is_scoped_to_caller(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->actingAsUser($a);
        $this->assertSame('null', trim($this->getJson('/api/subscriptions/me/')->getContent()));

        SubscriptionFactory::new()->active()->create(['user_id' => $b->id]);
        $mine = SubscriptionFactory::new()->active()->create(['user_id' => $a->id]);
        $this->getJson('/api/subscriptions/me/')->assertOk()->assertJsonPath('id', $mine->id)->assertJsonPath('is_active_now', true)
            ->assertJsonStructure(['id', 'plan' => ['id', 'slug'], 'status', 'started_at', 'expires_at', 'is_active_now', 'created_at']);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/logout/'); // no-op safety; token is fake
    }

    public function test_me_requires_auth(): void
    {
        $this->getJson('/api/subscriptions/me/')->assertStatus(401);
    }

    // ------------------------------------------------------------------ admin

    public function test_admin_endpoints_are_admin_only(): void
    {
        foreach (['/api/subscriptions/admin/list/', '/api/subscriptions/admin/payments/', '/api/subscriptions/admin/otps/', '/api/subscriptions/admin/plans/', '/api/subscriptions/active-subscribers/'] as $url) {
            $this->app['auth']->forgetGuards();
            $this->getJson($url)->assertStatus(401);
            $this->actingAsUser(User::factory()->create())->getJson($url)->assertStatus(403);
            $this->app['auth']->forgetGuards();
            $this->actingAsUser(User::factory()->reporter()->create())->getJson($url)->assertStatus(403);
            $this->app['auth']->forgetGuards();
            $this->actingAsUser(User::factory()->admin()->create())->getJson($url)->assertOk();
        }
    }

    public function test_admin_plan_crud(): void
    {
        $this->actingAsUser(User::factory()->admin()->create());
        $r = $this->postJson('/api/subscriptions/admin/plans/', ['name' => 'Gold Plan', 'price_amount' => '999', 'duration_days' => 90])->assertStatus(201);
        $r->assertJsonPath('slug', 'gold-plan')->assertJsonPath('price_amount', '999.00')->assertJsonPath('price_currency', 'INR')->assertJsonPath('is_active', true);
        $id = $r->json('id');
        $this->postJson('/api/subscriptions/admin/plans/', ['name' => 'Gold Plan', 'price_amount' => '1', 'duration_days' => 1])->assertStatus(201)->assertJsonPath('slug', 'gold-plan-2');

        $this->patchJson("/api/subscriptions/admin/plans/$id/", ['price_amount' => '1099.50', 'is_active' => false])->assertOk()
            ->assertJsonPath('price_amount', '1099.50')->assertJsonPath('is_active', false)->assertJsonPath('slug', 'gold-plan');
        $this->putJson("/api/subscriptions/admin/plans/$id/", ['name' => 'Gold', 'price_amount' => '5', 'duration_days' => 10])->assertOk()->assertJsonPath('slug', 'gold-plan');
        $this->getJson("/api/subscriptions/admin/plans/$id/")->assertOk()->assertJsonPath('name', 'Gold');
        $this->getJson('/api/subscriptions/admin/plans/')->assertOk()->assertJsonPath('count', 2);

        $this->postJson('/api/subscriptions/admin/plans/', ['name' => 'Bad', 'price_amount' => '0', 'duration_days' => 0])->assertStatus(400)->assertJsonStructure(['price_amount', 'duration_days']);
        $this->postJson('/api/subscriptions/admin/plans/', [])->assertStatus(400)->assertJsonStructure(['name', 'price_amount', 'duration_days']);
        $this->postJson('/api/subscriptions/admin/plans/', ['name' => 'X', 'price_amount' => '1.999', 'duration_days' => 1])->assertStatus(400)->assertJsonStructure(['price_amount']);

        // plan with subscriptions cannot be deleted; empty plan can
        SubscriptionFactory::new()->create(['plan_id' => $id]);
        $this->deleteJson("/api/subscriptions/admin/plans/$id/")->assertStatus(409);
        $empty = $this->plan();
        $this->deleteJson("/api/subscriptions/admin/plans/{$empty->id}/")->assertStatus(204);
        $this->getJson('/api/subscriptions/admin/plans/99999/')->assertStatus(404)->assertJsonPath('detail', 'Not found.');
    }

    public function test_admin_lists_filters_stats_and_secret_free_payloads(): void
    {
        $u1 = User::factory()->create(['email' => 'one@example.com']);
        $u2 = User::factory()->create(['email' => 'two@example.com']);
        $s1 = SubscriptionFactory::new()->active()->create(['user_id' => $u1->id]);
        SubscriptionFactory::new()->active()->create(['user_id' => $u1->id]); // same user: counted once
        SubscriptionFactory::new()->expiresAt(now()->subDay())->create(['user_id' => $u2->id]); // ACTIVE but past
        PaymentFactory::new()->forSubscription($s1)->create(['status' => Payment::PAID, 'razorpay_signature' => 'SECRETSIG']);
        PhoneOtp::create(['user_id' => $u1->id, 'phone' => '919876543210', 'code_hash' => 'HASHVALUE', 'expires_at' => now()->addMinute()]);

        $this->actingAsUser(User::factory()->admin()->create());
        $this->getJson('/api/subscriptions/active-subscribers/')->assertOk()->assertJsonPath('active_subscribers', 1);
        $this->getJson('/api/subscriptions/admin/list/')->assertOk()->assertJsonPath('count', 3)->assertJsonPath('results.0.user_email', fn ($e) => is_string($e));
        $this->getJson('/api/subscriptions/admin/list/?user='.$u2->id)->assertJsonPath('count', 1);
        $this->getJson('/api/subscriptions/admin/list/?status=EXPIRED')->assertJsonPath('count', 0);
        $this->getJson('/api/subscriptions/admin/list/?status=BOGUS')->assertStatus(400)->assertJsonStructure(['status']);
        $p = $this->getJson('/api/subscriptions/admin/payments/?status=PAID')->assertOk()->assertJsonPath('count', 1);
        $this->assertStringNotContainsString('SECRETSIG', $p->getContent());
        $this->assertArrayNotHasKey('razorpay_signature', $p->json('results.0'));
        $o = $this->getJson('/api/subscriptions/admin/otps/?is_verified=false')->assertOk()->assertJsonPath('count', 1);
        $this->assertStringNotContainsString('HASHVALUE', $o->getContent());
        $this->assertArrayNotHasKey('code_hash', $o->json('results.0'));
        $this->getJson('/api/subscriptions/admin/otps/?is_verified=true')->assertJsonPath('count', 0);
    }
}
