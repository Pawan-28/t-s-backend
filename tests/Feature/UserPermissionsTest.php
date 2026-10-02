<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus as S;
use App\Models\Article;
use App\Models\User;
use App\Support\Permissions;
use Database\Factories\IndustryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ArticleTestHelpers;
use Tests\Concerns\WorkflowFixtures;
use Tests\TestCase;

/** Admin user management (create / edit / role / password) and the per-user feature permissions. */
class UserPermissionsTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase, WorkflowFixtures;

    private const PW = 'Sup3r-Secret-pass';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflow();
        Queue::fake();
    }

    private function grant(User $u, array $perms): User
    {
        $u->permissions = $perms;
        $u->save();

        return $u->fresh();
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'email' => 'New.Person@Example.com', 'password' => 'Tr0ub4dor&3-xyz', 'password2' => 'Tr0ub4dor&3-xyz',
            'first_name' => 'New', 'last_name' => 'Person', 'role' => 'REPORTER', 'permissions' => ['ads.manage'],
        ], $over);
    }

    // ------------------------------------------------------------ catalog / access

    public function test_catalog_is_admin_only_and_lists_every_key(): void
    {
        $r = $this->as($this->admin)->getJson('/api/accounts/user-permissions/')->assertOk();
        $keys = collect($r->json())->pluck('key')->all();
        $this->assertEqualsCanonicalizing(Permissions::keys(), $keys);
        $this->as($this->author)->getJson('/api/accounts/user-permissions/')->assertForbidden();
        $this->as(null)->getJson('/api/accounts/user-permissions/')->assertUnauthorized();
    }

    public function test_a_permission_holder_still_cannot_manage_users(): void
    {
        $u = $this->grant($this->author, Permissions::keys());
        $this->as($u)->getJson('/api/accounts/users/')->assertForbidden();
        $this->as($u)->postJson('/api/accounts/users/', $this->payload())->assertForbidden();
        $this->as($u)->patchJson("/api/accounts/users/{$this->plainUser->id}/", ['role' => 'ADMIN'])->assertForbidden();
    }

    // ------------------------------------------------------------ create

    public function test_admin_creates_a_user_with_role_and_permissions(): void
    {
        $r = $this->as($this->admin)->postJson('/api/accounts/users/', $this->payload())->assertCreated();
        $r->assertJsonPath('email', 'New.Person@example.com')->assertJsonPath('role', 'REPORTER')->assertJsonPath('permissions', ['ads.manage']);
        $u = User::where('email', 'New.Person@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Tr0ub4dor&3-xyz', $u->password));
        $this->assertTrue($u->is_active);
        $this->postJson('/api/auth/login/', ['email' => 'New.Person@example.com', 'password' => 'Tr0ub4dor&3-xyz'])->assertOk();
    }

    public function test_create_validation(): void
    {
        $this->as($this->admin);
        $this->postJson('/api/accounts/users/', $this->payload(['email' => strtoupper($this->plainUser->email)]))->assertStatus(400)->assertJsonStructure(['email']);
        $this->postJson('/api/accounts/users/', $this->payload(['password' => '123', 'password2' => '123']))->assertStatus(400)->assertJsonStructure(['password']);
        $this->postJson('/api/accounts/users/', $this->payload(['password2' => 'different-Pass-9']))->assertStatus(400)->assertJsonStructure(['password2']);
        $this->postJson('/api/accounts/users/', $this->payload(['permissions' => ['nope.nope']]))->assertStatus(400)->assertJsonStructure(['permissions']);
        $this->postJson('/api/accounts/users/', $this->payload(['role' => 'GOD']))->assertStatus(400)->assertJsonStructure(['role']);
        $this->postJson('/api/accounts/users/', ['email' => 'x@example.com'])->assertStatus(400)->assertJsonStructure(['password']);
    }

    public function test_creating_an_admin_stores_no_explicit_permissions(): void
    {
        $this->as($this->admin)->postJson('/api/accounts/users/', $this->payload(['role' => 'ADMIN', 'permissions' => ['ads.manage']]))->assertCreated();
        $u = User::where('email', 'New.Person@example.com')->firstOrFail();
        $this->assertNull($u->getRawOriginal('permissions'));
        $this->assertEqualsCanonicalizing(Permissions::keys(), $u->effectivePermissions());
    }

    // ------------------------------------------------------------ update

    public function test_admin_edits_name_email_phone_role_and_permissions(): void
    {
        $this->as($this->admin)->patchJson("/api/accounts/users/{$this->plainUser->id}/", [
            'first_name' => 'Renamed', 'last_name' => 'Person', 'email' => 'Renamed@Example.com', 'phone' => '+919999900001',
            'role' => 'REPORTER', 'permissions' => ['ads.manage', 'analytics.view', 'ads.manage'],
        ])->assertOk()->assertJsonPath('email', 'Renamed@example.com')->assertJsonPath('role', 'REPORTER');
        $u = $this->plainUser->fresh();
        $this->assertSame('Renamed', $u->first_name);
        $this->assertSame('+919999900001', $u->phone);
        $this->assertEqualsCanonicalizing(['ads.manage', 'analytics.view'], $u->effectivePermissions());
    }

    public function test_duplicate_email_on_update_is_rejected(): void
    {
        $this->as($this->admin)->patchJson("/api/accounts/users/{$this->plainUser->id}/", ['email' => $this->author->email])
            ->assertStatus(400)->assertJsonStructure(['email']);
    }

    public function test_password_change_works_and_revokes_the_users_tokens(): void
    {
        $this->plainUser->createToken('access', ['access']);
        $this->as($this->admin)->patchJson("/api/accounts/users/{$this->plainUser->id}/", ['password' => 'Brand-New-Pass-77', 'password2' => 'Brand-New-Pass-77'])->assertOk();
        $this->assertSame(0, $this->plainUser->tokens()->count());
        $this->postJson('/api/auth/login/', ['email' => $this->plainUser->email, 'password' => self::PW])->assertStatus(401);
        $this->postJson('/api/auth/login/', ['email' => $this->plainUser->email, 'password' => 'Brand-New-Pass-77'])->assertOk();
    }

    public function test_weak_or_mismatched_password_on_update_is_rejected(): void
    {
        $this->as($this->admin);
        $this->patchJson("/api/accounts/users/{$this->plainUser->id}/", ['password' => '123'])->assertStatus(400)->assertJsonStructure(['password']);
        $this->patchJson("/api/accounts/users/{$this->plainUser->id}/", ['password' => 'Brand-New-Pass-77', 'password2' => 'Other-Pass-77x'])->assertStatus(400)->assertJsonStructure(['password2']);
        $this->assertTrue(Hash::check(self::PW, $this->plainUser->fresh()->password));
    }

    public function test_blank_password_leaves_the_password_alone(): void
    {
        $this->as($this->admin)->patchJson("/api/accounts/users/{$this->plainUser->id}/", ['password' => '', 'first_name' => 'Same'])->assertOk();
        $this->assertTrue(Hash::check(self::PW, $this->plainUser->fresh()->password));
    }

    public function test_promoting_to_admin_clears_permissions_and_demoting_starts_empty(): void
    {
        $u = $this->grant($this->author, ['ads.manage']);
        $this->as($this->admin)->patchJson("/api/accounts/users/{$u->id}/", ['role' => 'ADMIN'])->assertOk();
        $this->assertNull($u->fresh()->getRawOriginal('permissions'));
        $this->as($this->admin)->patchJson("/api/accounts/users/{$u->id}/", ['role' => 'REPORTER'])->assertOk();
        $this->assertSame([], $u->fresh()->effectivePermissions());
    }

    public function test_deactivating_revokes_tokens_and_drops_all_permissions(): void
    {
        $u = $this->grant($this->author, ['ads.manage']);
        $u->createToken('access', ['access']);
        $this->as($this->admin)->patchJson("/api/accounts/users/{$u->id}/", ['is_active' => false])->assertOk();
        $this->assertSame(0, $u->tokens()->count());
        $this->assertSame([], $u->fresh()->effectivePermissions());
    }

    public function test_an_admin_cannot_demote_or_deactivate_themselves(): void
    {
        $this->as($this->admin);
        $this->patchJson("/api/accounts/users/{$this->admin->id}/", ['role' => 'REPORTER'])->assertStatus(400)->assertJsonStructure(['role']);
        $this->patchJson("/api/accounts/users/{$this->admin->id}/", ['is_active' => false])->assertStatus(400);
        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_the_last_active_admin_cannot_be_demoted(): void
    {
        $this->admin2->forceFill(['is_active' => false])->save();
        $this->as($this->admin);
        $this->patchJson("/api/accounts/users/{$this->admin2->id}/", ['role' => 'USER'])->assertOk(); // inactive one may be demoted
        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_changing_own_password_keeps_the_current_session_token(): void
    {
        $this->as($this->admin);
        $before = $this->admin->tokens()->count();
        $this->patchJson("/api/accounts/users/{$this->admin->id}/", ['password' => 'Brand-New-Pass-77', 'password2' => 'Brand-New-Pass-77'])->assertOk();
        $this->assertSame(1, $this->admin->tokens()->count());
        $this->assertGreaterThanOrEqual(1, $before);
        $this->getJson('/api/accounts/users/')->assertOk();
    }

    // ------------------------------------------------------------ /auth/me exposes permissions

    public function test_me_returns_effective_permissions(): void
    {
        $u = $this->grant($this->author, ['ads.manage']);
        $this->as($u)->getJson('/api/auth/me/')->assertOk()->assertJsonPath('permissions', ['ads.manage']);
        $this->as($this->admin)->getJson('/api/auth/me/')->assertOk()->assertJsonCount(count(Permissions::keys()), 'permissions');
    }

    // ------------------------------------------------------------ enforcement per area

    public function test_ads_permission(): void
    {
        $this->as($this->author)->getJson('/api/advertisements/')->assertForbidden();
        $this->grant($this->author, ['ads.manage']);
        $this->as($this->author)->getJson('/api/advertisements/')->assertOk();
        $this->as($this->author)->getJson('/api/analytics/overview/')->assertForbidden();
    }

    public function test_analytics_permission_covers_analytics_and_ai_results(): void
    {
        foreach (['/api/analytics/overview/', '/api/ai/analysis-results/', '/api/ai/plagiarism-results/'] as $url) {
            $this->as($this->plainUser)->getJson($url)->assertForbidden();
        }
        $this->grant($this->plainUser, ['analytics.view']);
        foreach (['/api/analytics/overview/', '/api/ai/analysis-results/', '/api/ai/plagiarism-results/'] as $url) {
            $this->as($this->plainUser)->getJson($url)->assertOk();
        }
    }

    public function test_subscriptions_permission(): void
    {
        $this->as($this->plainUser)->getJson('/api/subscriptions/admin/list/')->assertForbidden();
        $this->grant($this->plainUser, ['subscriptions.manage']);
        $this->as($this->plainUser)->getJson('/api/subscriptions/admin/list/')->assertOk();
        $this->as($this->plainUser)->getJson('/api/subscriptions/admin/plans/')->assertOk();
    }

    public function test_notifications_admin_permission(): void
    {
        $this->as($this->plainUser)->getJson('/api/notifications/admin/')->assertForbidden();
        $this->grant($this->plainUser, ['notifications.view']);
        $this->as($this->plainUser)->getJson('/api/notifications/admin/')->assertOk();
    }

    public function test_reporter_assignments_and_schedules_permissions(): void
    {
        $this->as($this->author)->getJson('/api/reporters/assignments/')->assertForbidden();
        $this->as($this->author)->getJson('/api/reporters/schedules/')->assertForbidden();
        $this->grant($this->author, ['reporters.manage']);
        $this->as($this->author)->getJson('/api/reporters/assignments/')->assertOk();
        $this->as($this->author)->getJson('/api/reporters/schedules/')->assertForbidden();
        $this->grant($this->author, ['articles.publish']);
        $this->as($this->author)->getJson('/api/reporters/schedules/')->assertOk();
        $this->as($this->author)->getJson('/api/reporters/assignments/')->assertForbidden();
    }

    public function test_taxonomy_permission(): void
    {
        IndustryFactory::new()->create(['slug' => 'business']);
        $this->as($this->plainUser)->postJson('/api/categories/', ['name' => 'Startups', 'industry_slug' => 'business'])->assertForbidden();
        $this->as($this->author)->postJson('/api/categories/', ['name' => 'Startups', 'industry_slug' => 'business'])->assertForbidden();
        $this->grant($this->author, ['taxonomy.manage']);
        $this->as($this->author)->postJson('/api/categories/', ['name' => 'Startups', 'industry_slug' => 'business'])->assertCreated();
    }

    // ------------------------------------------------------------ article permissions

    public function test_without_permissions_a_reporter_cannot_touch_others_articles(): void
    {
        $a = $this->article(S::UNDER_REVIEW);
        $this->act($this->otherReporter, $a->slug, 'approve')->assertStatus(404);
        $this->assertSame(S::UNDER_REVIEW, $this->statusOf($a));
    }

    public function test_publish_permission_lets_a_reporter_approve_publish_and_reject_others_articles(): void
    {
        $this->grant($this->otherReporter, ['articles.publish']);
        $a = $this->article(S::UNDER_REVIEW);
        $this->act($this->otherReporter, $a->slug, 'approve')->assertOk();
        $this->assertSame(S::APPROVED, $this->statusOf($a));
        $this->act($this->otherReporter, $a->slug, 'publish')->assertOk();
        $this->assertSame(S::PUBLISHED, $this->statusOf($a));

        $b = $this->article(S::UNDER_REVIEW);
        $this->act($this->otherReporter, $b->slug, 'reject', ['reason' => 'Not suitable for publication.'])->assertOk();
        $this->assertSame(S::REJECTED, $this->statusOf($b));
    }

    public function test_publish_permission_lets_a_reporter_assign_but_not_review_their_own_article(): void
    {
        $this->grant($this->assignee, ['articles.publish']);
        $mine = $this->article(S::UNDER_REVIEW, ['author_id' => $this->assignee->id]);
        $this->act($this->assignee, $mine->slug, 'approve')->assertStatus(403);

        $a = $this->article(S::DRAFT);
        $this->act($this->assignee, $a->slug, 'assign-reporter', ['reporter_id' => $this->assignee->id])->assertOk();
    }

    public function test_manage_permission_lets_a_user_see_and_edit_others_articles(): void
    {
        $a = $this->article(S::DRAFT);
        $this->as($this->otherReporter)->getJson("/api/articles/{$a->slug}/")->assertNotFound();
        $this->as($this->otherReporter)->patchJson("/api/articles/{$a->slug}/", ['title' => 'Hijack'])->assertStatus(404);

        $this->grant($this->otherReporter, ['articles.manage']);
        $this->as($this->otherReporter)->getJson("/api/articles/{$a->slug}/")->assertOk();
        $this->as($this->otherReporter)->patchJson("/api/articles/{$a->slug}/", ['title' => 'Edited by manager'])->assertOk();
        $this->assertSame('Edited by manager', $a->fresh()->title);
        $this->assertGreaterThanOrEqual(1, Article::query()->count());
    }

    public function test_a_manage_permission_alone_cannot_publish(): void
    {
        $this->grant($this->otherReporter, ['articles.manage']);
        $a = $this->article(S::UNDER_REVIEW);
        $this->act($this->otherReporter, $a->slug, 'approve')->assertStatus(403);
        $this->assertSame(S::UNDER_REVIEW, $this->statusOf($a));
    }

    public function test_a_deactivated_holder_has_no_powers(): void
    {
        $u = $this->grant($this->author, ['ads.manage']);
        $tok = $u->createToken('access', ['access'])->plainTextToken;
        $u->forceFill(['is_active' => false])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($tok)->getJson('/api/advertisements/')->assertStatus(401);
    }
}
