<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;
use App\Services\Import\Support\Rules;

/**
 * subscriptions_subscription -> subscriptions. PENDING rows are imported AS-IS
 * (status PENDING) - no state is invented; the validator reports them.
 */
class SubscriptionImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'user_id', 'plan_id', 'status', 'started_at', 'expires_at', 'contact_email', 'contact_phone', 'expiring_notified_at', 'expired_notified_at', 'created_at', 'updated_at'];

    public function name(): string
    {
        return 'subscriptions';
    }

    public function sourceTable(): string
    {
        return 'subscriptions_subscription';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        if (! $ctx->has('users', $row['user_id'])) {
            $ctx->skip('subscriptions', $id, 'SUB-USER-ORPHAN', 'user '.($row['user_id'] ?? 'NULL').' does not exist');

            return null;
        }
        if (! $ctx->has('subscription_plans', $row['plan_id'])) {
            $ctx->skip('subscriptions', $id, 'SUB-PLAN-ORPHAN', 'plan '.($row['plan_id'] ?? 'NULL').' does not exist');

            return null;
        }

        return [
            'id' => $id,
            'user_id' => (int) $row['user_id'],
            'plan_id' => (int) $row['plan_id'],
            'status' => in_array($row['status'], Rules::SUBSCRIPTION_STATUSES, true) ? $row['status'] : Rules::DEFAULT_SUBSCRIPTION_STATUS,
            'started_at' => $row['started_at'],
            'expires_at' => $row['expires_at'],
            'contact_email' => self::str($row['contact_email']),
            'contact_phone' => self::str($row['contact_phone']),
            'expiring_notified_at' => $row['expiring_notified_at'],
            'expired_notified_at' => $row['expired_notified_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
