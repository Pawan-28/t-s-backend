<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Sup3r-Secret-pass';

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushRedis();
    }

    public function test_register_creates_only_plain_user_even_if_role_supplied(): void
    {
        $r = $this->postJson('/api/auth/register/', [
            'email' => 'New@Example.COM', 'password' => self::PW, 'password2' => self::PW,
            'first_name' => 'N', 'last_name' => 'U', 'role' => 'ADMIN',
        ]);
        $r->assertStatus(201)->assertJsonPath('role', 'USER')->assertJsonPath('email', 'New@example.com');
        $this->assertSame(Role::USER, User::first()->role);
    }

    public function test_register_rejects_duplicate_email_differing_by_case_and_mismatched_password(): void
    {
        User::factory()->create(['email' => 'a@example.com']);
        $this->postJson('/api/auth/register/', ['email' => 'A@EXAMPLE.com', 'password' => self::PW, 'password2' => 'x'])
            ->assertStatus(400)->assertJsonStructure(['email', 'password2']);
    }

    public function test_login_returns_access_refresh_user_and_trailing_slash_optional(): void
    {
        $u = User::factory()->create(['email' => 'l@example.com']);
        foreach (['/api/auth/login/', '/api/auth/login'] as $url) {
            $this->postJson($url, ['email' => 'L@example.com', 'password' => self::PW])
                ->assertOk()->assertJsonStructure(['access', 'refresh', 'user' => ['id', 'email', 'role']]);
        }
        $this->postJson('/api/auth/login/', ['email' => $u->email, 'password' => 'bad'])
            ->assertStatus(401)->assertJsonStructure(['detail']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $u = User::factory()->create(['is_active' => false]);
        $this->postJson('/api/auth/login/', ['email' => $u->email, 'password' => self::PW])->assertStatus(401);
    }

    public function test_refresh_does_not_rotate_and_can_be_reused(): void
    {
        $u = User::factory()->create();
        $login = $this->postJson('/api/auth/login/', ['email' => $u->email, 'password' => self::PW])->json();
        $before = DB::table('personal_access_tokens')->count();
        for ($i = 0; $i < 3; $i++) {
            $r = $this->postJson('/api/auth/refresh/', ['refresh' => $login['refresh']])->assertOk();
            $this->assertArrayHasKey('access', $r->json());
            $this->assertArrayNotHasKey('refresh', $r->json());
        }
        // refresh token row untouched: only access tokens were added
        $this->assertSame(3, DB::table('personal_access_tokens')->count() - $before);
        $this->assertNotNull(DB::table('personal_access_tokens')->where('name', 'refresh')->first());
    }

    public function test_refresh_rejects_access_token_and_garbage(): void
    {
        $u = User::factory()->create();
        $login = $this->postJson('/api/auth/login/', ['email' => $u->email, 'password' => self::PW])->json();
        $this->postJson('/api/auth/refresh/', ['refresh' => $login['access']])->assertStatus(401);
        $this->postJson('/api/auth/refresh/', ['refresh' => 'nope'])->assertStatus(401);
    }

    public function test_me_requires_access_token_not_refresh_token(): void
    {
        $u = User::factory()->create();
        $login = $this->postJson('/api/auth/login/', ['email' => $u->email, 'password' => self::PW])->json();
        $this->getJson('/api/auth/me/')->assertStatus(401)->assertJson(['detail' => 'Authentication credentials were not provided.']);
        $this->getJson('/api/auth/me/', ['Authorization' => 'Bearer '.$login['access']])->assertOk()->assertJsonPath('email', $u->email);
        $this->app['auth']->forgetGuards(); // the guard memoises the user within one test process
        $this->getJson('/api/auth/me/', ['Authorization' => 'Bearer '.$login['refresh']])->assertStatus(401);
    }

    public function test_logout_revokes_refresh_and_access(): void
    {
        $u = User::factory()->create();
        $login = $this->postJson('/api/auth/login/', ['email' => $u->email, 'password' => self::PW])->json();
        $h = ['Authorization' => 'Bearer '.$login['access']];
        $this->postJson('/api/auth/logout/', ['refresh' => $login['refresh']], $h)->assertStatus(205);
        $this->postJson('/api/auth/refresh/', ['refresh' => $login['refresh']])->assertStatus(401);
    }

    public function test_django_pbkdf2_hash_verifies_and_is_upgraded_to_bcrypt(): void
    {
        $salt = 'saltsalt';
        $hash = base64_encode(hash_pbkdf2('sha256', 'Legacy-pass-99', $salt, 1000, 32, true));
        $u = User::factory()->create(['email' => 'old@example.com', 'password' => "pbkdf2_sha256\$1000\${$salt}\${$hash}"]);
        $this->postJson('/api/auth/login/', ['email' => 'old@example.com', 'password' => 'Legacy-pass-99'])->assertOk();
        $this->assertStringStartsWith('$2y$', $u->fresh()->password);
        $this->postJson('/api/auth/login/', ['email' => 'old@example.com', 'password' => 'Legacy-pass-99'])->assertOk();
    }

    public function test_admin_endpoints_rbac(): void
    {
        $this->getJson('/api/accounts/users/')->assertStatus(401);
        $this->actingAsUser(User::factory()->create())->getJson('/api/accounts/users/')->assertStatus(403);
        $this->actingAsUser(User::factory()->reporter()->create())->getJson('/api/accounts/users/')->assertStatus(403);
        $this->actingAsUser(User::factory()->admin()->create())->getJson('/api/accounts/users/')
            ->assertOk()->assertJsonStructure(['count', 'next', 'previous', 'results']);
    }

    public function test_unknown_route_is_json_404(): void
    {
        $this->get('/api/nothing-here/')->assertStatus(404)->assertJson(['detail' => 'Not found.']);
    }
}
