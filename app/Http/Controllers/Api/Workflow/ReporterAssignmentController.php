<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowResources;
use App\Models\Category;
use App\Models\ReporterCategoryAssignment;
use App\Models\User;
use App\Support\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** /api/reporters/assignments/ - admin CRUD (Django ReporterCategoryAssignmentViewSet). */
class ReporterAssignmentController extends Controller
{
    private const WITH = ['reporter', 'category.industry', 'assignedBy'];

    public function index(Request $request): JsonResponse
    {
        $q = ReporterCategoryAssignment::query()
            ->join('categories', 'categories.id', '=', 'reporter_category_assignments.category_id')
            ->join('users as reporters', 'reporters.id', '=', 'reporter_category_assignments.reporter_id')
            ->select('reporter_category_assignments.*')
            ->with(self::WITH)
            ->orderBy('categories.name')->orderBy('reporters.email')->orderBy('reporter_category_assignments.id');

        $errors = [];
        foreach (['reporter' => [User::class, 'reporter_id'], 'category' => [Category::class, 'category_id']] as $param => [$model, $col]) {
            $v = $request->query($param);
            if (is_string($v) && trim($v) !== '') {
                if (ctype_digit(trim($v)) && $model::query()->whereKey((int) $v)->exists()) {
                    $q->where('reporter_category_assignments.'.$col, (int) $v);
                } else {
                    $errors[$param] = ['Select a valid choice. That choice is not one of the available choices.'];
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            foreach (preg_split('/[\s,]+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
                $q->where(fn ($w) => $w
                    ->where('reporters.email', 'like', $like)->orWhere('reporters.first_name', 'like', $like)
                    ->orWhere('reporters.last_name', 'like', $like)->orWhere('categories.name', 'like', $like));
            }
        }

        return response()->json(Page::make($q, $request, fn ($a) => WorkflowResources::assignment($a)));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(WorkflowResources::assignment($this->find($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, partial: false, existing: null);
        $assignment = ReporterCategoryAssignment::query()->create([
            'reporter_id' => $data['reporter']->id,
            'category_id' => $data['category']->id,
            'assigned_by_id' => $request->user()->id,
        ]);

        return response()->json(WorkflowResources::assignment($this->find($assignment->id)), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $assignment = $this->find($id);
        $data = $this->validated($request, partial: $request->isMethod('PATCH'), existing: $assignment);
        $assignment->update(array_filter([
            'reporter_id' => isset($data['reporter']) ? $data['reporter']->id : null,
            'category_id' => isset($data['category']) ? $data['category']->id : null,
        ]));

        return response()->json(WorkflowResources::assignment($this->find($id)));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();

        return response()->json(null, 204);
    }

    private function find(int $id): ReporterCategoryAssignment
    {
        return ReporterCategoryAssignment::query()->with(self::WITH)->findOrFail($id);
    }

    /** DRF ModelSerializer semantics: per-field pk errors, role rule, then the unique-together check. */
    private function validated(Request $request, bool $partial, ?ReporterCategoryAssignment $existing): array
    {
        $errors = [];
        $out = [];
        foreach (['reporter' => User::class, 'category' => Category::class] as $field => $model) {
            if (! $request->has($field)) {
                if (! $partial) {
                    $errors[$field] = ['This field is required.'];
                }

                continue;
            }
            $v = $request->input($field);
            if ($v === null) {
                $errors[$field] = ['This field may not be null.'];
            } elseif (! (is_int($v) || (is_string($v) && preg_match('/^\d+$/', trim($v))))) {
                $errors[$field] = ['Incorrect type. Expected pk value, received '.$this->typeName($v).'.'];
            } elseif (! ($obj = $model::query()->find((int) $v))) {
                $errors[$field] = ['Invalid pk "'.$v.'" - object does not exist.'];
            } else {
                $out[$field] = $obj;
            }
        }
        if (isset($out['reporter']) && $out['reporter']->role !== Role::REPORTER) {
            $errors['reporter'] = ['Only a user with role=REPORTER can be assigned to a category.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $reporterId = ($out['reporter'] ?? null)?->id ?? $existing?->reporter_id;
        $categoryId = ($out['category'] ?? null)?->id ?? $existing?->category_id;
        $dup = ReporterCategoryAssignment::query()->where('reporter_id', $reporterId)->where('category_id', $categoryId)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists();
        if ($dup) {
            throw ValidationException::withMessages(['non_field_errors' => ['The fields reporter, category must make a unique set.']]);
        }

        return $out;
    }

    private function typeName(mixed $v): string
    {
        return match (true) {
            is_string($v) => 'str', is_float($v) => 'float', is_bool($v) => 'bool', is_array($v) => 'list', default => gettype($v),
        };
    }
}
