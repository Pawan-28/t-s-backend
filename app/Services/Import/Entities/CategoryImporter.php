<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;

class CategoryImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'industry_id', 'name', 'slug', 'description', 'image_url', 'image_storage_path', 'is_active', 'created_at', 'updated_at'];

    public function name(): string
    {
        return 'categories';
    }

    public function sourceTable(): string
    {
        return 'categories_category';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        // Legacy rows without (or with a dangling) industry keep industry_id NULL - nothing is fabricated.
        $industry = $row['industry_id'] !== null && $ctx->has('industries', $row['industry_id']) ? (int) $row['industry_id'] : null;

        return [
            'id' => $id,
            'industry_id' => $industry,
            'name' => $ctx->plan->value('categories', 'name', $id, $row['name']),
            'slug' => $ctx->plan->slug('categories', $id, $row['slug']),
            'description' => self::str($row['description']),
            'image_url' => $row['image_url'],
            'image_storage_path' => $row['image_storage_path'],
            'is_active' => self::bool($row['is_active']),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
