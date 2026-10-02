<?php

namespace App\Providers;

use App\Console\Commands\ExpireSubscriptions;
use App\Console\Commands\NotifySubscribersOfPublishedArticles;
use App\Console\Commands\RemindExpiringSubscriptions;
use App\Services\Subscriptions\RazorpayClient;
use App\Services\Wati\WatiClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class SubscriptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RazorpayClient::class);
        $this->app->singleton(WatiClient::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('subscriptions:expire')->hourly()->withoutOverlapping(30)->onOneServer();
            $schedule->command('subscriptions:remind-expiring')->hourly()->withoutOverlapping(30)->onOneServer();
            $schedule->command('subscriptions:notify-published')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireSubscriptions::class, RemindExpiringSubscriptions::class, NotifySubscribersOfPublishedArticles::class]);
        }
    }
}
