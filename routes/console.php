<?php

use App\Jobs\CalculateForecastsJob;
use App\Jobs\CalculateSupplyRecommendationsJob;
use App\Jobs\CalculateUnitEconomicsJob;
use App\Jobs\GenerateAlertsJob;
use App\Jobs\GenerateShipmentRecommendationsJob;
use App\Jobs\GenerateSupplyRecommendationsJob;
use App\Jobs\SyncInventoryJob;
use App\Jobs\SyncProductsJob;
use App\Jobs\SyncShipmentStatusJob;
use App\Jobs\SyncSupplyStatusesJob;
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

// Sync unit economics from real API data daily at 04:30 (after products sync)
// Uses artisan command which fetches fresh data from Ozon API
Schedule::command('unit-economics:sync')
    ->dailyAt('04:30')
    ->name('sync_unit_economics_all');

// Fallback: Calculate unit economics for products without API data at 05:00
Schedule::job(new CalculateUnitEconomicsJob())
    ->dailyAt('05:00')
    ->name('calculate_unit_economics_fallback');

// Cleanup stuck synchronizations every 15 minutes
Schedule::command('sync:cleanup --minutes=30')
    ->everyFifteenMinutes()
    ->name('cleanup_stuck_syncs');

// Daily inventory snapshot at 23:55 for history charts
Schedule::command('inventory:snapshot')
    ->dailyAt('23:55')
    ->name('inventory_snapshot');

// Weekly cleanup of old inventory history (keep 90 days)
Schedule::command('inventory:cleanup --days=90')
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->name('inventory_history_cleanup');

// Auto sync products every 6 hours
Schedule::command('sync:auto --type=products')
    ->everySixHours()
    ->name('auto_sync_products');

// Auto sync inventory every 2 hours
Schedule::command('sync:auto --type=inventory')
    ->everyTwoHours()
    ->name('auto_sync_inventory');

// Daily data integrity check at 05:00
Schedule::command('data:check')
    ->dailyAt('05:00')
    ->name('data_integrity_check');

// Generate supply recommendations daily at 06:30 (after inventory sync)
Schedule::job(new GenerateSupplyRecommendationsJob())
    ->dailyAt('06:30')
    ->name('generate_supply_recommendations');

// Sync shipment statuses every 15 minutes (P1)
Schedule::job(new SyncShipmentStatusJob())
    ->everyFifteenMinutes()
    ->name('sync_shipment_statuses');

// Sync warehouse slots every 6 hours
Schedule::job(new \App\Jobs\SyncWarehouseSlotsJob())
    ->everySixHours()
    ->name('sync_warehouse_slots');

/*
|--------------------------------------------------------------------------
| Ozon FBO Supplies Module Jobs
|--------------------------------------------------------------------------
*/

// Calculate supply recommendations every 4 hours for all Ozon integrations
Schedule::call(function () {
    $integrations = \App\Models\Integration::where('marketplace', 'ozon')
        ->where('is_active', true)
        ->pluck('id');
    
    foreach ($integrations as $integrationId) {
        CalculateSupplyRecommendationsJob::dispatch($integrationId);
    }
})->cron('0 */4 * * *')->name('calculate_supply_recommendations');

// Sync supply statuses every 30 minutes for active supplies
Schedule::call(function () {
    $integrations = \App\Models\Integration::where('marketplace', 'ozon')
        ->where('is_active', true)
        ->pluck('id');
    
    foreach ($integrations as $integrationId) {
        SyncSupplyStatusesJob::dispatch($integrationId);
    }
})->everyThirtyMinutes()->name('sync_supply_statuses');

// Calculate supply analytics daily at 07:00
Schedule::command('supplies:analytics')
    ->dailyAt('07:00')
    ->name('calculate_supply_analytics');
