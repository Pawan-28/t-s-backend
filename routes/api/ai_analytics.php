<?php

use App\Http\Controllers\Api\AiResultsAdminController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ArticleChecksController;
use App\Http\Controllers\Api\PlagiarismWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Agent5 area: OpenAI AI check, Copyleaks plagiarism check, analytics.
| Advisory checks never touch article workflow/status.
*/

// ---- Per-article checks (author / assigned reporter / admin) -------------------------
Route::middleware('api.auth')->group(function () {
    Route::match(['get', 'post'], 'articles/{slug}/ai-check', [ArticleChecksController::class, 'aiCheck'])
        ->where('slug', '[-a-zA-Z0-9_]+')
        ->middleware('throttle:ai-check');
    Route::match(['get', 'post'], 'articles/{slug}/plagiarism-check', [ArticleChecksController::class, 'plagiarismCheck'])
        ->where('slug', '[-a-zA-Z0-9_]+')
        ->middleware('throttle:plagiarism-check');
});

// ---- Copyleaks webhook (unauthenticated; secret token in path, see controller) -------
Route::post('ai/plagiarism-webhook/{token}/{scanId}/{status?}', PlagiarismWebhookController::class)
    ->where(['token' => '[^/]+', 'scanId' => '[A-Za-z0-9_-]+', 'status' => '[A-Za-z0-9_]+']);

// ---- Admin read-only AI / plagiarism result browsing -----------------------------------
Route::prefix('ai')->middleware(['api.auth', 'perm:analytics.view'])->group(function () {
    Route::get('articles', [AiResultsAdminController::class, 'articles']);
    Route::get('analysis-results', [AiResultsAdminController::class, 'analysisIndex']);
    Route::get('analysis-results/{id}', [AiResultsAdminController::class, 'analysisShow'])->whereNumber('id');
    Route::get('plagiarism-results', [AiResultsAdminController::class, 'plagiarismIndex']);
    Route::get('plagiarism-results/{id}', [AiResultsAdminController::class, 'plagiarismShow'])->whereNumber('id');
});

// ---- Analytics ---------------------------------------------------------------------------
Route::prefix('analytics')->group(function () {
    // Public, write-only view event (PUBLISHED articles only).
    Route::post('articles/{slug}/view', [AnalyticsController::class, 'view'])
        ->where('slug', '[-a-zA-Z0-9_]+')
        ->middleware('throttle.scope:article_view');

    Route::middleware(['api.auth', 'perm:analytics.view'])->group(function () {
        Route::get('overview', [AnalyticsController::class, 'overview']);
        Route::get('articles/popular', [AnalyticsController::class, 'popular']);
        Route::get('industries', [AnalyticsController::class, 'industries']);
        Route::get('categories', [AnalyticsController::class, 'categories']);
        Route::get('subcategories', [AnalyticsController::class, 'subcategories']);
        Route::get('reporters', [AnalyticsController::class, 'reporters']);
        Route::get('publishing', [AnalyticsController::class, 'publishing']);
        Route::get('views-over-time', [AnalyticsController::class, 'viewsOverTime']);
        Route::get('admin/article-daily-views', [AnalyticsController::class, 'dailyViews']);
    });
});
