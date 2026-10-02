<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;

class SubcategoryImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'category_id', 'name', 'slug', 'description', 'is_active', 'display_order', 'created_at', 'updated_at'];

    public function name(): string
    {
        return 'subcategories';
    }

    public function sourceTable(): string
    {
        return 'categories_subcategory';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        if (! $ctx->has('categories', $row['category_id'])) {
            $ctx->skip('subcategories', $id, 'TAX-SUB-CATEGORY-ORPHAN', 'category '.($row['category_id'] ?? 'NULL').' does not exist (or was not imported)');

            return null;
        }
        $ctx->subcategoryCategory[$id] = (int) $row['category_id'];

        return [
            'id' => $id,
            'category_id' => (int) $row['category_id'],
            'name' => $row['name'],
            'slug' => $ctx->plan->slug('subcategories', $id, $row['slug']),
            'description' => self::str($row['description']),
            'display_order' => (int) $row['display_order'],
            'is_active' => self::bool($row['is_active']),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
