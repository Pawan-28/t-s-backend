<?php

use App\Providers\AnalyticsAiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ArticleServiceProvider;
use App\Providers\MediaServiceProvider;
use App\Providers\OpsServiceProvider;
use App\Providers\SubscriptionServiceProvider;
use App\Providers\WorkflowServiceProvider;

return [
    AppServiceProvider::class,
    ArticleServiceProvider::class,
    WorkflowServiceProvider::class,
    SubscriptionServiceProvider::class,
    MediaServiceProvider::class,
    AnalyticsAiServiceProvider::class,
    OpsServiceProvider::class,
];
