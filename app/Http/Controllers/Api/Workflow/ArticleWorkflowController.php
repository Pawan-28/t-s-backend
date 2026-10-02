<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Http\Resources\WorkflowResources;
use App\Models\Article;
use App\Models\User;
use App\Services\Workflow\ArticleListQuery;
use App\Services\Workflow\ArticleWorkflowService;
use App\Support\Page;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * /api/articles/{slug}/<action>/ (Django ArticleViewSet actions) + review history + mine/assigned lists.
 * Order of checks mirrors DRF: authentication (middleware) -> writer role -> object visibility (404) ->
 * object-level owner check -> action rule -> body validation -> service (which re-checks under lock).
 */
class ArticleWorkflowController extends Controller
{
    public function __construct(private readonly ArticleWorkflowService $workflow) {}

    // ---- reporter-facing lists ---------------------------------------------------------------

    public function mine(Request $request): JsonResponse
    {
        return $this->list($request, 'author_id');
    }

    public function assigned(Request $request): JsonResponse
    {
        return $this->list($request, 'assigned_reporter_id');
    }

    private function list(Request $request, string $ownerColumn): JsonResponse
    {
        $user = $request->user();
        $q = ArticleListQuery::apply(ArticleListQuery::visibleTo($user)->where($ownerColumn, $user->id), $request);

        return response()->json(Page::make($q, $request, fn (Article $a) => (new ArticleResource($a))->resolve($request)));
    }

    // ---- actions ------------------------------------------------------------------------------

    public function submit(Request $request, string $slug): JsonResponse
    {
        $article = $this->find($request, $slug);
        $this->workflow->authorize($request->user(), $article, 'submit');

        return $this->respond($this->workflow->submit($article, $request->user()));
    }

    public function startReview(Request $request, string $slug): JsonResponse
    {
        $article = $this->find($request, $slug);
        $this->workflow->authorize($request->user(), $article, 'startReview');

        return $this->respond($this->workflow->startReview($article, $request->user()));
    }

    public function requestChanges(Request $request, string $slug): JsonResponse
    {
        $article = $this->find($request, $slug);
        $this->workflow->authorize($request->user(), $article, 'requestChanges');
        $reason = $this->reason($request);

        return $this->respond($this->workflow->requestChanges($article, $request->user(), $reason));
    }

    public function reject(Request $request, string $slug): JsonResponse
    {
        $article = $this->find($request, $slug);
        $this->workflow->authorize($request->user(), $article, 'reject');
        $reason = $this->reason($request);

        return $this->respond($this->workflow->reject($article, $request->user(), $reason));
    }

    public function approve(Request $request, string $slug): JsonResponse
    {
        $article = $this->find($request, $slug);
        $this->workflow->authorize($request->user(), $article, 'approve');

        return $this->respond($this->workflow->approve($article, $request->user()));
    }

    public function publish(Request $request, string $slug): JsonResponse
    {
        $article = $this->find($request, $slug);
        $this->workflow->authorize($request->user(), $article, 'publish');

        return $this->respond($this->workflow->publishNow($article, $request->user()));
    }

    public function schedule(Request $request, string $slug): JsonResponse
    {
        $article = $this->find($request, $slug);
        $this->workflow->authorize($request->user(), $article, 'schedule');
        $at = $this->scheduledFor($request);

        return $this->respond($this->workflow->schedule($article, $request->user(), $at));
    }

    public function cancelSchedule(Request $request, string $slug): JsonResponse
    {
        $article = $this->find($request, $slug);
        $this->workflow->authorize($request->user(), $article, 'cancelSchedule');

        return $this->respond($this->workflow->cancelSchedule($article, $request->user()));
    }

