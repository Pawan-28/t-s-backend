<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiPlagiarismResources;
use App\Models\AiAnalysisResult;
use App\Models\Article;
use App\Models\PlagiarismCheckResult;
use App\Models\User;
use App\Services\Ai\AiCheckService;
use App\Services\Plagiarism\PlagiarismCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET|POST /api/articles/{slug}/ai-check/ and /plagiarism-check/.
 * ADVISORY ONLY: nothing here changes article status or calls the workflow.
 */
class ArticleChecksController extends Controller
{
    public function aiCheck(Request $request, string $slug, AiCheckService $service): JsonResponse
    {
        $article = $this->article($request, $slug);

        if ($request->isMethod('post')) {
            // Synchronous provider call (up to the configured timeout).
            @set_time_limit((int) config('portal.openai.timeout', 60) + 30);
            $result = $service->analyze($article, $request->user())->load('requestedBy');

            return response()->json(AiPlagiarismResources::analysis($result), 201);
        }

        $rows = AiAnalysisResult::with('requestedBy')->where('article_id', $article->id)
            ->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json($rows->map(fn ($r) => AiPlagiarismResources::analysis($r))->all());
    }

    public function plagiarismCheck(Request $request, string $slug, PlagiarismCheckService $service): JsonResponse
    {
        $article = $this->article($request, $slug);

        if ($request->isMethod('post')) {
            $result = $service->submit($article, $request->user())->load('requestedBy');

            return response()->json(AiPlagiarismResources::plagiarism($result), 201);
        }

        $rows = PlagiarismCheckResult::with('requestedBy')->where('article_id', $article->id)
            ->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json($rows->map(fn ($r) => AiPlagiarismResources::plagiarism($r))->all());
    }

    /**
     * Django order: write-permission (POST needs admin/reporter) -> visibility
     * (404 when the caller cannot see the article) -> object permission (403).
     */
    private function article(Request $request, string $slug): Article
    {
        /** @var User $user */
        $user = $request->user();

        if ($request->isMethod('post') && ! $user->isArticleStaff()) {
            throw new AccessDeniedHttpException('Only reporters and administrators can create or modify articles.');
        }

        $article = Article::where('slug', $slug)->first();
        $visible = $article && ($user->hasArticleOversight()
            || $article->status->value === 'PUBLISHED'
            || ($user->isReporter() && ($article->author_id === $user->id || $article->assigned_reporter_id === $user->id)));
        if (! $visible) {
            throw new NotFoundHttpException('Not found.');
        }

        if (! Gate::forUser($user)->allows('check', $article)) {
            throw new AccessDeniedHttpException("Only the article's author, assigned reporter, or an administrator may run this check.");
        }

        return $article;
    }
}
