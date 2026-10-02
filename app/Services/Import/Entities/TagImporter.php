<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;

class TagImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'name', 'slug', 'created_at'];

    public function name(): string
    {
        return 'tags';
    }

    public function sourceTable(): string
    {
        return 'categories_tag';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];

        return [
            'id' => $id,
            'name' => $ctx->plan->value('tags', 'name', $id, $row['name']),
            'slug' => $ctx->plan->slug('tags', $id, $row['slug']),
            'created_at' => $row['created_at'],
        ];
    }
}
