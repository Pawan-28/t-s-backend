<?php

use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\SubscriptionAdminController;
use App\Http\Controllers\Api\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('subscriptions')->group(function () {
    // Public
    Route::get('plans', [SubscriptionController::class, 'plans']);
    // Razorpay server-to-server: no user auth, authenticated by X-Razorpay-Signature over the raw body.
    Route::post('webhook', [SubscriptionController::class, 'webhook']);

    Route::middleware('api.auth')->group(function () {
        Route::post('checkout', [SubscriptionController::class, 'checkout'])->middleware('throttle.scope:checkout');
        Route::post('verify', [SubscriptionController::class, 'verify'])->middleware('throttle.scope:verify_payment');
        Route::get('me', [SubscriptionController::class, 'me']);

        Route::post('otp/request', [OtpController::class, 'request'])->middleware('throttle.scope:otp_request_burst,otp_request');
        Route::post('otp/verify', [OtpController::class, 'verify'])->middleware('throttle.scope:otp_verify_burst,otp_verify');

        Route::middleware('perm:subscriptions.manage')->group(function () {
            Route::get('active-subscribers', [SubscriptionController::class, 'activeSubscribers']);
            Route::get('admin/list', [SubscriptionAdminController::class, 'subscriptions']);
            Route::get('admin/payments', [SubscriptionAdminController::class, 'payments']);
            Route::get('admin/otps', [SubscriptionAdminController::class, 'otps']);

            Route::get('admin/plans', [SubscriptionAdminController::class, 'plans']);
            Route::post('admin/plans', [SubscriptionAdminController::class, 'storePlan']);
            Route::get('admin/plans/{plan}', [SubscriptionAdminController::class, 'showPlan'])->whereNumber('plan');
            Route::match(['put', 'patch'], 'admin/plans/{plan}', [SubscriptionAdminController::class, 'updatePlan'])->whereNumber('plan');
            Route::delete('admin/plans/{plan}', [SubscriptionAdminController::class, 'destroyPlan'])->whereNumber('plan');
        });
    });
});