    public function assignReporter(Request $request, string $slug): JsonResponse
    {
        $article = $this->find($request, $slug);
        $this->workflow->authorize($request->user(), $article, 'assignReporter');

        $raw = $request->input('reporter_id');
        if (! $request->has('reporter_id')) {
            throw ValidationException::withMessages(['reporter_id' => ['This field is required.']]);
        }
        if ($raw === null) {
            throw ValidationException::withMessages(['reporter_id' => ['This field may not be null.']]);
        }
        if (is_bool($raw) || (! is_int($raw) && ! (is_string($raw) && preg_match('/^\s*-?\d+\s*$/', $raw)))) {
            throw ValidationException::withMessages(['reporter_id' => ['A valid integer is required.']]);
        }
        $reporter = User::query()->find((int) $raw);
        if (! $reporter) {
            return response()->json(['reporter_id' => 'No user with this id exists.'], 400);
        }

        return $this->respond($this->workflow->assignReporter($article, $request->user(), $reporter));
    }

    /** Full audit trail, newest first. (Django exposed it to anyone who could see the article; restricted here.) */
    public function reviewHistory(Request $request, string $slug): JsonResponse
    {
        $article = $this->find($request, $slug, requireOwnership: false);
        $user = $request->user();
        if (! $user->hasArticleOversight() && $article->author_id !== $user->id && $article->assigned_reporter_id !== $user->id) {
            throw new AccessDeniedHttpException('You do not have permission to perform this action.');
        }
        $reviews = $article->reviews()->with('reviewer')->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json($reviews->map(fn ($r) => WorkflowResources::review($r))->all());
    }

    // ---- helpers ------------------------------------------------------------------------------

    /** Writer-role gate + visibility (404) + object-level owner check, in DRF order. */
    private function find(Request $request, string $slug, bool $requireOwnership = true): Article
    {
        $user = $request->user();
        if ($requireOwnership && ! $user->isArticleStaff()) {
            throw new AccessDeniedHttpException('Only reporters and administrators can create or modify articles.');
        }
        $article = ArticleListQuery::visibleTo($user)->where('slug', $slug)->with(ArticleResource::relations())->firstOrFail();
        if ($requireOwnership && ! $user->hasArticleOversight() && $article->author_id !== $user->id && $article->assigned_reporter_id !== $user->id) {
            throw new AccessDeniedHttpException('You do not have permission to perform this action.');
        }

        return $article;
    }

    private function respond(Article $article): JsonResponse
    {
        return response()->json((new ArticleResource($article))->resolve(request()));
    }

    /** The raw JSON value, before Laravel's ""->null conversion (DRF distinguishes blank from null). */
    private function raw(Request $request, string $key): array
    {
        $body = $request->isJson() ? json_decode($request->getContent(), true) : $request->all();
        $body = is_array($body) ? $body : [];

        return [array_key_exists($key, $body), $body[$key] ?? null];
    }

    private function reason(Request $request): string
    {
        [$present, $v] = $this->raw($request, 'reason');
        if (! $present) {
            throw ValidationException::withMessages(['reason' => ['This field is required.']]);
        }
        if ($v === null) {
            throw ValidationException::withMessages(['reason' => ['This field may not be null.']]);
        }
        if (! is_scalar($v) || is_bool($v)) {
            throw ValidationException::withMessages(['reason' => ['Not a valid string.']]);
        }

        return (string) $v; // service trims / rejects blank / caps at 2000 chars
    }

    private function scheduledFor(Request $request): CarbonImmutable
    {
        [$present, $v] = $this->raw($request, 'scheduled_for');
        if (! $present) {
            throw ValidationException::withMessages(['scheduled_for' => ['This field is required.']]);
        }
        if ($v === null) {
            throw ValidationException::withMessages(['scheduled_for' => ['This field may not be null.']]);
        }
        $wrong = ValidationException::withMessages(['scheduled_for' => [
            'Datetime has wrong format. Use one of these formats instead: YYYY-MM-DDThh:mm[:ss[.uuuuuu]][+HH:MM|-HH:MM|Z].',
        ]]);
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?\s*(Z|[+-]\d{2}(:?\d{2})?)?$/i', trim($v))) {
            throw $wrong;
        }
        try {
            // Naive values are read in the project timezone (Django: Asia/Kolkata), like DRF.
            // Normalised to the app zone: Laravel writes timestamptz as a zone-less wall clock in the Carbon's own
            // zone, so a client-supplied 'Z'/+00:00 instant would otherwise be stored 5.5 h off.
            return CarbonImmutable::parse(trim($v), config('app.timezone'))->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            throw $wrong;
        }
    }
}
