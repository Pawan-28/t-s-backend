<?php

namespace App\Policies;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\User;

/**
 * Object-level article permissions, ported from Django
 * ArticleWritePermission + IsArticleOwnerOrAdmin + per-action rules.
 * "Admin" = role ADMIN (no superuser flag in Laravel).
 */
class ArticlePolicy
{
    private function isOwner(User $u, Article $a): bool
    {
        return $a->author_id === $u->id;
    }

    private function isAssigned(User $u, Article $a): bool
    {
        return $a->assigned_reporter_id !== null && $a->assigned_reporter_id === $u->id;
    }

    public function create(User $u): bool
    {
        return $u->is_active && ($u->isAdmin() || $u->isReporter() || $u->canManageArticles());
    }

    /** update + delete: admin, or author/assigned reporter while the article is still editable. */
    public function update(User $u, Article $a): bool
    {
        if (! $u->is_active) {
            return false;
        }
        if ($u->canManageArticles()) {
            return true;
        }
        if (! $u->isReporter() || ! ($this->isOwner($u, $a) || $this->isAssigned($u, $a))) {
            return false;
        }
        $s = $a->status;
        if (in_array($s, [ArticleStatus::DRAFT, ArticleStatus::CHANGES_REQUESTED], true)) {
            return true;
        }

        return $s === ArticleStatus::UNDER_REVIEW && $this->isAssigned($u, $a);
    }

    public function delete(User $u, Article $a): bool
    {
        return $this->update($u, $a);
    }

    /** submit: author or assigned reporter ONLY (deliberately no admin bypass). */
    public function submit(User $u, Article $a): bool
    {
        return $u->is_active && ($this->isOwner($u, $a) || $this->isAssigned($u, $a));
    }

    public function startReview(User $u, Article $a): bool
    {
        return $u->canPublishArticles();
    }

    public function requestChanges(User $u, Article $a): bool
    {
        return $u->canPublishArticles();
    }

    public function cancelSchedule(User $u, Article $a): bool
    {
        return $u->canPublishArticles();
    }

    public function assignReporter(User $u, Article $a): bool
    {
        return $u->canPublishArticles();
    }

    /** reject / approve / publish / schedule: admin or the assigned reporter. */
    public function review(User $u, Article $a): bool
    {
        return $u->is_active && ($u->canPublishArticles() || $this->isAssigned($u, $a));
    }

    /** AI + plagiarism runs and history: author, assigned reporter or admin. */
    public function check(User $u, Article $a): bool
    {
        return $u->is_active && ($u->hasArticleOversight() || $this->isOwner($u, $a) || $this->isAssigned($u, $a));
    }
}
