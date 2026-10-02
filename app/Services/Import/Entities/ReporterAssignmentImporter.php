<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;

class ReporterAssignmentImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'reporter_id', 'category_id', 'assigned_by_id', 'created_at'];

    /** @var array<string, true> */
    private array $seen = [];

    public function name(): string
    {
        return 'reporter_assignments';
    }

    public function sourceTable(): string
    {
        return 'reporters_reportercategoryassignment';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        if (! $ctx->has('users', $row['reporter_id'])) {
            $ctx->skip('reporter_assignments', $id, 'RCA-REPORTER-ORPHAN', 'reporter '.($row['reporter_id'] ?? 'NULL').' does not exist');

            return null;
        }
        if (! $ctx->has('categories', $row['category_id'])) {
            $ctx->skip('reporter_assignments', $id, 'RCA-CATEGORY-ORPHAN', 'category '.($row['category_id'] ?? 'NULL').' does not exist');

            return null;
        }
        $key = $row['reporter_id'].':'.$row['category_id'];
        if (isset($this->seen[$key])) {
            $ctx->skip('reporter_assignments', $id, 'RCA-DUP', 'duplicate (reporter, category) pair');

            return null;
        }
        $this->seen[$key] = true;

        return [
            'id' => $id,
            'reporter_id' => (int) $row['reporter_id'],
            'category_id' => (int) $row['category_id'],
            'assigned_by_id' => $row['assigned_by_id'] !== null && $ctx->has('users', $row['assigned_by_id']) ? (int) $row['assigned_by_id'] : null,
            'created_at' => $row['created_at'],
        ];
    }
}
