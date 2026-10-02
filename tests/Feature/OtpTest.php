<?php

namespace Tests\Feature;

use App\Models\PhoneOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PaymentsTestHelpers;
use Tests\TestCase;

class OtpTest extends TestCase
{
    use PaymentsTestHelpers, RefreshDatabase;

    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePayments();
        $this->fakeExternal();
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = $e->message.' '.json_encode($e->context));
    }

    /** Reads the code out of the (faked) WATI request - the only place it ever leaves the server. */
    private function sentCode(): string
    {
        $code = null;
        Http::assertSent(function (Request $r) use (&$code) {
            $code = $r->data()['parameters'][0]['value'] ?? $code;

            return true;
        });

        return (string) $code;
    }

    private function request(User $u, string $phone = '9876543210')
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsUser($u)->postJson('/api/subscriptions/otp/request/', ['phone' => $phone]);
    }

    private function verify(User $u, string $code)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsUser($u)->postJson('/api/subscriptions/otp/verify/', ['code' => $code]);
    }

    public function test_endpoints_require_auth(): void
    {
        $this->postJson('/api/subscriptions/otp/request/', ['phone' => '9876543210'])->assertStatus(401);
        $this->postJson('/api/subscriptions/otp/verify/', ['code' => '123456'])->assertStatus(401);
    }

    public function test_request_stores_only_a_hash_sends_via_wati_and_never_returns_the_code(): void
    {
        $user = User::factory()->create();
        $r = $this->request($user)->assertOk()->assertJsonPath('detail', 'If WhatsApp delivery is configured, a code has been sent.');
        $code = $this->sentCode();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertStringNotContainsString($code, $r->getContent());
        $row = PhoneOtp::firstOrFail();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertStringNotContainsString($code, json_encode($row->getAttributes()));
        $this->assertSame('919876543210', $row->phone);   // bare 10 digits -> WATI-ready
        $this->assertSame(0, $row->attempts);
        $this->assertFalse($row->is_verified);
        $this->assertTrue($row->expires_at->isFuture());
        Http::assertSent(fn (Request $q) => $q->data()['template_name'] === 'otp_verification' && $q->data()['parameters'][0]['name'] === '1' && str_contains($q->url(), 'whatsappNumber=919876543210'));
        $this->assertStringNotContainsString($code, implode("\n", $this->logged));
    }

    public function test_invalid_phone_is_400(): void
    {
        $this->request(User::factory()->create(), '12345')->assertStatus(400)->assertJsonStructure(['phone']);
        $this->actingAsUser(User::factory()->create())->postJson('/api/subscriptions/otp/request/', [])->assertStatus(400)->assertJsonStructure(['phone']);
    }

    public function test_correct_code_verifies_phone_once(): void
    {
        $user = User::factory()->create();
        $this->request($user)->assertOk();
        $code = $this->sentCode();

        $this->verify($user, $code)->assertOk()->assertJsonPath('detail', 'Phone verified.');
        $user->refresh();
        $this->assertSame('919876543210', $user->phone);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertTrue(PhoneOtp::first()->is_verified);

        // single use
        $this->verify($user, $code)->assertStatus(400)->assertJsonPath('detail', 'No pending OTP request found. Request a new code.');
    }

    public function test_wrong_code_counts_attempts_and_locks_out_even_the_right_code(): void
    {
        $user = User::factory()->create();
        $this->request($user)->assertOk();
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->verify($user, $wrong)->assertStatus(400)->assertJsonPath('detail', 'Incorrect code.');
        }
        $this->assertSame(5, PhoneOtp::first()->attempts);
        $this->verify($user, $code)->assertStatus(400)->assertJsonPath('detail', 'Too many incorrect attempts. Request a new code.');
        $this->assertNull($user->refresh()->phone_verified_at);
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->request($user)->assertOk();
        $code = $this->sentCode();
        $this->travel(11)->minutes();
        $this->verify($user, $code)->assertStatus(400)->assertJsonPath('detail', 'This code has expired. Request a new one.');
        $this->assertNull($user->refresh()->phone_verified_at);
    }

    public function test_ttl_and_attempts_follow_config(): void
    {
        config()->set('portal.otp.ttl_minutes', 1);
        config()->set('portal.otp.max_attempts', 1);
        $user = User::factory()->create();
        $this->request($user)->assertOk();
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->verify($user, $wrong)->assertStatus(400)->assertJsonPath('detail', 'Incorrect code.');
        $this->verify($user, $code)->assertStatus(400)->assertJsonPath('detail', 'Too many incorrect attempts. Request a new code.');
    }

    public function test_resend_invalidates_the_previous_code(): void
    {
        $user = User::factory()->create();
        $this->request($user)->assertOk();
        $first = $this->sentCode();
        $this->fakeExternal();
        $this->request($user)->assertOk();
        $second = $this->sentCode();

        $this->assertSame(2, PhoneOtp::count());
        if ($first !== $second) {
            $this->verify($user, $first)->assertStatus(400)->assertJsonPath('detail', 'Incorrect code.');
        }
        $this->verify($user, $second)->assertOk();
    }

    public function test_code_is_bound_to_the_requesting_user(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->request($a)->assertOk();
        $code = $this->sentCode();
        $this->verify($b, $code)->assertStatus(400)->assertJsonPath('detail', 'No pending OTP request found. Request a new code.');
        $this->assertNull($b->refresh()->phone_verified_at);
        $this->assertNull($a->refresh()->phone_verified_at);
    }

    public function test_phone_already_owned_by_another_account_is_refused_not_a_500(): void
    {
        User::factory()->create(['phone' => '919876543210']);
        $user = User::factory()->create();
        $this->request($user)->assertOk();
        $this->verify($user, $this->sentCode())->assertStatus(400)->assertJsonPath('detail', 'This phone number is already linked to another account.');
        $this->assertNull($user->refresh()->phone_verified_at);
    }

    public function test_wati_failure_still_returns_200_without_leaking_anything(): void
    {
        foreach ([fn () => Http::fake(['*' => Http::response('token=super-secret', 500)]), fn () => config()->set('portal.wati.endpoint', '')] as $break) {
            $this->fakeExternal();
            $break();
            $user = User::factory()->create();
            $r = $this->request($user, '9876543211')->assertOk();
            $this->assertSame(['detail' => 'If WhatsApp delivery is configured, a code has been sent.'], $r->json());
            $this->assertSame(1, PhoneOtp::where('user_id', $user->id)->count());
        }
        $this->assertStringNotContainsString('wati-secret-token', implode("\n", $this->logged));
    }

    public function test_throttles_return_429(): void
    {
        config()->set('portal.throttle.otp_request_burst.rate', '2/minute');
        config()->set('portal.throttle.otp_verify_burst.rate', '3/minute');
        $u = User::factory()->create();
        $other = User::factory()->create();
        $this->request($u)->assertOk();
        $this->request($u)->assertOk();
        $this->request($u)->assertStatus(429)->assertJsonStructure(['detail']);
        $this->request($other)->assertOk(); // per user, not per IP

        for ($i = 0; $i < 3; $i++) {
            $this->assertNotSame(429, $this->verify($u, '000000')->status());
        }
        $this->verify($u, '000000')->assertStatus(429);
    }

    public function test_hourly_caps_apply(): void
    {
        config()->set('portal.throttle.otp_request.rate', '1/hour');
        config()->set('portal.throttle.otp_verify.rate', '1/hour');
        $u = User::factory()->create();
        $this->request($u)->assertOk();
        $this->request($u)->assertStatus(429);
        $this->verify($u, '000000');
        $this->verify($u, '000000')->assertStatus(429);
    }

    public function test_admin_otp_list_never_exposes_code_or_hash(): void
    {
        $user = User::factory()->create();
        $this->request($user)->assertOk();
        $code = $this->sentCode();
        $hash = PhoneOtp::first()->code_hash;
        $this->app['auth']->forgetGuards();
        $r = $this->actingAsUser(User::factory()->admin()->create())->getJson('/api/subscriptions/admin/otps/')->assertOk();
        $this->assertStringNotContainsString($hash, $r->getContent());
        $this->assertStringNotContainsString($code, $r->getContent());
    }
}
