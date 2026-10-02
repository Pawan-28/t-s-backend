<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Database\Factories\ArticleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function make(User $u, array $o = []): Notification
    {
        return Notification::create($o + ['recipient_id' => $u->id, 'notification_type' => NotificationType::SUBSCRIPTION_ACTIVATED, 'message' => 'Hello', 'is_read' => false]);
    }

    private function as(User $u)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsUser($u);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/notifications/')->assertStatus(401);
        $this->postJson('/api/notifications/1/mark-read/')->assertStatus(401);
    }

    public function test_list_is_paginated_newest_first_and_only_own(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $article = ArticleFactory::new()->published()->create();
        $first = $this->make($me, ['message' => 'old', 'created_at' => now()->subHour()]);
        $second = $this->make($me, ['message' => 'new', 'article_id' => $article->id, 'notification_type' => NotificationType::ARTICLE_PUBLISHED]);
        $this->make($other, ['message' => 'not mine']);

        $r = $this->as($me)->getJson('/api/notifications/')->assertOk();
        $r->assertJsonPath('count', 2)->assertJsonPath('next', null)->assertJsonPath('previous', null)
            ->assertJsonPath('results.0.id', $second->id)->assertJsonPath('results.0.article_slug', $article->slug)
            ->assertJsonPath('results.0.article_title', $article->title)->assertJsonPath('results.1.article_slug', null)
            ->assertJsonPath('results.0.notification_type', 'ARTICLE_PUBLISHED')->assertJsonPath('results.0.is_read', false)
            ->assertJsonStructure(['results' => [['id', 'notification_type', 'article_slug', 'article_title', 'message', 'is_read', 'created_at']]]);
        $this->assertStringNotContainsString('not mine', $r->getContent());

        for ($i = 0; $i < 22; $i++) {
            $this->make($me);
        }
        $this->as($me)->getJson('/api/notifications/')->assertJsonPath('count', 24)->assertJsonCount(20, 'results');
        $this->as($me)->getJson('/api/notifications/?page=2')->assertOk()->assertJsonCount(4, 'results');
        $this->as($me)->getJson('/api/notifications/?page=9')->assertStatus(404)->assertJsonPath('detail', 'Invalid page.');
    }

    public function test_mark_read_and_retrieve_own(): void
    {
        $me = User::factory()->create();
        $n = $this->make($me);
        $this->as($me)->getJson("/api/notifications/{$n->id}/")->assertOk()->assertJsonPath('is_read', false);
        $this->as($me)->postJson("/api/notifications/{$n->id}/mark-read/")->assertOk()->assertJsonPath('is_read', true);
        $this->as($me)->postJson("/api/notifications/{$n->id}/mark-read/")->assertOk()->assertJsonPath('is_read', true); // idempotent
        $this->assertTrue($n->refresh()->is_read);
    }

    public function test_other_users_notification_is_404_not_403_and_stays_unread(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $n = $this->make($owner);
        $this->as($intruder)->getJson("/api/notifications/{$n->id}/")->assertStatus(404)->assertJsonPath('detail', 'Not found.');
        $this->as($intruder)->postJson("/api/notifications/{$n->id}/mark-read/")->assertStatus(404);
        $this->as($intruder)->getJson('/api/notifications/999999/')->assertStatus(404);
        $this->assertFalse($n->refresh()->is_read);
    }

    public function test_mark_all_read_and_unread_count_are_scoped(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->make($me);
        $this->make($me);
        $this->make($me, ['is_read' => true]);
        $theirs = $this->make($other);

        $this->as($me)->getJson('/api/notifications/unread-count/')->assertOk()->assertJsonPath('unread_count', 2);
        $this->as($me)->postJson('/api/notifications/mark-all-read/')->assertOk()->assertJsonPath('updated', 2);
        $this->as($me)->getJson('/api/notifications/unread-count/')->assertJsonPath('unread_count', 0);
        $this->assertFalse($theirs->refresh()->is_read);
    }

    public function test_admin_list_requires_admin_filters_and_marks_read(): void
    {
        $u = User::factory()->create(['email' => 'rcpt@example.com']);
        $n1 = $this->make($u);
        $n2 = $this->make($u, ['notification_type' => NotificationType::PAYMENT_FAILED, 'is_read' => true]);

        $this->as($u)->getJson('/api/notifications/admin/')->assertStatus(403);
        $this->as(User::factory()->reporter()->create())->getJson('/api/notifications/admin/')->assertStatus(403);
        $this->as($u)->postJson("/api/notifications/admin/{$n1->id}/mark-read/")->assertStatus(403);

        $admin = User::factory()->admin()->create();
        $this->as($admin)->getJson('/api/notifications/admin/')->assertOk()->assertJsonPath('count', 2)
            ->assertJsonPath('results.0.recipient_email', 'rcpt@example.com')->assertJsonPath('results.0.recipient_id', $u->id);
        $this->as($admin)->getJson('/api/notifications/admin/?is_read=true')->assertJsonPath('count', 1);
        $this->as($admin)->getJson('/api/notifications/admin/?notification_type=PAYMENT_FAILED')->assertJsonPath('count', 1);
        $this->as($admin)->getJson('/api/notifications/admin/?recipient='.$u->id)->assertJsonPath('count', 2);
        $this->as($admin)->getJson('/api/notifications/admin/?notification_type=NOPE')->assertStatus(400);
        $this->as($admin)->postJson("/api/notifications/admin/{$n1->id}/mark-read/")->assertOk()->assertJsonPath('is_read', true);
        $this->as($admin)->getJson("/api/notifications/admin/{$n2->id}/")->assertOk()->assertJsonPath('id', $n2->id);
    }

    public function test_notification_service_rows_show_up_through_the_api(): void
    {
        $u = User::factory()->create();
        app(NotificationService::class)->notify($u, NotificationType::SUBSCRIPTION_EXPIRED, 'Your Gold subscription has expired.');
        $this->as($u)->getJson('/api/notifications/')->assertJsonPath('results.0.notification_type', 'SUBSCRIPTION_EXPIRED');
    }
}
