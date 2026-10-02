<?php

namespace App\Services\Articles;

use App\Enums\AccessLevel;
use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\User;
use App\Services\Search\ArticleSearchIndexer;
use App\Services\Workflow\ArticleWorkflowService;
use App\Support\Slug;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Article create/update. The author comes from the authenticated user on create and
 * is never touched afterwards; `articles.status` is only ever written by
 * ArticleWorkflowService (new articles are inserted as DRAFT and, for an admin who
 * asked for another status, moved through the workflow like any other change).
 */
class ArticleService
{
    public function __construct(
        private readonly ArticleSearchIndexer $indexer,
    ) {}

    /** @param array{attrs: array, tag_ids: ?array, status: ?ArticleStatus, slug_blank: bool} $data */
    public function create(User $author, array $data): Article
    {
        $attempts = 0;
        while (true) {
            try {
                return DB::transaction(function () use ($author, $data) {
                    $article = new Article($data['attrs'] + [
                        'access_level' => AccessLevel::PUBLIC,
                        'faqs' => [],
                        'excerpt' => '',
                        'location_name' => '',
                    ]);
                    $article->author_id = $author->id;
                    $article->status = ArticleStatus::DRAFT;
                    $article->rejection_reason = '';
                    if (empty($data['attrs']['slug'])) {
                        $article->slug = $this->autoSlug($article->title, null);
                    }
                    $article->save();

                    if (! empty($data['tag_ids'])) {
                        $article->tags()->sync($data['tag_ids']);
                        $this->indexer->refresh($article);
                    }
                    if ($data['status'] && $data['status'] !== ArticleStatus::DRAFT) {
                        $article = app(ArticleWorkflowService::class)->changeStatus($article, $author, $data['status']);
                    }

                    return $article;
                }, 3);
            } catch (UniqueConstraintViolationException $e) {
                // Lost a slug race against a concurrent request: regenerate (auto slugs only).
                if (! empty($data['attrs']['slug']) || ++$attempts >= 3) {
                    throw $e;
                }
            }
        }
    }

    /** @param array{attrs: array, tag_ids: ?array, status: ?ArticleStatus, slug_blank: bool} $data */
    public function update(Article $article, User $actor, array $data): Article
    {
        return DB::transaction(function () use ($article, $actor, $data) {
            /** @var Article $locked */
            $locked = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
            // Re-check on the locked row: status may have moved while we validated.
            Gate::forUser($actor)->authorize('update', $locked);

            $locked->fill($data['attrs']);
            if ($data['slug_blank']) {
                $locked->slug = $this->autoSlug($locked->title, $locked);
            }
            $locked->save();

            if ($data['tag_ids'] !== null) {
                $locked->tags()->sync($data['tag_ids']);
                $this->indexer->refresh($locked);
            }
            if ($data['status'] && $data['status'] !== $locked->status) {
                $locked = app(ArticleWorkflowService::class)->changeStatus($locked, $actor, $data['status']);
            }

            return $locked;
        }, 3);
    }

    public function autoSlug(string $title, ?Article $ignore): string
    {
        $slug = Slug::unique(Article::class, $title, 220, $ignore);
        if (in_array($slug, ArticleInput::RESERVED_SLUGS, true)) {
            $slug = Slug::unique(Article::class, $slug.' 2', 220, $ignore);
        }

        return $slug;
    }
}
