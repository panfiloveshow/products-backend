<?php

use App\Jobs\CalculateForecastsJob;
use App\Jobs\CalculateUnitEconomicsJob;
use App\Jobs\GenerateAlertsJob;
use App\Jobs\GenerateShipmentRecommendationsJob;
use App\Jobs\SyncInventoryJob;
use App\Jobs\SyncProductsJob;
use App\Jobs\SyncSalesJob;
use App\Models\Integration;
use App\Models\SyncLog;
use App\Support\SyncStartGuard;
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
    if (! \Illuminate\Support\Facades\Schema::hasTable('integrations')) {
        return;
    }
    $integrations = Integration::active()->autoSyncEnabled()->get();
    foreach ($integrations as $integration) {
        $running = SyncLog::where('integration_id', $integration->id)
            ->where('sync_type', 'products')
            ->running()
            ->exists();
        if ($running) {
            continue;
        }
        $syncLog = SyncLog::create([
            'marketplace' => SyncStartGuard::storageMarketplace((string) $integration->marketplace),
            'integration_id' => $integration->id,
            'sync_type' => 'products',
            'status' => SyncLog::STATUS_PENDING,
            'credentials' => $integration->getDecryptedCredentials(),
        ]);
        SyncProductsJob::dispatch($syncLog);
    }
})->everySixHours()->name('sync_products_all');

// Sync inventory every 2 hours
Schedule::call(function () {
    if (! \Illuminate\Support\Facades\Schema::hasTable('integrations')) {
        return;
    }
    $integrations = Integration::active()->autoSyncEnabled()->get();
    foreach ($integrations as $integration) {
        $running = SyncLog::where('integration_id', $integration->id)
            ->where('sync_type', 'inventory')
            ->running()
            ->exists();
        if ($running) {
            continue;
        }
        $syncLog = SyncLog::create([
            'marketplace' => SyncStartGuard::storageMarketplace((string) $integration->marketplace),
            'integration_id' => $integration->id,
            'sync_type' => 'inventory',
            'status' => SyncLog::STATUS_PENDING,
            'credentials' => $integration->getDecryptedCredentials(),
        ]);
        SyncInventoryJob::dispatch($syncLog);
    }
})->everyTwoHours()->name('sync_inventory_all');

// Sync sales every 3 hours (через 1 час после inventory чтобы данные уже обновились)
Schedule::job(new SyncSalesJob)
    ->cron('0 1,4,7,10,13,16,19,22 * * *')
    ->name('sync_sales_all')
    ->withoutOverlapping();

// Calculate forecasts daily at 03:00
Schedule::job(new CalculateForecastsJob)
    ->dailyAt('03:00')
    ->name('calculate_forecasts');

// Generate alerts every 2 hours
Schedule::job(new GenerateAlertsJob)
    ->everyTwoHours()
    ->name('generate_alerts');

// Generate shipment recommendations daily at 06:00
Schedule::job(new GenerateShipmentRecommendationsJob)
    ->dailyAt('06:00')
    ->name('generate_shipment_recommendations');

// Calculate unit economics daily at 04:00
Schedule::job(new CalculateUnitEconomicsJob)
    ->dailyAt('04:00')
    ->name('calculate_unit_economics');
