<?php

namespace App\Services;

use App\Enums\AccessLevel;
use App\Models\Article;
use App\Models\Subscription;
use App\Models\User;

/**
 * Single source of truth for "may this caller receive the article BODY?".
 *
 * PUBLIC: everyone. Otherwise: admins, or any user with an ACTIVE, unexpired
 * subscription. SUBSCRIBER_ONLY and RESTRICTED are treated identically (the
 * plan is ignored). Authors/reporters are NOT exempt (preserved Django rule).
 * Used by ArticleResource so list/detail/related/search all gate the same way.
 */
class EntitlementService
{
    /** @var array<int, bool> per-request memo of active-subscription lookups */
    private array $activeCache = [];

    public function canReadFullContent(?User $user, Article $article): bool
    {
        if ($article->access_level === AccessLevel::PUBLIC) {
            return true;
        }
        if (! $user || ! $user->is_active) {
            return false;
        }
        if ($user->isAdmin() || $user->canManageArticles()) {
            return true;
        }

        return $this->hasActiveSubscription($user);
    }

    /** May this caller read the body of ANY restricted article (admin or active subscriber)? */
    public function canReadAnyRestricted(?User $user): bool
    {
        return $user !== null && $user->is_active && ($user->isAdmin() || $user->canManageArticles() || $this->hasActiveSubscription($user));
    }

    public function hasActiveSubscription(User $user): bool
    {
        return $this->activeCache[$user->id] ??= Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', Subscription::ACTIVE)
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function flush(): void
    {
        $this->activeCache = [];
    }
}
