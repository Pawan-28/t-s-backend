<?php

namespace App\Http\Controllers\Api\Taxonomy;

use App\Http\Resources\TaxonomyResources;
use App\Jobs\ReindexArticleSearch;
use App\Models\Tag;
use App\Models\User;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Tags are independent of the tree: public read; ADMIN or REPORTER may create;
 * only ADMIN may rename/delete. DELETE is a real delete (pivot rows cascade).
 */
class TagController extends TaxonomyController
{
    protected string $writeDenied = 'Only administrators can modify or remove tags.';

    protected function model(): string
    {
        return Tag::class;
    }

    protected function label(): string
    {
        return 'tag';
    }

    protected function hidesInactive(): bool
    {
        return false;
    }

    protected function present(Model $m): array
    {
        return TaxonomyResources::tag($m);
    }

    protected function searchFields(): array
    {
        return ['name'];
    }

    protected function orderingFields(): array
    {
        return ['name' => 'name', 'created_at' => 'created_at'];
    }

    protected function defaultOrdering(): array
    {
        return [['name', 'asc']];
    }

    protected function canWrite(User $user, Request $request): bool
    {
        return $user->canManageTaxonomy() || ($request->isMethod('POST') && $user->isReporter() && $request->route('slug') === null);
    }

    protected function makeSlug(Model $m, string $source): string
    {
        return Slug::unique(Tag::class, $source, 220, $m->exists ? $m : null);
    }

    protected function validated(Request $request, ?Model $existing, bool $partial): array
    {
        $f = $this->fields($request, $partial);
        $f->string('name', 60, required: true, blank: false);
        $f->slug('slug', 80);
        $v = $f->validated();
        if (isset($v['name']) && ! $f->failed('name') && $this->taken('name', $v['name'], $existing)) {
            $f->error('name', 'A tag with this name already exists.');
        }
        if (($v['slug'] ?? '') !== '' && ! $f->failed('slug') && $this->taken('slug', $v['slug'], $existing)) {
            $f->error('slug', 'A tag with this slug already exists.');
        }
        $f->throwIfFailed();

        return $v;
    }

    protected function saved(Model $m, bool $created): void
    {
        if (! $created && $m->wasChanged('name')) {
            ReindexArticleSearch::dispatch('tag', 0, $m->articles()->pluck('articles.id')->all());
        }
    }

    public function destroy(Request $request, string|int $key): JsonResponse|Response
    {
        $this->requireWrite($request);
        $tag = $this->find($request, $key);
        $articleIds = $tag->articles()->pluck('articles.id')->all();
        $tag->delete();
        if ($articleIds) {
            ReindexArticleSearch::dispatch('tag', 0, $articleIds);
        }

        return response()->noContent();
    }

    public function activate(Request $request, string|int $key): JsonResponse
    {
        abort(404);
    }

    public function deactivate(Request $request, string|int $key): JsonResponse
    {
        abort(404);
    }
}
