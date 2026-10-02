<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;

class IndustryImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'name', 'slug', 'description', 'is_active', 'display_order', 'created_at', 'updated_at'];

    public function name(): string
    {
        return 'industries';
    }

    public function sourceTable(): string
    {
        return 'categories_industry';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];

        return [
            'id' => $id,
            'name' => $ctx->plan->value('industries', 'name', $id, $row['name']),
            'slug' => $ctx->plan->slug('industries', $id, $row['slug']),
            'description' => self::str($row['description']),
            'is_active' => self::bool($row['is_active']),
            'display_order' => (int) $row['display_order'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
