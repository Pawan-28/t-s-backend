<?php

use Illuminate\Support\Facades\Route;

/*
| Every URL keeps the Django path (e.g. /api/articles/mine/). Laravel matches
| with or without the trailing slash, so the frontend needs no change.
| Domain route files live in routes/api/*.php and are loaded alphabetically.
| Rule for each file: register static paths BEFORE {slug}/{id} wildcards.
*/
Route::get('health', function () {
    // Same contract as Django's HealthCheckView: 200 {"status":"ok"} or 503 {"status":"error"} when the DB is down.
    $db = rescue(fn () => (bool) DB::selectOne('select 1 as ok') ? 'ok' : 'unreachable', 'unreachable', false);

    return response()->json([
        'status' => $db === 'ok' ? 'ok' : 'error',
        'service' => 'news-portal-backend',
        'database' => $db,
    ], $db === 'ok' ? 200 : 503);
});

foreach (glob(__DIR__.'/api/*.php') as $file) {
    require $file;
}
