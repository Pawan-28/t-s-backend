<?php

use App\Http\Controllers\Api\AdvertisementController;
use Illuminate\Support\Facades\Route;

/*
| Advertisements (Django apps.advertisements). Static `active` BEFORE `{id}`.
| Public: GET active/?placement=. Admin (role ADMIN): list/create/show/update/delete.
| Placements: HOME_TOP (kept), HOME_MIDDLE, HOME_SIDEBAR, HOME_BOTTOM, ARTICLE_TOP|MIDDLE|BOTTOM.
*/
Route::prefix('advertisements')->group(function () {
    Route::get('active', [AdvertisementController::class, 'active']);

    Route::middleware(['api.auth', 'perm:ads.manage'])->group(function () {
        Route::get('/', [AdvertisementController::class, 'index']);
        Route::post('/', [AdvertisementController::class, 'store']);
        Route::get('{id}', [AdvertisementController::class, 'show'])->whereNumber('id');
        Route::match(['put', 'patch'], '{id}', [AdvertisementController::class, 'update'])->whereNumber('id');
        Route::delete('{id}', [AdvertisementController::class, 'destroy'])->whereNumber('id');
    });
});
