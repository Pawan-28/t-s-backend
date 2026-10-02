<?php

namespace App\Http\Controllers\Api;

use App\Enums\ArticleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiPlagiarismResources;
use App\Models\AiAnalysisResult;
use App\Models\Article;
use App\Models\PlagiarismCheckResult;
use App\Support\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Admin, READ-ONLY, site-wide browsing of AI / plagiarism results
 * (GET /api/ai/analysis-results/ and /api/ai/plagiarism-results/, + /{id}/).
 * Filters: status, provider, article, article_status, created_after, created_before.
 */
class AiResultsAdminController extends Controller
{
    private const STATUSES = ['PENDING', 'COMPLETED', 'FAILED'];

    public function analysisIndex(Request $request): JsonResponse
    {
        $q = $this->filtered(AiAnalysisResult::query(), $request, ['OPENAI', 'GEMINI']);

        return response()->json(Page::make($q, $request, fn ($r) => AiPlagiarismResources::adminAnalysis($r)));
    }

    /**
     * GET /api/ai/articles/ : EVERY article (analysed or not) with its latest AI analysis and the
     * number of analyses, so the admin screen can offer "Analyze" on any article. Filters: search
     * (title), article_status, ai (any|none|completed|failed = state of the LATEST analysis).
     */
    public function articles(Request $request): JsonResponse
    {
        $errors = [];
        $q = Article::query()->with(['author', 'assignedReporter'])
            ->withCount('aiAnalyses')
            ->addSelect(['latest_ai_id' => AiAnalysisResult::query()->select('id')
                ->whereColumn('article_id', 'articles.id')->orderByDesc('id')->limit(1)]);

        $choice = static fn (string $v) => "Select a valid choice. {$v} is not one of the available choices.";

        if (($as = $request->query('article_status')) !== null && $as !== '') {
            ArticleStatus::tryFrom((string) $as) ? $q->where('status', (string) $as) : $errors['article_status'] = [$choice((string) $as)];
        }
        if (($search = trim((string) $request->query('search', ''))) !== '') {
            $like = '%'.addcslashes(mb_substr($search, 0, 100), '\\%_').'%';
            $q->where('title', 'like', $like);
        }
        $ai = (string) $request->query('ai', '');
        if ($ai !== '') {
            $latest = fn ($status) => $q->whereIn('articles.id', AiAnalysisResult::query()->select('article_id')
                ->whereIn('id', AiAnalysisResult::query()->selectRaw('max(id)')->groupBy('article_id'))
                ->where('status', $status));
            match ($ai) {
                'any' => null,
                'none' => $q->whereDoesntHave('aiAnalyses'),
                'completed' => $latest('COMPLETED'),
                'failed' => $latest('FAILED'),
                default => $errors['ai'] = [$choice($ai)],
            };
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $q->orderByDesc('updated_at')->orderByDesc('id');

        return response()->json(Page::make($q, $request, function (Article $a) {
            $latest = $a->latest_ai_id ? AiAnalysisResult::with('requestedBy')->find($a->latest_ai_id) : null;

            return [
                'id' => $a->id,
                'title' => $a->title,
                'slug' => $a->slug,
                'status' => $a->status->value,
                'author_email' => $a->author?->email,
                'assigned_reporter_email' => $a->assignedReporter?->email,
                'updated_at' => $a->updated_at?->toIso8601String(),
                'analysis_count' => (int) $a->ai_analyses_count,
                'latest_analysis' => $latest ? AiPlagiarismResources::analysis($latest) : null,
            ];
        }));
    }

    public function analysisShow(int $id): JsonResponse
    {
        $r = $this->with(AiAnalysisResult::query())->findOrFail($id);

        return response()->json(AiPlagiarismResources::adminAnalysis($r));
    }

    public function plagiarismIndex(Request $request): JsonResponse
    {
        $q = $this->filtered(PlagiarismCheckResult::query(), $request, ['COPYLEAKS']);

        return response()->json(Page::make($q, $request, fn ($r) => AiPlagiarismResources::adminPlagiarism($r)));
    }

    public function plagiarismShow(int $id): JsonResponse
    {
        $r = $this->with(PlagiarismCheckResult::query())->findOrFail($id);

        return response()->json(AiPlagiarismResources::adminPlagiarism($r));
    }

    private function with(Builder $q): Builder
    {
        return $q->with(['article.author', 'article.assignedReporter', 'requestedBy']);
    }

    private function filtered(Builder $q, Request $request, array $providers): Builder
    {
        $errors = [];
        $this->with($q);

        $choice = static fn (string $v) => "Select a valid choice. {$v} is not one of the available choices.";

        if (($status = $request->query('status')) !== null && $status !== '') {
            in_array($status, self::STATUSES, true) ? $q->where('status', $status) : $errors['status'] = [$choice((string) $status)];
        }
        if (($provider = $request->query('provider')) !== null && $provider !== '') {
            in_array($provider, $providers, true) ? $q->where('provider', $provider) : $errors['provider'] = [$choice((string) $provider)];
        }
        if (($article = $request->query('article')) !== null && $article !== '') {
            if (ctype_digit((string) $article) && Article::whereKey((int) $article)->exists()) {
                $q->where('article_id', (int) $article);
            } else {
                $errors['article'] = ['Select a valid choice. That choice is not one of the available choices.'];
            }
        }
        if (($as = $request->query('article_status')) !== null && $as !== '') {
            $q->whereHas('article', fn ($a) => $a->where('status', (string) $as));
        }
        foreach (['created_after' => '>=', 'created_before' => '<='] as $param => $op) {
            $v = $request->query($param);
            if ($v === null || $v === '') {
                continue;
            }
            try {
                $q->where('created_at', $op, Carbon::parse((string) $v)->setTimezone(config('app.timezone')));
            } catch (\Throwable) {
                $errors[$param] = ['Enter a valid date/time.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $q->orderByDesc('created_at')->orderByDesc('id');
    }
}
