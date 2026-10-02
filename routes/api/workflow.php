<?php

use App\Http\Controllers\Api\Workflow\ArticleWorkflowController;
use App\Http\Controllers\Api\Workflow\PublishingScheduleController;
use App\Http\Controllers\Api\Workflow\ReporterAssignmentController;
use Illuminate\Support\Facades\Route;

/*
| Editorial workflow (Django apps.articles ArticleViewSet actions + apps.reporters).
| Authentication is required everywhere (401 otherwise); authorization is enforced by
| ArticleWorkflowService::authorize() / ArticlePolicy.
*/
$slug = '[^/.]+';

Route::middleware('api.auth')->group(function () use ($slug) {
    Route::prefix('articles/{slug}')->where(['slug' => $slug])->group(function () {
        Route::post('submit', [ArticleWorkflowController::class, 'submit']);
        Route::post('start-review', [ArticleWorkflowController::class, 'startReview']);
        Route::post('request-changes', [ArticleWorkflowController::class, 'requestChanges']);
        Route::post('reject', [ArticleWorkflowController::class, 'reject']);
        Route::post('approve', [ArticleWorkflowController::class, 'approve']);
        Route::post('publish', [ArticleWorkflowController::class, 'publish']);
        Route::post('schedule', [ArticleWorkflowController::class, 'schedule']);
        Route::post('cancel-schedule', [ArticleWorkflowController::class, 'cancelSchedule']);
        Route::post('assign-reporter', [ArticleWorkflowController::class, 'assignReporter']);
        Route::get('review-history', [ArticleWorkflowController::class, 'reviewHistory']);
    });

    Route::prefix('reporters')->group(function () {
        Route::get('schedules', [PublishingScheduleController::class, 'index'])->middleware('perm:articles.publish');

        Route::middleware('perm:reporters.manage')->group(function () {
            Route::get('assignments', [ReporterAssignmentController::class, 'index']);
            Route::post('assignments', [ReporterAssignmentController::class, 'store']);
            Route::get('assignments/{id}', [ReporterAssignmentController::class, 'show'])->whereNumber('id');
            Route::match(['put', 'patch'], 'assignments/{id}', [ReporterAssignmentController::class, 'update'])->whereNumber('id');
            Route::delete('assignments/{id}', [ReporterAssignmentController::class, 'destroy'])->whereNumber('id');
        });
    });
});
