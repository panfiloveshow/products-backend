<?php

use App\Jobs\CalculateForecastsJob;
use App\Jobs\CalculateUnitEconomicsJob;
use App\Jobs\GenerateAlertsJob;
use App\Jobs\GenerateShipmentRecommendationsJob;
use App\Jobs\SyncInventoryJob;
use App\Jobs\SyncProductsJob;
use App\Models\SyncLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Jobs
|--------------------------------------------------------------------------
*/

// Sync products every 6 hours
Schedule::call(function () {
    foreach (['wildberries', 'ozon', 'yandex'] as $marketplace) {
        $syncLog = SyncLog::create([
            'marketplace' => $marketplace,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_PENDING,
        ]);
        SyncProductsJob::dispatch($syncLog);
    }
})->everySixHours()->name('sync_products_all');

// Sync inventory every 2 hours
Schedule::call(function () {
    foreach (['wildberries', 'ozon', 'yandex'] as $marketplace) {
        $syncLog = SyncLog::create([
            'marketplace' => $marketplace,
            'sync_type' => 'inventory',
            'status' => SyncLog::STATUS_PENDING,
        ]);
        SyncInventoryJob::dispatch($syncLog);
    }
})->everyTwoHours()->name('sync_inventory_all');

// Calculate forecasts daily at 03:00
Schedule::job(new CalculateForecastsJob())
    ->dailyAt('03:00')
    ->name('calculate_forecasts');

// Generate alerts every 2 hours
Schedule::job(new GenerateAlertsJob())
    ->everyTwoHours()
    ->name('generate_alerts');

// Generate shipment recommendations daily at 06:00
Schedule::job(new GenerateShipmentRecommendationsJob())
    ->dailyAt('06:00')
    ->name('generate_shipment_recommendations');

// Calculate unit economics daily at 04:00
Schedule::job(new CalculateUnitEconomicsJob())
    ->dailyAt('04:00')
    ->name('calculate_unit_economics');
