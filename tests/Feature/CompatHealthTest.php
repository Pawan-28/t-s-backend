<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** GET /api/health/ keeps Django's contract: 200 {"status":"ok"} / 503 {"status":"error"} when the DB is unreachable. */
class CompatHealthTest extends TestCase
{
    public function test_health_ok(): void
    {
        $this->getJson('/api/health/')->assertOk()->assertExactJson(['status' => 'ok', 'service' => 'news-portal-backend', 'database' => 'ok']);
        $this->getJson('/api/health')->assertOk();
    }

    public function test_health_503_when_database_is_down(): void
    {
        $original = config('database.connections.mysql.port');
        try {
            config(['database.connections.mysql.port' => 5999]);
            DB::purge('mysql');
            $this->getJson('/api/health/')->assertStatus(503)
                ->assertExactJson(['status' => 'error', 'service' => 'news-portal-backend', 'database' => 'unreachable']);
        } finally {
            config(['database.connections.mysql.port' => $original]);
            DB::purge('mysql');
        }
    }
}
