<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Стандартный лимит для API: 60 запросов в минуту
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Строгий лимит для авторизации: 5 попыток в минуту (защита от брутфорса)
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip())->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Слишком много попыток входа. Попробуйте через минуту.',
                ], 429);
            });
        });

        // Лимит для синхронизации: 10 запросов в минуту (тяжёлые операции)
        RateLimiter::for('sync', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip())->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Слишком много запросов на синхронизацию. Попробуйте через минуту.',
                ], 429);
            });
        });

        // Лимит для bulk операций: 20 запросов в минуту
        RateLimiter::for('bulk', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip())->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Слишком много массовых операций. Попробуйте через минуту.',
                ], 429);
            });
        });

        // Лимит для экспорта: 5 запросов в минуту
        RateLimiter::for('export', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip())->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Слишком много запросов на экспорт. Попробуйте через минуту.',
                ], 429);
            });
        });
    }
}
