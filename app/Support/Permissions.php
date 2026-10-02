<?php

namespace App\Support;

/**
 * Feature permissions an Admin can grant to a non-admin account (typically a Reporter).
 * ADMIN always holds all of them. Users management itself is deliberately NOT grantable:
 * creating accounts, changing roles/passwords and granting permissions stay Admin-only.
 */
final class Permissions
{
    public const ARTICLES_MANAGE = 'articles.manage';

    public const ARTICLES_PUBLISH = 'articles.publish';

    public const TAXONOMY_MANAGE = 'taxonomy.manage';

    public const REPORTERS_MANAGE = 'reporters.manage';

    public const ADS_MANAGE = 'ads.manage';

    public const SUBSCRIPTIONS_MANAGE = 'subscriptions.manage';

    public const ANALYTICS_VIEW = 'analytics.view';

    public const NOTIFICATIONS_VIEW = 'notifications.view';

    /** @return array<string, array{label: string, group: string, description: string}> */
    public static function catalog(): array
    {
        return [
            self::ARTICLES_MANAGE => ['label' => 'Manage all articles', 'group' => 'Articles', 'description' => 'See, create, edit and delete every article (drafts included), manage images and run AI / plagiarism checks.'],
            self::ARTICLES_PUBLISH => ['label' => 'Review, publish & schedule', 'group' => 'Articles', 'description' => 'Approve, reject, publish, schedule, cancel schedules, start reviews and assign reporters on any article; see the publishing schedule.'],
            self::TAXONOMY_MANAGE => ['label' => 'Manage categories & tags', 'group' => 'Content structure', 'description' => 'Create, edit and delete industries, categories, subcategories and tags.'],
            self::REPORTERS_MANAGE => ['label' => 'Manage reporter category assignments', 'group' => 'Content structure', 'description' => 'Decide which categories each reporter may be assigned articles in.'],
            self::ADS_MANAGE => ['label' => 'Manage advertisements', 'group' => 'Business', 'description' => 'Create, edit, schedule and delete advertisements.'],
            self::SUBSCRIPTIONS_MANAGE => ['label' => 'Manage subscriptions & payments', 'group' => 'Business', 'description' => 'View subscriptions, payments and phone OTPs; manage subscription plans.'],
            self::ANALYTICS_VIEW => ['label' => 'View analytics & AI results', 'group' => 'Insights', 'description' => 'View analytics dashboards, daily views and AI / plagiarism result history.'],
            self::NOTIFICATIONS_VIEW => ['label' => 'View all notifications', 'group' => 'Insights', 'description' => 'Browse every user\'s notifications in the admin panel.'],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::catalog());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::catalog());
    }

    /** Known keys only, de-duplicated, in catalog order. @param  array<int, mixed>|null  $in @return list<string> */
    public static function clean(?array $in): array
    {
        $in = array_map('strval', $in ?? []);

        return array_values(array_filter(self::keys(), fn ($k) => in_array($k, $in, true)));
    }
}
