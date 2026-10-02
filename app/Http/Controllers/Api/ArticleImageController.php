<?php

namespace App\Http\Controllers\Api;

use App\Enums\ArticleStatus;
use App\Http\Controllers\Api\Concerns\ParsesDrfInput;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleImageResource;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\User;
use App\Services\Media\ArticleImageService;
use App\Services\Media\BunnyStorageException;
use App\Services\Media\ImageValidationException;
use App\Support\ApiUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /api/articles/{slug}/images/ (Django apps.media ArticleImageViewSet).
 *
 * Visibility mirrors Django: anyone may list a PUBLISHED article's images;
 * drafts etc. only for admin / author / assigned reporter. Writes need
 * role ADMIN|REPORTER plus ArticlePolicy@update on the article (object level).
 */
class ArticleImageController extends Controller
{
    use ParsesDrfInput;

    public function __construct(private ArticleImageService $images) {}

    public function index(Request $request, string $slug): JsonResponse
    {
        $article = $this->article($slug);
        $this->assertCanView(ApiUser::resolve($request), $article);

        $rows = ArticleImage::query()->where('article_id', $article->id)->with('uploader')
            ->orderBy('display_order')->orderBy('created_at')->orderBy('id')->get();

        // Plain JSON array (Django list is NOT paginated for this resource).
        return response()->json($rows->map(fn (ArticleImage $i) => (new ArticleImageResource($i))->resolve())->all());
    }

    public function show(Request $request, string $slug, int $image): JsonResponse
    {
        $user = ApiUser::resolve($request) ?? throw new AuthenticationException;
        $article = $this->article($slug);
        $this->assertCanView($user, $article);
        $row = $this->image($article, $image);
        if (! $this->isPrivileged($user, $article)) {
            throw new AccessDeniedHttpException('You do not have permission to manage images on this article.');
        }

        return response()->json((new ArticleImageResource($row->load('uploader')))->resolve());
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $user = $this->writer($request);
        $article = $this->article($slug);
        $this->assertCanView($user, $article);
        $this->assertCanWrite($user, $article, true);

        [$attrs, $errors] = $this->metaFields($request);
        $file = $this->file($request, 'image', $errors);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $image = $this->images->upload($article, $user, $file, $attrs);
        } catch (ImageValidationException $e) {
            throw ValidationException::withMessages(['image' => [$e->getMessage()]]);
        } catch (BunnyStorageException $e) {
            Log::error('Article image upload failed at Bunny', ['article_id' => $article->id]);
            throw new HttpException(502, 'Image storage is temporarily unavailable. Please try again.');
        }

