<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Integration-run regressions (Next.js frontend <-> Laravel API contract). */
class CompatAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Sup3r-Secret-pass';

    public function test_register_201_body_carries_is_active_true_like_django(): void
    {
        $r = $this->postJson('/api/auth/register/', ['email' => 'compat@example.com', 'password' => self::PW, 'password2' => self::PW]);
        $r->assertStatus(201)->assertJsonPath('is_active', true)->assertJsonPath('role', 'USER');
    }

    public function test_default_validation_messages_use_drf_wording(): void
    {
        // The register form shows Object.values(body)[0][0] verbatim.
        $r = $this->postJson('/api/auth/register/', ['email' => 'not-an-email', 'password' => self::PW]);
        $r->assertStatus(400)
            ->assertJsonPath('email.0', 'Enter a valid email address.')
            ->assertJsonPath('password2.0', 'This field is required.');

        $this->postJson('/api/auth/login/', [])->assertStatus(400)
            ->assertJsonPath('email.0', 'This field is required.')
            ->assertJsonPath('password.0', 'This field is required.');

        $this->postJson('/api/auth/register/', ['email' => 'ok@example.com', 'password' => str_repeat('a', 129), 'password2' => self::PW])
            ->assertStatus(400)->assertJsonPath('password.0', 'Ensure this field has no more than 128 characters.');
    }

    public function test_refresh_is_not_rotating_and_returns_access_only(): void
    {
        $this->postJson('/api/auth/register/', ['email' => 'r@example.com', 'password' => self::PW, 'password2' => self::PW])->assertStatus(201);
        $login = $this->postJson('/api/auth/login/', ['email' => 'r@example.com', 'password' => self::PW])->assertOk()->json();

        // The Next.js proxy keeps its old refresh cookie and only reads `access` from the reply.
        foreach ([1, 2] as $_) {
            $r = $this->postJson('/api/auth/refresh/', ['refresh' => $login['refresh']])->assertOk();
            $this->assertSame(['access'], array_keys($r->json()));
        }
    }
}
