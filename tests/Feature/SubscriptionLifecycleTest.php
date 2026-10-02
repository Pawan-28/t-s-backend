<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\Role;
use App\Http\Resources\ArticleResource;
use App\Mail\SubscriptionNoticeMail;
use App\Models\Article;
use App\Models\Notification;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\TokenService;
use Carbon\Carbon;
use Database\Factories\ArticleFactory;
use Database\Factories\SubscriptionFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\PaymentsTestHelpers;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use PaymentsTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePayments();
        $this->fakeExternal();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_expire_marks_overdue_expired_once_demotes_role_and_notifies_once(): void
    {
        $user = User::factory()->subscriber()->create();
        $sub = SubscriptionFactory::new()->expiresAt(now()->addHour())->create(['user_id' => $user->id, 'contact_email' => 'c@example.com', 'contact_phone' => '919876543210']);
        $fresh = SubscriptionFactory::new()->active(10)->create();

        $this->travel(2)->hours();
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->artisan('subscriptions:expire')->assertSuccessful(); // second run: no duplicate

        $sub->refresh();
        $this->assertSame(Subscription::EXPIRED, $sub->status);
        $this->assertNotNull($sub->expired_notified_at);
        $this->assertSame(Subscription::ACTIVE, $fresh->refresh()->status);
        $this->assertSame(Role::USER, $user->refresh()->role);
        $this->assertSame(1, Notification::where(['recipient_id' => $user->id, 'notification_type' => 'SUBSCRIPTION_EXPIRED'])->count());
        $this->drainQueue();
        Mail::assertQueued(SubscriptionNoticeMail::class, fn ($m) => $m->hasTo('c@example.com') && $m->noticeSubject === 'Your subscription has expired');
        Mail::assertQueued(SubscriptionNoticeMail::class, 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->data()['template_name'] === 'sub_expired' && $r->data()['parameters'] === []);
    }

    public function test_expiry_keeps_subscriber_role_when_another_subscription_is_still_active(): void
    {
        $user = User::factory()->subscriber()->create();
        SubscriptionFactory::new()->expiresAt(now()->subMinute())->create(['user_id' => $user->id]);
        SubscriptionFactory::new()->active(20)->create(['user_id' => $user->id]);
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->assertSame(Role::SUBSCRIBER, $user->refresh()->role);
    }

    public function test_expiry_never_touches_pending_cancelled_or_admin_role(): void
    {
        $admin = User::factory()->admin()->create();
        SubscriptionFactory::new()->expiresAt(now()->subMinute())->create(['user_id' => $admin->id]);
        $pending = SubscriptionFactory::new()->create();
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->assertSame(Role::ADMIN, $admin->refresh()->role);
        $this->assertSame(Subscription::PENDING, $pending->refresh()->status);
    }

    public function test_reminder_is_sent_once_inside_window_only(): void
    {
        config()->set('portal.subscriptions.expiry_reminder_days', 3);
        $soon = SubscriptionFactory::new()->expiresAt(now()->addDays(2))->create(['contact_email' => 's@example.com', 'contact_phone' => '919876543210']);
        $later = SubscriptionFactory::new()->expiresAt(now()->addDays(10))->create();
        $past = SubscriptionFactory::new()->expiresAt(now()->subHour())->create();

        $this->artisan('subscriptions:remind-expiring')->assertSuccessful();
        $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

        $this->assertNotNull($soon->refresh()->expiring_notified_at);
        $this->assertNull($later->refresh()->expiring_notified_at);
        $this->assertNull($past->refresh()->expiring_notified_at);
        $this->assertSame(1, Notification::where('notification_type', 'SUBSCRIPTION_EXPIRING')->count());
        $this->drainQueue();
        Mail::assertQueued(SubscriptionNoticeMail::class, 1);
        Http::assertSent(fn ($r) => $r->data()['template_name'] === 'sub_expiring' && $r->data()['parameters'][0]['name'] === 'expires_at');

        // window follows config
        config()->set('portal.subscriptions.expiry_reminder_days', 30);
        $this->artisan('subscriptions:remind-expiring')->assertSuccessful();
        $this->assertNotNull($later->refresh()->expiring_notified_at);
    }

    public function test_lifecycle_uses_frozen_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00'));
        $sub = SubscriptionFactory::new()->expiresAt(Carbon::parse('2026-01-01 12:00:00'))->create();
        $this->artisan('subscriptions:expire');
        $this->assertSame(Subscription::ACTIVE, $sub->refresh()->status);
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:01'));
        $this->artisan('subscriptions:expire');
        $this->assertSame(Subscription::EXPIRED, $sub->refresh()->status);
    }

    public function test_lifecycle_commands_are_scheduled(): void
    {
        $cmds = collect(app(Schedule::class)->events())->pluck('command')->implode('|');
        $this->assertStringContainsString('subscriptions:expire', $cmds);
        $this->assertStringContainsString('subscriptions:remind-expiring', $cmds);
    }

    public function test_notification_failure_channels_never_break_the_job(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('boom', 500)]);
        $sub = SubscriptionFactory::new()->expiresAt(now()->subMinute())->create(['contact_phone' => '919876543210']);
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->assertSame(Subscription::EXPIRED, $sub->refresh()->status);
    }

    public function test_notify_published_command_notifies_active_subscribers_once_and_skips_old_articles(): void
    {
        $sub = SubscriptionFactory::new()->active()->create(['contact_email' => 'p@example.com', 'contact_phone' => '919876543210']);
        $gated = ArticleFactory::new()->published()->access(AccessLevel::SUBSCRIBER_ONLY)->create();
        $public = ArticleFactory::new()->published()->create();
        $old = ArticleFactory::new()->published()->access(AccessLevel::SUBSCRIBER_ONLY)->create(['published_at' => now()->subDays(5)]);

        $this->artisan('subscriptions:notify-published')->assertSuccessful();
        $this->artisan('subscriptions:notify-published')->assertSuccessful();
        $this->drainQueue();

        Mail::assertQueued(SubscriptionNoticeMail::class, 1);
        Http::assertSent(fn ($r) => $r->data()['template_name'] === 'art_pub' && $r->data()['parameters'][0]['value'] === $gated->title);
        $this->assertNotNull($gated->refresh()->subscribers_notified_at);
        $this->assertNotNull($old->refresh()->subscribers_notified_at);
        $this->assertNull($public->refresh()->subscribers_notified_at);
    }

    // ------------------------------------------------- entitlement consistency

    public function test_entitlement_flips_on_activation_and_on_expiry(): void
    {
        $user = User::factory()->create();
        $ent = fn () => tap(app(EntitlementService::class))->flush()->hasActiveSubscription($user);
        $this->assertFalse($ent());

        [$sub] = $this->pendingCheckout($user);
        $this->assertFalse($ent()); // PENDING grants nothing

        $this->actingAsUser($user)->postJson('/api/subscriptions/verify/', ['razorpay_order_id' => 'order_TESTORDER1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $this->sign('order_TESTORDER1', 'pay_1')])->assertOk();
        $this->assertTrue($ent());

        $this->travel(31)->days();
        $this->assertFalse($ent()); // expires_at passed, even before the expiry job ran
        $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->assertFalse($ent());
    }

    public function test_restricted_article_body_is_gated_through_the_api_resource_and_flips_with_the_subscription(): void
    {
        $article = ArticleFactory::new()->published()->access(AccessLevel::SUBSCRIBER_ONLY)->create(['content' => '<p>SECRET-BODY</p>', 'faqs' => [['q' => 'a', 'a' => 'b']]]);
        Route::middleware('api')->prefix('api')->get('_t4/article/{id}', fn (Request $r, $id) => new ArticleResource(Article::query()->findOrFail($id)));

        $user = User::factory()->create();
        $token = app(TokenService::class)->issueAccess($user);
        $get = function (?string $token) use ($article) {
            $this->app['auth']->forgetGuards();
            app(EntitlementService::class)->flush(); // per-request memo (singleton lives across requests in a test)
            $h = $token ? ['Authorization' => 'Bearer '.$token] : [];

            return $this->withHeaders($h)->getJson('/api/_t4/article/'.$article->id);
        };

        $r = $get(null)->assertOk();
        $this->assertNull($r->json('content'));
        $this->assertStringNotContainsString('SECRET-BODY', $r->getContent());
        $this->assertNull($get($token)->json('content')); // logged in, not subscribed

        [$sub] = $this->pendingCheckout($user);
        $this->assertNull($get($token)->json('content')); // PENDING still locked

        $this->actingAsUser($user)->postJson('/api/subscriptions/verify/', ['razorpay_order_id' => 'order_TESTORDER1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $this->sign('order_TESTORDER1', 'pay_1')])->assertOk();
        $this->assertSame('<p>SECRET-BODY</p>', $get($token)->json('content'));
        $this->assertFalse($get($token)->json('is_locked'));

        $this->travel(40)->days();
        $this->artisan('subscriptions:expire');
        $r = $get($token);
        $this->assertNull($r->json('content'));
        $this->assertSame([], $r->json('faqs'));
        $this->assertTrue($r->json('is_locked'));
    }
}
