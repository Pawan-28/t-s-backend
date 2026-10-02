<?php

use App\Http\Controllers\Api\ArticleImageController;
use Illuminate\Support\Facades\Route;

/*
| Article images (Bunny CDN). Django: /api/articles/<slug>/images/[<id>/]
| List is a plain JSON array. `replace` is an addition (POST multipart `image`).
*/
Route::prefix('articles/{slug}/images')->where(['slug' => '[-a-zA-Z0-9_]+', 'image' => '[0-9]+'])->group(function () {
    Route::get('/', [ArticleImageController::class, 'index']);
    Route::get('{image}', [ArticleImageController::class, 'show']);

    Route::middleware('api.auth')->group(function () {
        Route::post('/', [ArticleImageController::class, 'store'])->middleware('throttle.scope:upload');
        Route::post('{image}/replace', [ArticleImageController::class, 'replace'])->middleware('throttle.scope:upload');
        Route::match(['put', 'patch'], '{image}', [ArticleImageController::class, 'update']);
        Route::delete('{image}', [ArticleImageController::class, 'destroy']);
    });
});
