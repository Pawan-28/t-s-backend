<?php

use App\Http\Controllers\Api\Taxonomy\CategoryController;
use App\Http\Controllers\Api\Taxonomy\IndustryController;
use App\Http\Controllers\Api\Taxonomy\SubcategoryController;
use App\Http\Controllers\Api\Taxonomy\TagController;
use Illuminate\Support\Facades\Route;

/*
| Taxonomy (Industry -> Category -> Subcategory, Tag). Django ModelViewSets with
| pagination disabled: reads are public (plain arrays), writes require auth and
| are admin-only (tags: reporters may also POST). Industry/category/tag detail
| routes use the slug; subcategory detail routes use the numeric id.
*/
$slug = '[^/.]+';

foreach ([
    'industries' => [IndustryController::class, 'slug', $slug],
    'categories' => [CategoryController::class, 'slug', $slug],
    'subcategories' => [SubcategoryController::class, 'id', '[0-9]+'],
    'tags' => [TagController::class, 'slug', $slug],
] as $prefix => [$controller, $param, $pattern]) {
    Route::get($prefix, [$controller, 'index']);
    Route::post($prefix, [$controller, 'store'])->middleware('api.auth');

    Route::get("$prefix/{{$param}}", [$controller, 'show'])->where($param, $pattern);
    Route::match(['put', 'patch'], "$prefix/{{$param}}", [$controller, 'update'])->where($param, $pattern)->middleware('api.auth');
    Route::delete("$prefix/{{$param}}", [$controller, 'destroy'])->where($param, $pattern)->middleware('api.auth');

    if ($prefix !== 'tags') {
        Route::post("$prefix/{{$param}}/activate", [$controller, 'activate'])->where($param, $pattern)->middleware('api.auth');
        Route::post("$prefix/{{$param}}/deactivate", [$controller, 'deactivate'])->where($param, $pattern)->middleware('api.auth');
    }
}
