<?php

use App\Http\Controllers\Api\AccountsAdminController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle.scope:auth_register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle.scope:auth_login_ip,auth_login_identity,auth_login_account');
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::middleware('api.auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::prefix('accounts')->middleware(['api.auth', 'role:ADMIN'])->group(function () {
    Route::get('users', [AccountsAdminController::class, 'users']);
    Route::post('users', [AccountsAdminController::class, 'storeUser']);
    Route::get('user-permissions', [AccountsAdminController::class, 'permissionCatalog']);
    Route::get('users/{user}', [AccountsAdminController::class, 'showUser'])->whereNumber('user');
    Route::match(['put', 'patch'], 'users/{user}', [AccountsAdminController::class, 'updateUser'])->whereNumber('user');

    Route::get('groups', [AccountsAdminController::class, 'groups']);
    Route::match(['post', 'put', 'patch', 'delete'], 'groups/{any?}', [AccountsAdminController::class, 'groupsUnsupported'])->where('any', '.*');
    Route::get('permissions', [AccountsAdminController::class, 'permissions']);

    Route::get('security/outstanding-tokens', [AccountsAdminController::class, 'outstandingTokens']);
    Route::get('security/outstanding-tokens/{id}', [AccountsAdminController::class, 'outstandingToken'])->whereNumber('id');
    Route::get('security/blacklisted-tokens', [AccountsAdminController::class, 'blacklistedTokens']);
});
