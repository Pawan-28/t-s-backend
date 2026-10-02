<?php

namespace App\Http\Controllers\Api\Taxonomy;

use App\Http\Resources\TaxonomyResources;
use App\Jobs\ReindexArticleSearch;
use App\Models\Article;
use App\Models\Category;
use App\Models\Industry;
use App\Models\ReporterCategoryAssignment;
use App\Support\DrfFields;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class CategoryController extends TaxonomyController
{
    protected function model(): string
    {
        return Category::class;
    }

    protected function label(): string
    {
        return 'category';
    }

    protected function relations(): array
    {
        return ['industry'];
    }

    protected function present(Model $m): array
    {
        return TaxonomyResources::category($m);
    }

    protected function searchFields(): array
    {
        return ['categories.name', 'categories.description'];
    }

    protected function orderingFields(): array
    {
        return ['name' => 'categories.name', 'created_at' => 'categories.created_at'];
    }

    protected function defaultOrdering(): array
    {
        return [['categories.name', 'asc']];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $slug = $request->query('industry');
        if (is_string($slug) && $slug !== '') {
            $query->whereHas('industry', fn ($q) => $q->where('slug', $slug));
        }
    }

    protected function makeSlug(Model $m, string $source): string
    {
        return Slug::unique(Category::class, $source, 220, $m->exists ? $m : null);
    }

    protected function referencedBy(Model $m): ?string
    {
        $parts = [];
        if (($n = $m->subcategories()->count()) > 0) {
            $parts[] = $n.' subcategor'.($n === 1 ? 'y' : 'ies');
        }
        if (($n = Article::query()->where('category_id', $m->id)->count()) > 0) {
            $parts[] = $n.' article'.($n === 1 ? '' : 's');
        }
        if (($n = ReporterCategoryAssignment::query()->where('category_id', $m->id)->count()) > 0) {
            $parts[] = $n.' reporter assignment'.($n === 1 ? '' : 's');
        }

        return $parts ? implode(', ', $parts) : null;
    }

    protected function validated(Request $request, ?Model $existing, bool $partial): array
    {
        $f = $this->fields($request, $partial);
        $f->string('name', 100, required: true, blank: false);
        $f->slug('slug', 120);
        $f->string('description', 100000);
        $f->bool('is_active');
        $this->url($f, 'image_url', 200);
        $this->nullableString($f, 'image_storage_path', 255);

        $industry = null;
        if ($f->present('industry_slug') || ! $partial) {
            $slug = $f->string('industry_slug', 120, required: true, blank: false);
            if ($slug !== null) {
                $industry = Industry::query()->where('is_active', true)->where('slug', $slug)->first();
                if (! $industry) {
                    $f->error('industry_slug', "Object with slug={$slug} does not exist.");
                }
            }
        }

        $v = $f->validated();
        unset($v['industry_slug']);
        if ($industry) {
            $v['industry_id'] = $industry->id;
        }
        if (isset($v['name']) && ! $f->failed('name') && $this->taken('name', $v['name'], $existing)) {
            $f->error('name', 'A category with this name already exists.');
        }
        if (($v['slug'] ?? '') !== '' && ! $f->failed('slug') && $this->taken('slug', $v['slug'], $existing)) {
            $f->error('slug', 'A category with this slug already exists.');
        }
        $f->throwIfFailed();

        return $v;
    }

    private function url(DrfFields $f, string $key, int $max): void
    {
        $v = $this->nullableString($f, $key, $max);
        if ($v !== null && $v !== '' && ! preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $v)) {
            $f->error($key, 'Enter a valid URL.');
        }
    }

    /** Text column that stores NULL for blank input (nullable + blank Django columns). */
    private function nullableString(DrfFields $f, string $key, int $max): ?string
    {
        $v = $f->string($key, $max);
        if ($v === '') {
            $f->set($key, null);

            return null;
        }

        return $v;
    }

    protected function saved(Model $m, bool $created): void
    {
        if (! $created && ($m->wasChanged('name') || $m->wasChanged('industry_id'))) {
            ReindexArticleSearch::dispatch('category', $m->id);
        }
    }
}
