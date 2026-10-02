<?php

namespace App\Http\Controllers\Api\Taxonomy;

use App\Http\Resources\TaxonomyResources;
use App\Jobs\ReindexArticleSearch;
use App\Models\Category;
use App\Models\Subcategory;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Detail routes use the numeric id (slugs are only unique inside a category). */
class SubcategoryController extends TaxonomyController
{
    protected function model(): string
    {
        return Subcategory::class;
    }

    protected function label(): string
    {
        return 'subcategory';
    }

    protected function lookupColumn(): string
    {
        return 'id';
    }

    protected function relations(): array
    {
        return ['category.industry'];
    }

    protected function present(Model $m): array
    {
        return TaxonomyResources::subcategory($m);
    }

    protected function searchFields(): array
    {
        return ['subcategories.name', 'subcategories.description'];
    }

    protected function orderingFields(): array
    {
        return ['name' => 'subcategories.name', 'display_order' => 'subcategories.display_order', 'created_at' => 'subcategories.created_at'];
    }

    protected function defaultOrdering(): array
    {
        return [['subcategories.display_order', 'asc'], ['subcategories.name', 'asc']];
    }

    /** Public readers: active subcategory AND active parent category. */
    protected function scope(Builder $query, Request $request, bool $admin): Builder
    {
        if (! $admin) {
            $query->where('subcategories.is_active', true)
                ->whereHas('category', fn ($q) => $q->where('is_active', true));
        }

        return $query;
    }

    protected function filter(Builder $query, Request $request): void
    {
        $category = $request->query('category');
        if (is_string($category) && $category !== '') {
            $query->whereHas('category', fn ($q) => $q->where('slug', $category));
        }
        $industry = $request->query('industry');
        if (is_string($industry) && $industry !== '') {
            $query->whereHas('category.industry', fn ($q) => $q->where('slug', $industry));
        }
        $slug = $request->query('slug');
        if (is_string($slug) && $slug !== '') {
            $query->where('subcategories.slug', $slug);
        }
    }

    protected function makeSlug(Model $m, string $source): string
    {
        return Slug::unique(Subcategory::class, $source, 220, $m->exists ? $m : null, ['category_id' => $m->category_id]);
    }

    protected function referencedBy(Model $m): ?string
    {
        $n = $m->articles()->count();

        return $n > 0 ? $n.' article'.($n === 1 ? '' : 's') : null;
    }

    protected function validated(Request $request, ?Model $existing, bool $partial): array
    {
        $f = $this->fields($request, $partial);
        $f->string('name', 150, required: true, blank: false);
        $f->slug('slug', 170);
        $f->string('description', 100000);
        $f->bool('is_active');
        $f->positiveInt('display_order');

        $category = null;
        if ($f->present('category_slug') || ! $partial) {
            $slug = $f->string('category_slug', 120, required: true, blank: false);
            if ($slug !== null) {
                $category = Category::query()->where('is_active', true)->where('slug', $slug)->first();
                if (! $category) {
                    $f->error('category_slug', "Object with slug={$slug} does not exist.");
                }
            }
        }

        $v = $f->validated();
        unset($v['category_slug']);
        if ($category) {
            $v['category_id'] = $category->id;
        }

        // Slug uniqueness is scoped to the (possibly new) parent category.
        $categoryId = $category?->id ?? $existing?->category_id;
        $slug = $v['slug'] ?? ($existing && $category && $category->id !== $existing->category_id ? $existing->slug : null);
        if ($slug !== null && $slug !== '' && ! $f->failed('slug') && $categoryId && $this->taken('slug', $slug, $existing, ['category_id' => $categoryId])) {
            $f->error('slug', 'A subcategory with this slug already exists under this category.');
        }
        $f->throwIfFailed();

        return $v;
    }

    protected function saved(Model $m, bool $created): void
    {
        if (! $created && ($m->wasChanged('name') || $m->wasChanged('category_id'))) {
            ReindexArticleSearch::dispatch('subcategory', $m->id);
        }
    }
}
