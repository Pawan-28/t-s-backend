<?php

namespace App\Http\Controllers\Api;

use App\Enums\ArticleStatus;
use App\Events\ArticleViewed;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Models\User;
use App\Services\Articles\ArticleInput;
use App\Services\Articles\ArticleQuery;
use App\Services\Articles\ArticleService;
use App\Services\Articles\RelatedArticles;
use App\Support\ApiUser;
use App\Support\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Article CRUD + list + related (Django ArticleViewSet, minus the workflow actions,
 * mine/assigned lists and AI endpoints that live with their own areas).
 * ArticleResource is the only serializer: locked bodies never leave the server.
 */
class ArticleController extends Controller
{
    public function __construct(private readonly ArticleService $articles) {}

    /** GET /api/articles/ - public; visibility depends on the caller's role. */
    public function index(Request $request): JsonResponse
    {
        $user = ApiUser::resolve($request);
        $query = ArticleQuery::listing(ArticleQuery::visibleTo($user), $request);

        return response()->json(Page::make($query, $request, fn (Article $a) => (new ArticleResource($a))->resolve($request)));
    }

    /** GET /api/articles/{slug}/ */
    public function show(Request $request, string $slug): JsonResponse
    {
        $user = ApiUser::resolve($request);
        $article = $this->find($user, $slug);

        if ($article->status === ArticleStatus::PUBLISHED && $request->isMethod('GET')) {
            $this->recordView($request, $article, $user);
        }

        return response()->json((new ArticleResource($article))->resolve($request));
    }

    /** POST /api/articles/ - admin or reporter; always created as DRAFT by the authenticated author. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->writer($request);
        $data = ArticleInput::validate($request, $user, null, false);
        $article = $this->articles->create($user, $data);

        return response()->json($this->present($request, $article), 201);
    }

    /** PUT|PATCH /api/articles/{slug}/ */
    public function update(Request $request, string $slug): JsonResponse
    {
        $user = $this->writer($request);
        $article = $this->find($user, $slug);
        Gate::forUser($user)->authorize('update', $article);

        $data = ArticleInput::validate($request, $user, $article, $request->isMethod('PATCH'));
        $updated = $this->articles->update($article, $user, $data);

        return response()->json($this->present($request, $updated));
    }

    /** DELETE /api/articles/{slug}/ */
    public function destroy(Request $request, string $slug): Response
    {
        $user = $this->writer($request);
        $article = $this->find($user, $slug);
        Gate::forUser($user)->authorize('delete', $article);
        $article->delete();

        return response()->noContent();
    }

    /** GET /api/articles/{slug}/related/?limit=4 (max 20) - PUBLISHED candidates only, plain array. */
    public function related(Request $request, string $slug, RelatedArticles $related): JsonResponse
    {
        $article = $this->find(ApiUser::resolve($request), $slug);
        $raw = $request->query('limit');
        $limit = is_string($raw) && ctype_digit($raw) && (int) $raw > 0 ? min((int) $raw, 20) : 4;

        $items = $related->for($article, $limit)->map(fn (Article $a) => (new ArticleResource($a))->resolve($request));

        return response()->json($items->values()->all());
    }

    // ------------------------------------------------------------------

    /** Visible article by slug (404 for anything the caller may not see, like Django's queryset scoping). */
    private function find(?User $user, string $slug): Article
    {
        return ArticleQuery::visibleTo($user)
            ->with(ArticleResource::relations())
            ->where('articles.slug', $slug)
            ->firstOrFail();
    }

    /** Django ArticleWritePermission: authenticated (route middleware) + admin/reporter role. */
    private function writer(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->is_active || ! $user->isArticleStaff()) {
            throw new AccessDeniedHttpException('Only reporters and administrators can create or modify articles.');
        }

        return $user;
    }

    private function present(Request $request, Article $article): array
    {
        $fresh = Article::query()->with(ArticleResource::relations())->findOrFail($article->id);

        return (new ArticleResource($fresh))->resolve($request);
    }

    private function recordView(Request $request, Article $article, ?User $user): void
    {
        $key = $user ? 'u:'.$user->id : sha1($request->ip().'|'.$request->userAgent());
        try {
            event(new ArticleViewed($article, $key));
        } catch (\Throwable $e) {
            report($e); // analytics must never break reading
        }
    }
}