        return response()->json((new ArticleImageResource($image))->resolve(), 201);
    }

    /** PATCH (partial) / PUT (Django requires `image` on PUT but never replaces the file with it). */
    public function update(Request $request, string $slug, int $image): JsonResponse
    {
        $user = $this->writer($request);
        $article = $this->article($slug);
        $this->assertCanView($user, $article);
        $row = $this->image($article, $image);
        $this->assertCanWrite($user, $article);

        $partial = $request->isMethod('PATCH');
        [$attrs, $errors] = $this->metaFields($request);
        if (! $partial) {
            $this->file($request, 'image', $errors);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return response()->json((new ArticleImageResource($this->images->update($row, $attrs)))->resolve());
    }

    /** Extra (not in Django): POST .../images/{id}/replace/ with multipart `image` swaps the file of one row. */
    public function replace(Request $request, string $slug, int $image): JsonResponse
    {
        $user = $this->writer($request);
        $article = $this->article($slug);
        $this->assertCanView($user, $article);
        $row = $this->image($article, $image);
        $this->assertCanWrite($user, $article);

        $errors = [];
        $file = $this->file($request, 'image', $errors);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $fresh = $this->images->replace($row, $article, $file);
        } catch (ImageValidationException $e) {
            throw ValidationException::withMessages(['image' => [$e->getMessage()]]);
        } catch (BunnyStorageException $e) {
            Log::error('Article image replace failed at Bunny', ['article_id' => $article->id, 'image_id' => $row->id]);
            throw new HttpException(502, 'Image storage is temporarily unavailable. Please try again.');
        }

        return response()->json((new ArticleImageResource($fresh))->resolve());
    }

    public function destroy(Request $request, string $slug, int $image): JsonResponse
    {
        $user = $this->writer($request);
        $article = $this->article($slug);
        $this->assertCanView($user, $article);
        $row = $this->image($article, $image);
        $this->assertCanWrite($user, $article);

        $this->images->delete($row, $article);

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------------ helpers

    private function article(string $slug): Article
    {
        return Article::query()->where('slug', $slug)->firstOrFail();
    }

    private function image(Article $article, int $id): ArticleImage
    {
        return ArticleImage::query()->where('article_id', $article->id)->whereKey($id)->firstOrFail();
    }

    /** Authenticated reporter/admin (Django ArticleImageWritePermission). */
    private function writer(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }
        if (! $user->isArticleStaff()) {
            throw new AccessDeniedHttpException('Only reporters and administrators can upload or modify article images.');
        }

        return $user;
    }

    private function isPrivileged(User $u, Article $a): bool
    {
        return $u->hasArticleOversight() || $a->author_id === $u->id || ($a->assigned_reporter_id !== null && $a->assigned_reporter_id === $u->id);
    }

    private function assertCanView(?User $user, Article $article): void
    {
        if ($article->status === ArticleStatus::PUBLISHED || ($user && $this->isPrivileged($user, $article))) {
            return;
        }
        // 404, not 403: an unpublished article must be indistinguishable from a non-existent slug
        // (otherwise draft slugs could be enumerated through this endpoint).
        throw new NotFoundHttpException('Not found.');
    }

    /** Object-level write check through ArticlePolicy@update. */
    private function assertCanWrite(User $user, Article $article, bool $creating = false): void
    {
        if (Gate::forUser($user)->allows('update', $article)) {
            return;
        }
        if (! $this->isPrivileged($user, $article)) {
            throw new AccessDeniedHttpException($creating
                ? 'You can only upload images to your own articles, or one assigned to you for review.'
                : 'You do not have permission to manage images on this article.');
        }
        throw new AccessDeniedHttpException('Images can no longer be changed while this article is in its current status.');
    }

    /**
     * alt_text / caption / is_featured / display_order with DRF-style messages.
     *
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    private function metaFields(Request $request): array
    {
        $attrs = [];
        $errors = [];
        foreach (['alt_text' => 255, 'caption' => 500] as $f => $max) {
            if ($request->has($f)) {
                [$v, $e] = $this->drfChar($request->input($f), $max);
                $e ? $errors[$f] = [$e] : $attrs[$f] = $v;
            }
        }
        if ($request->has('is_featured') && $request->input('is_featured') !== null) {
            $raw = $request->input('is_featured');
            $b = $this->drfBool($raw);
            $b === null ? $errors['is_featured'] = ['"'.(is_scalar($raw) ? $raw : 'value').'" is not a valid boolean.'] : $attrs['is_featured'] = $b;
        }
        if ($request->has('display_order') && $request->input('display_order') !== null) {
            [$v, $e] = $this->drfPositiveInt($request->input('display_order'));
            $e ? $errors['display_order'] = [$e] : $attrs['display_order'] = $v;
        }

        return [$attrs, $errors];
    }

    /** @param array<string, list<string>> $errors */
    private function file(Request $request, string $key, array &$errors): ?UploadedFile
    {
        $file = $request->file($key);
        if ($file instanceof UploadedFile) {
            return $file;
        }
        $errors[$key] = [$request->has($key) && $request->input($key) !== null
            ? 'The submitted data was not a file. Check the encoding type on the form.'
            : 'No file was submitted.'];

        return null;
    }
}
