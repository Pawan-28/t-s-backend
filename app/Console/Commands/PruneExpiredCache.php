<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The `database` cache store only deletes an expired row when that very key is read again, so one-shot keys
 * (per-IP rate-limit counters, view-cooldown markers) would pile up forever. This removes expired rows from
 * the cache and cache_locks tables. No-op for other cache stores.
 */
class PruneExpiredCache extends Command
{
    protected $signature = 'portal:prune-cache';

    protected $description = 'Delete expired rows from the database cache tables (database cache store only)';

    public function handle(): int
    {
        $store = config('cache.stores.'.config('cache.default'));
        $limiter = config('cache.limiter') ? config('cache.stores.'.config('cache.limiter')) : null;
        $usesDb = ($store['driver'] ?? null) === 'database' || ($limiter['driver'] ?? null) === 'database';
        if (! $usesDb) {
            $this->info('Cache store is not "database"; nothing to prune.');

            return self::SUCCESS;
        }

        $conn = DB::connection($store['connection'] ?? null);
        $now = time();
        $cache = $conn->table($store['table'] ?? 'cache')->where('expiration', '<=', $now)->delete();
        $locks = $conn->table($store['lock_table'] ?? 'cache_locks')->where('expiration', '<=', $now)->delete();
        $this->info("Pruned {$cache} expired cache row(s) and {$locks} expired lock row(s).");

        return self::SUCCESS;
    }
}
