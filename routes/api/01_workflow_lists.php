<?php

use App\Http\Controllers\Api\Workflow\ArticleWorkflowController;
use Illuminate\Support\Facades\Route;

/*
| Reporter-facing lists. Deliberately loaded BEFORE every other routes/api/*.php file (numeric prefix) so the
| static paths /api/articles/mine and /api/articles/assigned are registered ahead of any /api/articles/{slug}
| wildcard from the articles area. All other workflow routes live in workflow.php.
*/
Route::middleware('api.auth')->prefix('articles')->group(function () {
    Route::get('mine', [ArticleWorkflowController::class, 'mine']);
    Route::get('assigned', [ArticleWorkflowController::class, 'assigned']);
});
