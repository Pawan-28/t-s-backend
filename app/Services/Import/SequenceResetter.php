<?php

namespace App\Services\Import;

use App\Services\Import\Support\TargetSchema;
use Illuminate\Support\Facades\DB;

/**
 * Imported rows carry their Django ids, so every AUTO_INCREMENT counter must be moved past MAX(id).
 * InnoDB already bumps the counter on explicit ids, but an empty table (or a rehearsal that advanced it) must end
 * up at exactly MAX(id)+1, and the report proves it. ALTER TABLE ... AUTO_INCREMENT is DDL: it commits implicitly,
 * so this runs only after the per-entity transactions (never inside a rehearsal transaction).
 */
class SequenceResetter
{
    public const TABLES = ['users', 'industries', 'categories', 'subcategories', 'tags', 'subscription_plans', 'articles', 'article_tag', 'article_images', 'article_reviews', 'publishing_schedules', 'reporter_category_assignments', 'subscriptions', 'payments', 'notifications', 'advertisements', 'article_daily_views', 'ai_analysis_results', 'plagiarism_check_results'];

    public function reset(ImportReport $report, ?array $tables = null): void
    {
        foreach ($tables ?? self::TABLES as $table) {
            $max = DB::table($table)->max('id');
            $next = $max === null ? 1 : ((int) $max) + 1;
            $before = TargetSchema::autoIncrement($table);
            DB::statement('ALTER TABLE `'.str_replace('`', '', $table).'` AUTO_INCREMENT = '.$next);
            $report->sequence($table, 'AUTO_INCREMENT', $next, $before, TargetSchema::autoIncrement($table));
        }
    }
}
