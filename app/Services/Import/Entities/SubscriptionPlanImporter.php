<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;

class SubscriptionPlanImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'name', 'slug', 'description', 'price_amount', 'price_currency', 'duration_days', 'is_active', 'created_at', 'updated_at'];

    public function name(): string
    {
        return 'subscription_plans';
    }

    public function sourceTable(): string
    {
        return 'subscriptions_subscriptionplan';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];

        return [
            'id' => $id,
            'name' => $row['name'],
            'slug' => $ctx->plan->slug('subscription_plans', $id, $row['slug']),
            'description' => self::str($row['description']),
            'price_amount' => $row['price_amount'],
            'price_currency' => $row['price_currency'],
            'duration_days' => (int) $row['duration_days'],
            'is_active' => self::bool($row['is_active']),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
