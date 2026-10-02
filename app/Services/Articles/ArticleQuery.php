<?php

namespace App\Services\Articles;

use App\Enums\AccessLevel;
use App\Enums\ArticleStatus;
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
 * Article visibility + list filters (Django ArticleViewSet.get_queryset and its
 * DjangoFilter/Ordering/Search backends). Every taxonomy filter matches the
 * article's EFFECTIVE category/industry: the subcategory's parent, or the legacy
 * `category` when no subcategory is chosen yet.
 */
class ArticleQuery
{
    /** Admin: everything. Reporter: own + assigned + PUBLISHED. Everyone else: PUBLISHED only. */
    public static function visibleTo(?User $user): Builder
    {
        $q = Article::query();
        if ($user && $user->is_active && $user->hasArticleOversight()) {
            return $q;
        }
        if ($user && $user->is_active && $user->isReporter()) {
            return $q->where(fn (Builder $w) => $w
                ->where('articles.author_id', $user->id)
                ->orWhere('articles.assigned_reporter_id', $user->id)
                ->orWhere('articles.status', ArticleStatus::PUBLISHED->value));
        }

        return $q->where('articles.status', ArticleStatus::PUBLISHED->value);
    }

    public static function published(): Builder
    {
        return Article::query()->where('articles.status', ArticleStatus::PUBLISHED->value);
    }

    /** subcategory > category > industry precedence (shared by list and search). */
    public static function taxonomy(Builder $q, Request $request): Builder
    {
        $sub = self::param($request, 'subcategory');
        $cat = self::param($request, 'category');
        $ind = self::param($request, 'industry');

        if ($sub !== null) {
            $q->whereHas('subcategory', fn ($s) => $s->where('slug', $sub));
        } elseif ($cat !== null) {
            $q->where(fn (Builder $w) => $w
                ->whereHas('subcategory.category', fn ($c) => $c->where('slug', $cat))
                ->orWhere(fn (Builder $x) => $x->whereNull('articles.subcategory_id')
                    ->whereHas('legacyCategory', fn ($c) => $c->where('slug', $cat))));
        } elseif ($ind !== null) {
            $q->where(fn (Builder $w) => $w
                ->whereHas('subcategory.category.industry', fn ($i) => $i->where('slug', $ind))
                ->orWhere(fn (Builder $x) => $x->whereNull('articles.subcategory_id')
                    ->whereHas('legacyCategory.industry', fn ($i) => $i->where('slug', $ind))));
        }

        return $q;
    }

    /** Full list pipeline: taxonomy, exact filters, ?search=, ?ordering=, eager loads. */
    public static function listing(Builder $q, Request $request): Builder
    {
        $errors = [];
        foreach (['status' => ArticleStatus::class, 'access_level' => AccessLevel::class] as $param => $enum) {
            $v = self::param($request, $param);
            if ($v === null) {
                continue;
            }
            if ($enum::tryFrom($v)) {
                $q->where('articles.'.$param, $v);
            } else {
                $errors[$param] = ["Select a valid choice. {$v} is not one of the available choices."];
            }
        }
        foreach (['author' => 'author_id', 'assigned_reporter' => 'assigned_reporter_id'] as $param => $column) {
            $v = self::param($request, $param);
            if ($v === null) {
                continue;
            }
            if (ctype_digit($v) && strlen($v) < 19 && User::query()->whereKey((int) $v)->exists()) {
                $q->where('articles.'.$column, (int) $v);
            } else {
                $errors[$param] = ['Select a valid choice. That choice is not one of the available choices.'];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        self::taxonomy($q, $request);
        // Bodies of restricted articles are searchable only by callers entitled to read them; otherwise the
        // search box would be an oracle for paywalled text.
        $entitled = app(EntitlementService::class)->canReadAnyRestricted(ApiUser::resolve($request));
        DrfQuery::search(
            $q,
            $request,
            $entitled ? ['articles.title', 'articles.excerpt', 'articles.content'] : ['articles.title', 'articles.excerpt'],
            'search',
            $entitled ? [] : ['articles.content'],
        );
        DrfQuery::order(
            $q,
            $request,
            ['published_at' => 'articles.published_at', 'created_at' => 'articles.created_at'],
            [['articles.created_at', 'desc']]
        );
        $q->orderByDesc('articles.id');

        return $q->with(ArticleResource::relations());
    }

    public static function param(Request $request, string $key): ?string
    {
        $v = $request->query($key);

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
