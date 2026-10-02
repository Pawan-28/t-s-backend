<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;

/** media_articleimage (+ media_mediametadata folded into the jsonb `metadata`) -> article_images. */
class ArticleImageImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'article_id', 'uploaded_by_id', 'bunny_url', 'bunny_storage_path', 'alt_text', 'caption', 'is_featured', 'display_order', 'created_at', 'updated_at'];

    public function name(): string
    {
        return 'article_images';
    }

    public function sourceTable(): string
    {
        return 'media_articleimage';
    }

    public function extraSourceTables(): array
    {
        return ['media_mediametadata', 'articles_article'];
    }

    public function requiredColumns(): array
    {
        return [
            'media_articleimage' => self::COLUMNS,
            'media_mediametadata' => ['id', 'image_id', 'original_filename', 'content_type', 'file_size_bytes', 'width', 'height', 'checksum'],
            'articles_article' => ['id', 'author_id'],
        ];
    }

    public function idExpr(): string
    {
        return 'i.id';
    }

    public function selectFrom(): string
    {
        return 'SELECT i.id, i.article_id, i.uploaded_by_id, i.bunny_url, i.bunny_storage_path, i.alt_text, i.caption, i.is_featured, i.display_order, i.created_at, i.updated_at,
                a.author_id AS article_author_id,
                CASE WHEN m.id IS NULL THEN NULL ELSE jsonb_build_object(\'original_filename\', m.original_filename, \'content_type\', m.content_type, \'file_size_bytes\', m.file_size_bytes, \'width\', m.width, \'height\', m.height, \'checksum\', m.checksum)::text END AS metadata
            FROM media_articleimage i
            LEFT JOIN articles_article a ON a.id = i.article_id
            LEFT JOIN media_mediametadata m ON m.image_id = i.id';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        $articleId = (int) $row['article_id'];
        if (! $ctx->has('articles', $articleId)) {
            $ctx->skip('article_images', $id, 'IMG-ARTICLE-ORPHAN', 'article '.$articleId.' does not exist or was not imported');

            return null;
        }
        $uploader = $row['uploaded_by_id'];
        if (! $ctx->has('users', $uploader)) {
            // Required in the target: fall back to the article author (documented resolution).
            $uploader = $row['article_author_id'];
            if (! $ctx->has('users', $uploader)) {
                $ctx->skip('article_images', $id, 'IMG-UPLOADER-ORPHAN', 'uploader and article author both missing');

                return null;
            }
        }
        $featured = array_key_exists($articleId, $ctx->plan->featured) ? $ctx->plan->featured[$articleId] === $id : self::bool($row['is_featured']);

        return [
            'id' => $id,
            'article_id' => $articleId,
            'uploaded_by_id' => (int) $uploader,
            'bunny_url' => $row['bunny_url'],
            'bunny_storage_path' => $row['bunny_storage_path'],
            'alt_text' => self::str($row['alt_text']),
            'caption' => self::str($row['caption']),
            'is_featured' => $featured,
            'display_order' => (int) $row['display_order'],
            'metadata' => $row['metadata'] !== null && json_validate((string) $row['metadata']) ? (string) $row['metadata'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /** media_mediametadata rows whose image does not exist cannot be attached to anything: reported as unresolved. */
    public function afterRun(ImportContext $ctx): void
    {
        if (! $ctx->reader->hasTable('media_mediametadata') || ! $ctx->reader->hasTable('media_articleimage')) {
            return;
        }
        $rows = $ctx->reader->select('SELECT m.id FROM media_mediametadata m WHERE NOT EXISTS (SELECT 1 FROM media_articleimage i WHERE i.id = m.image_id) ORDER BY m.id');
        $ctx->report->setEntity('media_metadata', ['source_count' => count($rows)]);
        foreach ($rows as $r) {
            $ctx->skip('media_metadata', (int) $r['id'], 'IMG-META-ORPHAN', 'metadata row references a missing image');
        }
    }
}
