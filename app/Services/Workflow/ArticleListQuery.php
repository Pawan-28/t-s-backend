<?php

namespace App\Services\Workflow;

use App\Enums\AccessLevel;
use App\Enums\ArticleStatus;
use App\Enums\Role;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\ApiUser;
use App\Support\DrfQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Article visibility + the list filters the reporter-facing endpoints share with Django's ArticleViewSet
 * (status, access_level, author, assigned_reporter, industry/category/subcategory slugs, search, ordering).
 */
class ArticleListQuery
{
    /** Django get_queryset(): admin all; reporter own + assigned + PUBLISHED; everybody else PUBLISHED. */
    public static function visibleTo(?User $user): Builder
    {
        $q = Article::query();
        if ($user && $user->is_active && $user->hasArticleOversight()) {
            return $q;
        }
        if ($user && $user->is_active && $user->role === Role::REPORTER) {
            return $q->where(fn (Builder $w) => $w
                ->where('author_id', $user->id)
                ->orWhere('assigned_reporter_id', $user->id)
                ->orWhere('status', ArticleStatus::PUBLISHED->value));
        }

        return $q->where('status', ArticleStatus::PUBLISHED->value);
    }

    public static function apply(Builder $q, Request $request): Builder
    {
        $errors = [];
        if (($v = self::param($request, 'status')) !== null) {
            if (ArticleStatus::tryFrom($v)) {
                $q->where('status', $v);
            } else {
                $errors['status'] = ["Select a valid choice. {$v} is not one of the available choices."];
            }
        }
        if (($v = self::param($request, 'access_level')) !== null) {
            if (AccessLevel::tryFrom($v)) {
                $q->where('access_level', $v);
            } else {
                $errors['access_level'] = ["Select a valid choice. {$v} is not one of the available choices."];
            }
        }
        foreach (['author' => 'author_id', 'assigned_reporter' => 'assigned_reporter_id'] as $param => $column) {
            if (($v = self::param($request, $param)) !== null) {
                if (ctype_digit($v) && User::query()->whereKey((int) $v)->exists()) {
                    $q->where($column, (int) $v);
                } else {
                    $errors[$param] = ['Select a valid choice. That choice is not one of the available choices.'];
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // Taxonomy: clean single-level params matching the article's EFFECTIVE category (subcategory's parent,
        // or the legacy category when no subcategory is chosen yet).
        if ($slug = self::param($request, 'subcategory')) {
            $q->whereHas('subcategory', fn ($s) => $s->where('slug', $slug));
        } elseif ($slug = self::param($request, 'category')) {
            $q->where(fn (Builder $w) => $w
                ->whereHas('subcategory.category', fn ($c) => $c->where('slug', $slug))
                ->orWhere(fn (Builder $x) => $x->whereNull('subcategory_id')->whereHas('legacyCategory', fn ($c) => $c->where('slug', $slug))));
        } elseif ($slug = self::param($request, 'industry')) {
            $q->where(fn (Builder $w) => $w
                ->whereHas('subcategory.category.industry', fn ($i) => $i->where('slug', $slug))
                ->orWhere(fn (Builder $x) => $x->whereNull('subcategory_id')->whereHas('legacyCategory.industry', fn ($i) => $i->where('slug', $slug))));
        }

        // DRF SearchFilter: every whitespace/comma separated term must match title|excerpt|content (icontains).
        if (($search = self::param($request, 'search')) !== null) {
            // Restricted bodies are searchable only by callers who may read them (no oracle for paywalled text).
            $entitled = app(EntitlementService::class)->canReadAnyRestricted(ApiUser::resolve($request));
            foreach (preg_split('/[\s,]+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
                $q->where(fn (Builder $w) => $w
                    ->where('title', 'like', $like)->orWhere('excerpt', 'like', $like)
                    ->when(
                        $entitled,
                        fn (Builder $x) => $x->orWhere('content', 'like', $like),
                        fn (Builder $x) => $x->orWhere(fn (Builder $y) => $y->where('access_level', 'PUBLIC')->where('content', 'like', $like)),
                    ));
            }
        }

        // DRF OrderingFilter: published_at / created_at, optional '-' prefix; unknown terms ignored.
        $applied = false;
        foreach (explode(',', (string) $request->query('ordering', '')) as $term) {
            $term = trim($term);
            $field = ltrim($term, '-');
            if (in_array($field, ['published_at', 'created_at'], true)) {
                DrfQuery::orderPg($q, $field, str_starts_with($term, '-') ? 'desc' : 'asc'); // PostgreSQL NULL placement
                $applied = true;
            }
        }
        if (! $applied) {
            $q->orderByDesc('created_at');
        }
        $q->orderByDesc('id');

        return $q->with(ArticleResource::relations());
    }

    private static function param(Request $request, string $key): ?string
    {
        $v = $request->query($key);

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
