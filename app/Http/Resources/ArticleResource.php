<?php

namespace App\Http\Resources;

use App\Models\Article;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\ApiUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The ONLY serializer for articles (list, detail, mine, assigned, related,
 * search, workflow actions). Restricted-content protection lives here: when the
 * caller is not entitled, `content` is null and `faqs` is [] IN THE API
 * RESPONSE - the body never leaves the server. Eager-load
 * subcategory.category.industry, tags, author, assignedReporter, featuredImage,
 * legacyCategory.industry to avoid N+1.
 *
 * @mixin Article
 */
class ArticleResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var Article $a */
        $a = $this->resource;
        $user = ApiUser::resolve($request);
        $locked = ! app(EntitlementService::class)->canReadFullContent($user, $a);

        $category = $a->effective_category;
        // Newsroom e-mail addresses are only shown to newsroom callers (admin/reporter); everybody else gets a masked one.
        $staff = $user !== null && $user->isArticleStaff();

        return [
            'id' => $a->id,
            'title' => $a->title,
            'slug' => $a->slug,
            'excerpt' => $a->excerpt,
            'content' => $locked ? null : $a->content,
            'location_name' => $a->location_name,
            'faqs' => $locked ? [] : ($a->faqs ?? []),
            'subcategory' => TaxonomyResources::subcategory($a->subcategory),
            'category' => TaxonomyResources::category($category),
            'industry' => TaxonomyResources::industry($category?->industry),
            'tags' => $a->tags->map(fn ($t) => TaxonomyResources::tag($t))->values()->all(),
            'author' => self::person($a->author, $staff),
            'assigned_reporter' => self::person($a->assignedReporter, $staff),
            'status' => $a->status->value,
            'rejection_reason' => $a->rejection_reason,
            'access_level' => $a->access_level->value,
            'featured_image_url' => $a->featuredImage?->bunny_url,
            'is_locked' => $locked,
            'scheduled_publish_at' => $a->scheduled_publish_at?->toIso8601String(),
            'published_at' => $a->published_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }

    public static function person(?User $u, bool $staff = true): ?array
    {
        if (! $u) {
            return null;
        }
        if ($staff) {
            return [
                'id' => $u->id, 'email' => $u->email, 'first_name' => $u->first_name,
                'last_name' => $u->last_name, 'full_name' => $u->full_name,
            ];
        }
        $masked = self::maskEmail((string) $u->email);
        $name = trim(($u->first_name ?? '').' '.($u->last_name ?? ''));

        return [
            'id' => $u->id, 'email' => $masked, 'first_name' => $u->first_name,
            'last_name' => $u->last_name, 'full_name' => $name !== '' ? $name : $masked,
        ];
    }

    /** "jane.doe@example.com" -> "j***@example.com" */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }

    /** Relations every article response needs (use with Article::with()/load()). */
    public static function relations(): array
    {
        return ['subcategory.category.industry', 'legacyCategory.industry', 'tags', 'author', 'assignedReporter', 'featuredImage'];
    }
}
