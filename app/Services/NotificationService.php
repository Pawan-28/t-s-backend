<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\Role;
use App\Models\Article;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * In-app notifications (one row per recipient). Callers decide who to notify;
 * this only persists. Message text is capped to the column length.
 */
class NotificationService
{
    public function notify(?User $recipient, NotificationType $type, string $message, ?Article $article = null): ?Notification
    {
        if (! $recipient || ! $recipient->is_active) {
            return null;
        }

        return Notification::create([
            'recipient_id' => $recipient->id,
            'article_id' => $article?->id,
            'notification_type' => $type,
            'message' => mb_substr($message, 0, 500),
        ]);
    }

    /** @param  iterable<User>  $recipients */
    public function notifyMany(iterable $recipients, NotificationType $type, string $message, ?Article $article = null, ?int $exceptUserId = null): int
    {
        $n = 0;
        foreach ($recipients as $user) {
            if ($exceptUserId !== null && $user->id === $exceptUserId) {
                continue;
            }
            $n += $this->notify($user, $type, $message, $article) ? 1 : 0;
        }

        return $n;
    }

    /** Every active ADMIN (optionally excluding the actor). */
    public function notifyAdmins(NotificationType $type, string $message, ?Article $article = null, ?int $exceptUserId = null): int
    {
        return $this->notifyMany($this->admins(), $type, $message, $article, $exceptUserId);
    }

    /** @return Collection<int, User> */
    public function admins(): Collection
    {
        return User::query()->where('role', Role::ADMIN->value)->where('is_active', true)->get();
    }
}
