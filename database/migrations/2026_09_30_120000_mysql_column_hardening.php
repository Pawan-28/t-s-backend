<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 8 / MariaDB strict-mode hardening that the PostgreSQL schema got for free:
 *
 *  1. Opaque identifiers compare BYTE-exact (utf8mb4_bin) instead of case/accent-insensitive
 *     (utf8mb4_unicode_ci): Razorpay order/payment ids are case-sensitive base62, scan ids and hashed
 *     API tokens must never match a differently-cased twin. (PAD SPACE still applies to every MySQL
 *     collation; these values are never space-padded.)
 *  2. TEXT holds only 65,535 BYTES, but the API accepts up to 1,000,000 characters of excerpt and
 *     100,000 characters of taxonomy descriptions (up to 4 bytes each), so a strict-mode INSERT would fail
 *     with "Data too long" (HTTP 500). Those columns become MEDIUMTEXT (16 MB).
 *
 * Plain ALTER ... MODIFY statements: identical on MySQL 8.0 and MariaDB 10.x.
 */
return new class extends Migration
{
    private const BINARY = [
        ['payments', 'razorpay_order_id', 'VARCHAR(100)', 'NOT NULL'],
        ['payments', 'razorpay_payment_id', 'VARCHAR(100)', "NOT NULL DEFAULT ''"],
        ['plagiarism_check_results', 'scan_id', 'VARCHAR(100)', 'NOT NULL'],
        ['personal_access_tokens', 'token', 'VARCHAR(64)', 'NOT NULL'],
    ];

    private const MEDIUMTEXT = [
        ['articles', 'excerpt'],
        ['article_search_index', 'excerpt'],
        ['article_search_index', 'taxonomy'],
        ['industries', 'description'],
        ['categories', 'description'],
        ['subcategories', 'description'],
        ['subscription_plans', 'description'],
    ];

    public function up(): void
    {
        foreach (self::BINARY as [$table, $column, $type, $attrs]) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$type} CHARACTER SET utf8mb4 COLLATE utf8mb4_bin {$attrs}");
        }
        foreach (self::MEDIUMTEXT as [$table, $column]) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` MEDIUMTEXT NOT NULL");
        }
    }

    public function down(): void
    {
        foreach (self::BINARY as [$table, $column, $type, $attrs]) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$type} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci {$attrs}");
        }
        // MEDIUMTEXT -> TEXT could truncate data: intentionally not reverted.
    }
};
