<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;
use App\Services\Import\Support\Rules;

class NotificationImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'recipient_id', 'article_id', 'notification_type', 'message', 'is_read', 'created_at'];

    public function name(): string
    {
        return 'notifications';
    }

    public function sourceTable(): string
    {
        return 'notifications_notification';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        if (! $ctx->has('users', $row['recipient_id'])) {
            $ctx->skip('notifications', $id, 'NTF-RECIPIENT-ORPHAN', 'recipient '.($row['recipient_id'] ?? 'NULL').' does not exist');

            return null;
        }
        if (! in_array($row['notification_type'], Rules::notificationTypes(), true)) {
            $ctx->skip('notifications', $id, 'NTF-TYPE-INVALID', 'notification_type is not a known value');

            return null;
        }

        return [
            'id' => $id,
            'recipient_id' => (int) $row['recipient_id'],
            'article_id' => $row['article_id'] !== null && $ctx->has('articles', $row['article_id']) ? (int) $row['article_id'] : null,
            'notification_type' => $row['notification_type'],
            'message' => (string) $row['message'],
            'is_read' => self::bool($row['is_read']),
            'created_at' => $row['created_at'],
        ];
    }
}
