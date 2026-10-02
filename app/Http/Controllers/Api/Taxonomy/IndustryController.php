<?php

namespace App\Http\Controllers\Api\Taxonomy;

use App\Http\Resources\TaxonomyResources;
use App\Jobs\ReindexArticleSearch;
use App\Models\Industry;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class IndustryController extends TaxonomyController
{
    protected function model(): string
    {
        return Industry::class;
    }

    protected function label(): string
    {
        return 'industry';
    }

    protected function present(Model $m): array
    {
        return TaxonomyResources::industry($m);
    }

    protected function searchFields(): array
    {
        return ['name', 'description'];
    }

    protected function orderingFields(): array
    {
        return ['name' => 'name', 'display_order' => 'display_order', 'created_at' => 'created_at'];
    }

    protected function defaultOrdering(): array
    {
        return [['display_order', 'asc'], ['name', 'asc']];
    }

    protected function makeSlug(Model $m, string $source): string
    {
        return Slug::unique(Industry::class, $source, 220, $m->exists ? $m : null);
    }

    protected function referencedBy(Model $m): ?string
    {
        $n = $m->categories()->count();

        return $n > 0 ? $n.' categor'.($n === 1 ? 'y' : 'ies') : null;
    }

    protected function validated(Request $request, ?Model $existing, bool $partial): array
    {
        $f = $this->fields($request, $partial);
        $f->string('name', 100, required: true, blank: false);
        $f->slug('slug', 120);
        $f->string('description', 100000);
        $f->bool('is_active');
        $f->positiveInt('display_order');

        $v = $f->validated();
        if (isset($v['name']) && ! $f->failed('name') && $this->taken('name', $v['name'], $existing)) {
            $f->error('name', 'An industry with this name already exists.');
        }
        if (($v['slug'] ?? '') !== '' && ! $f->failed('slug') && $this->taken('slug', $v['slug'], $existing)) {
            $f->error('slug', 'An industry with this slug already exists.');
        }
        $f->throwIfFailed();

        return $v;
    }

    protected function saved(Model $m, bool $created): void
    {
        if (! $created && $m->wasChanged('name')) {
            ReindexArticleSearch::dispatch('industry', $m->id);
        }
    }
}
