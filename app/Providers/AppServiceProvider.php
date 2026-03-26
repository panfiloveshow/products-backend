<?php

namespace App\Providers;

use App\Console\Commands\RouteListCommand as AppRouteListCommand;
use App\Console\Commands\ScheduleListCommand as AppScheduleListCommand;
use App\Console\Commands\ScheduleRunCommand as AppScheduleRunCommand;
use Illuminate\Console\Scheduling\ScheduleListCommand;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Foundation\Console\RouteListCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RouteListCommand::class, function ($app) {
            return new AppRouteListCommand($app['router']);
        });

        $this->app->singleton(ScheduleListCommand::class, function () {
            return new AppScheduleListCommand;
        });

        $this->app->singleton(ScheduleRunCommand::class, function () {
            return new AppScheduleRunCommand;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
