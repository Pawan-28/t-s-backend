<?php

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('notifications')->middleware('api.auth')->group(function () {
    // Static/admin routes first, then numeric {id}.
    Route::middleware('perm:notifications.view')->prefix('admin')->group(function () {
        Route::get('/', [NotificationController::class, 'adminIndex']);
        Route::get('{id}', [NotificationController::class, 'adminShow'])->whereNumber('id');
        Route::post('{id}/mark-read', [NotificationController::class, 'adminMarkRead'])->whereNumber('id');
    });

    Route::get('unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('mark-all-read', [NotificationController::class, 'markAllRead']);

    Route::get('/', [NotificationController::class, 'index']);
    Route::get('{id}', [NotificationController::class, 'show'])->whereNumber('id');
    Route::post('{id}/mark-read', [NotificationController::class, 'markRead'])->whereNumber('id');
});
