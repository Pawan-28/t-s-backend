<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;
use App\Services\Import\Support\Rules;
use App\Support\HtmlSanitizer;

/**
 * articles_article -> articles. Written with plain SQL (no Eloquent events, no
 * workflow service, no notifications): an import is not an editorial action.
 * Article HTML is re-sanitised (HtmlSanitizer) on the way in. article_search_index is rebuilt afterwards.
 */
class ArticleImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'title', 'slug', 'excerpt', 'content', 'location_name', 'faqs', 'subcategory_id', 'category_id', 'author_id', 'assigned_reporter_id', 'status', 'rejection_reason', 'access_level', 'scheduled_publish_at', 'published_at', 'subscribers_notified_at', 'created_at', 'updated_at'];

    protected const EXPRESSIONS = ['faqs' => 'faqs::text'];

    public function name(): string
    {
        return 'articles';
    }

    public function sourceTable(): string
    {
        return 'articles_article';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        if (! $ctx->has('users', $row['author_id'])) {
            $ctx->skip('articles', $id, 'ART-AUTHOR-ORPHAN', 'author '.($row['author_id'] ?? 'NULL').' does not exist; article (and its dependants) cannot be imported');

            return null;
        }

        $subcategoryId = $row['subcategory_id'] !== null && $ctx->has('subcategories', $row['subcategory_id']) ? (int) $row['subcategory_id'] : null;
        if ($subcategoryId !== null) {
            $categoryId = $ctx->categoryOfSubcategory($subcategoryId); // legacy mirror is always derived from the subcategory
        } else {
            $categoryId = $row['category_id'] !== null && $ctx->has('categories', $row['category_id']) ? (int) $row['category_id'] : null;
        }

        $faqsRaw = ltrim((string) $row['faqs']);
        $faqs = str_starts_with($faqsRaw, '[') && json_validate($faqsRaw) ? $faqsRaw : '[]'; // JSON column: always a valid array document

        return [
            'id' => $id,
            'title' => $row['title'],
            'slug' => $ctx->plan->slug('articles', $id, $row['slug']),
            'excerpt' => self::str($row['excerpt']),
            // Django only sanitised in the API serializer (not Django admin / shell / older rows): never carry
            // possibly-unsafe HTML into the new system. Idempotent for already-clean bodies.
            'content' => HtmlSanitizer::clean((string) $row['content']),
            'location_name' => self::str($row['location_name']),
            'faqs' => $faqs,
            'subcategory_id' => $subcategoryId,
            'category_id' => $categoryId,
            'author_id' => (int) $row['author_id'],
            'assigned_reporter_id' => $row['assigned_reporter_id'] !== null && $ctx->has('users', $row['assigned_reporter_id']) ? (int) $row['assigned_reporter_id'] : null,
            'status' => in_array($row['status'], Rules::articleStatuses(), true) ? $row['status'] : Rules::DEFAULT_ARTICLE_STATUS,
            'rejection_reason' => self::str($row['rejection_reason']),
            'access_level' => in_array($row['access_level'], Rules::accessLevels(), true) ? $row['access_level'] : Rules::DEFAULT_ACCESS_LEVEL,
            'scheduled_publish_at' => $row['scheduled_publish_at'],
            'published_at' => $row['published_at'],
            'subscribers_notified_at' => $row['subscribers_notified_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
