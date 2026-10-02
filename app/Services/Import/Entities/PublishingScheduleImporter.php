<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;
use App\Services\Import\Support\Rules;

class PublishingScheduleImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'article_id', 'scheduled_for', 'scheduled_by_id', 'status', 'executed_at', 'created_at', 'updated_at'];

    public function name(): string
    {
        return 'publishing_schedules';
    }

    public function sourceTable(): string
    {
        return 'reporters_publishingschedule';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        if (! $ctx->has('articles', $row['article_id'])) {
            $ctx->skip('publishing_schedules', $id, 'SCH-ARTICLE-ORPHAN', 'article '.$row['article_id'].' does not exist or was not imported');

            return null;
        }
        if (! $ctx->has('users', $row['scheduled_by_id'])) {
            $ctx->skip('publishing_schedules', $id, 'SCH-USER-ORPHAN', 'scheduled_by user '.($row['scheduled_by_id'] ?? 'NULL').' does not exist');

            return null;
        }
        $status = in_array($row['status'], Rules::SCHEDULE_STATUSES, true) ? $row['status'] : Rules::DEFAULT_SCHEDULE_STATUS;
        if (isset($ctx->plan->scheduleCancel[$id])) {
            $status = 'CANCELLED'; // keeps the single-PENDING-per-article partial unique index satisfied
        }

        return [
            'id' => $id,
            'article_id' => (int) $row['article_id'],
            'scheduled_for' => $row['scheduled_for'],
            'scheduled_by_id' => (int) $row['scheduled_by_id'],
            'status' => $status,
            'executed_at' => $row['executed_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
