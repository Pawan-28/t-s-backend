<?php

use App\Http\Controllers\Api\ArticleController;
use Illuminate\Support\Facades\Route;

/*
| Articles CRUD + related. Reads are public (visibility scoped by role inside the
| controller); writes need an authenticated admin/reporter. The static reporter
| lists /articles/mine and /articles/assigned and every /articles/{slug}/<action>
| workflow route are owned by the workflow files (01_workflow_lists.php loads first).
*/
$slug = '[^/.]+';

Route::get('articles', [ArticleController::class, 'index']);
Route::post('articles', [ArticleController::class, 'store'])->middleware('api.auth');

Route::get('articles/{slug}', [ArticleController::class, 'show'])->where('slug', $slug);
Route::match(['put', 'patch'], 'articles/{slug}', [ArticleController::class, 'update'])->where('slug', $slug)->middleware('api.auth');
Route::delete('articles/{slug}', [ArticleController::class, 'destroy'])->where('slug', $slug)->middleware('api.auth');

Route::get('articles/{slug}/related', [ArticleController::class, 'related'])->where('slug', $slug);
