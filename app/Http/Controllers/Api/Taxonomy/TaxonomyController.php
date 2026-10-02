<?php

namespace App\Http\Controllers\Api\Taxonomy;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiUser;
use App\Support\DrfFields;
use App\Support\DrfQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Shared CRUD flow of the four taxonomy resources (Django ModelViewSets with
 * pagination disabled: plain JSON arrays, ?search=, ?ordering=, public reads,
 * admin-only writes). Subclasses provide model/shape/validation.
 */
abstract class TaxonomyController extends Controller
{
    protected string $writeDenied = 'Only administrators can modify this resource.';

    /** @return class-string<Model> */
    abstract protected function model(): string;

    abstract protected function label(): string;

    /** Eager loads needed by present(). */
    protected function relations(): array
    {
        return [];
    }

    abstract protected function present(Model $m): array;

    /** @return list<string> */
    abstract protected function searchFields(): array;

    /** @return array<string,string> */
    abstract protected function orderingFields(): array;

    /** @return list<array{0:string,1:string}> */
    abstract protected function defaultOrdering(): array;

    /** Validate + normalise input; returns attributes ready for fill() (may include a resolved parent id). */
    abstract protected function validated(Request $request, ?Model $existing, bool $partial): array;

    /** Human description of what still references the row, or null when it is safe to remove. */
    protected function referencedBy(Model $m): ?string
    {
        return null;
    }

    /** Unique slug for a new/blank-slug row. */
    abstract protected function makeSlug(Model $m, string $source): string;

    /** Hook after a successful create/update (e.g. reindex articles after a rename). */
    protected function saved(Model $m, bool $created): void {}

    /** Whether public (non-admin) readers only see active rows. */
    protected function hidesInactive(): bool
    {
        return true;
    }

    protected function viewer(Request $request): ?User
    {
        return ApiUser::resolve($request);
    }

    protected function isAdmin(Request $request): bool
    {
        return (bool) $this->viewer($request)?->canManageTaxonomy();
    }

    protected function requireWrite(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->is_active || ! $this->canWrite($user, $request)) {
            throw new AccessDeniedHttpException($this->writeDenied);
        }

        return $user;
    }

    protected function canWrite(User $user, Request $request): bool
    {
        return $user->canManageTaxonomy();
    }

    protected function scope(Builder $query, Request $request, bool $admin): Builder
    {
        if (! $admin && $this->hidesInactive()) {
            $query->where($this->table().'.is_active', true);
        }

        return $query;
    }

    protected function table(): string
    {
        return (new ($this->model()))->getTable();
    }

    protected function find(Request $request, string|int $key, ?string $column = null): Model
    {
        $query = $this->model()::query()->with($this->relations());
        $this->scope($query, $request, $this->isAdmin($request));
        $query->where($this->table().'.'.($column ?? 'slug'), $key);

        return $query->firstOrFail();
    }

    protected function lookupColumn(): string
    {
        return 'slug';
    }

    // ------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $query = $this->model()::query()->with($this->relations());
        $this->scope($query, $request, $this->isAdmin($request));
        $this->filter($query, $request);
        DrfQuery::search($query, $request, $this->searchFields());
        DrfQuery::order($query, $request, $this->orderingFields(), $this->defaultOrdering());

        return response()->json($query->get()->map(fn ($m) => $this->present($m))->all());
    }

    protected function filter(Builder $query, Request $request): void {}

    public function show(Request $request, string|int $key): JsonResponse
    {
        return response()->json($this->present($this->find($request, $key, $this->lookupColumn())));
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireWrite($request);
        $attrs = $this->validated($request, null, false);
        $model = new ($this->model());
        $model->fill($attrs);
        if (($attrs['slug'] ?? '') === '') {
            $model->slug = $this->makeSlug($model, (string) $model->name);
        }
        $model->save();
        $this->saved($model, true);

        return response()->json($this->present($this->fresh($model)), 201);
    }

    public function update(Request $request, string|int $key): JsonResponse
    {
        $this->requireWrite($request);
        $model = $this->find($request, $key, $this->lookupColumn());
        $partial = $request->isMethod('PATCH');
        $attrs = $this->validated($request, $model, $partial);
        $model->fill($attrs);
        if (array_key_exists('slug', $attrs) && $attrs['slug'] === '') {
            $model->slug = $this->makeSlug($model, (string) $model->name);
        }
        $model->save();
        $this->saved($model, false);

        return response()->json($this->present($this->fresh($model)));
    }

    /**
     * DELETE: refused (409) while other records still reference the row; otherwise
     * Django's soft delete (is_active=false, 204). Rows are never hard-deleted.
     */
    public function destroy(Request $request, string|int $key): JsonResponse|Response
    {
        $this->requireWrite($request);
        $model = $this->find($request, $key, $this->lookupColumn());
        if ($blocked = $this->referencedBy($model)) {
            return response()->json([
                'detail' => 'Cannot delete this '.$this->label().' because it is still referenced by '.$blocked.'. Deactivate it instead, or remove/reassign the dependent records first.',
            ], 409);
        }
        $model->forceFill(['is_active' => false])->save();

        return response()->noContent();
    }

    public function activate(Request $request, string|int $key): JsonResponse
    {
        return $this->toggle($request, $key, true);
    }

    public function deactivate(Request $request, string|int $key): JsonResponse
    {
        return $this->toggle($request, $key, false);
    }

    private function toggle(Request $request, string|int $key, bool $active): JsonResponse
    {
        $this->requireWrite($request);
        $model = $this->find($request, $key, $this->lookupColumn());
        $model->forceFill(['is_active' => $active])->save();

        return response()->json($this->present($this->fresh($model)));
    }

    protected function fresh(Model $m): Model
    {
        return $this->model()::query()->with($this->relations())->findOrFail($m->getKey());
    }

    // ---- shared validation helpers -------------------------------------

    protected function fields(Request $request, bool $partial): DrfFields
    {
        return new DrfFields($request->all(), $partial);
    }

    /** Case-insensitive "already exists" check (DRF validate_name / validate_slug). */
    protected function taken(string $column, string $value, ?Model $ignore, array $scope = []): bool
    {
        $q = $this->model()::query()->whereRaw("LOWER($column) = LOWER(?)", [$value]);
        foreach ($scope as $col => $val) {
            $q->where($col, $val);
        }
        if ($ignore) {
            $q->whereKeyNot($ignore->getKey());
        }

        return $q->exists();
    }
}
