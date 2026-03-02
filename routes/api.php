<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\AutoSupplyPlanController;
use App\Http\Controllers\Api\CostPriceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\UnitEconomicsController;
use App\Http\Controllers\Api\UnitEconomicsCacheController;
use App\Http\Controllers\Api\SellerStockController;
use App\Http\Controllers\Api\WbBarcodeCostController;
use App\Http\Controllers\Api\WbWebhookController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\OzonOrderReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Integrations Module
|--------------------------------------------------------------------------
*/
Route::prefix('integrations')->middleware('sellico.permission')->group(function () {
    Route::get('/', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::get('/{id}/premium-status', [IntegrationController::class, 'getPremiumStatus'])->name('integrations.premiumStatus');
    Route::post('/{id}/sync', [IntegrationController::class, 'sync'])->name('integrations.sync');
    Route::get('/{id}/sync-status', [IntegrationController::class, 'syncStatus'])->name('integrations.syncStatus');
});

/*
|--------------------------------------------------------------------------
| Auth & Integrations (Sellico API)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/workspaces', [AuthController::class, 'workspaces']);
    Route::get('/workspaces/{workspaceId}/integrations', [AuthController::class, 'integrations']);
});

/*
|--------------------------------------------------------------------------
| Products Module
|--------------------------------------------------------------------------
*/
Route::prefix('products')->middleware('sellico.permission')->group(function () {
    Route::get('/', [ProductController::class, 'index'])->name('products.index');
    Route::get('/sync/status', [ProductController::class, 'syncStatus'])->name('products.syncStatus');
    Route::post('/sync/{marketplace}', [ProductController::class, 'sync'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex'])
        ->name('products.sync');
    Route::get('/cost-price', [CostPriceController::class, 'index']);
    Route::post('/cost-price/upload', [CostPriceController::class, 'upload']);
    Route::post('/cost-price/bulk', [CostPriceController::class, 'bulk']);
    Route::get('/cost-price/template', [CostPriceController::class, 'template']);
    Route::get('/{id}', [ProductController::class, 'show'])->name('products.show');
    Route::post('/', [ProductController::class, 'store'])->name('products.store');
    Route::put('/{id}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/{id}', [ProductController::class, 'destroy'])->name('products.destroy');
});

/*
|--------------------------------------------------------------------------
| Inventory Module
|--------------------------------------------------------------------------
*/
Route::prefix('inventory')->middleware('sellico.permission')->group(function () {
    Route::get('/', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('/sync/status', [InventoryController::class, 'syncStatus'])->name('inventory.syncStatus');
    Route::get('/alerts', [InventoryController::class, 'alerts'])->name('inventory.alerts');
    Route::get('/matrix', [InventoryController::class, 'matrix'])->name('inventory.matrix');
    Route::get('/recommendations', [InventoryController::class, 'recommendations'])->name('inventory.recommendations');
    Route::get('/redistribution', [InventoryController::class, 'redistribution'])->name('inventory.redistribution');
    Route::get('/stats', [InventoryController::class, 'stats'])->name('inventory.stats');
    Route::post('/sync/{marketplace}', [InventoryController::class, 'sync'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex'])
        ->name('inventory.sync');
    Route::post('/sync-storage-fees', [InventoryController::class, 'syncStorageFees'])->name('inventory.syncStorageFees');
    Route::get('/{sku}', [InventoryController::class, 'show'])->name('inventory.show');
    Route::get('/{sku}/history', [InventoryController::class, 'history'])->name('inventory.history');
    Route::get('/{sku}/forecast', [InventoryController::class, 'forecast'])->name('inventory.forecast');
});

/*
|--------------------------------------------------------------------------
| Shipments Module
|--------------------------------------------------------------------------
*/
Route::prefix('shipments')->middleware('sellico.permission')->group(function () {
    Route::get('/', [ShipmentController::class, 'index'])->name('shipments.index');
    Route::get('/slots', [ShipmentController::class, 'slots'])->name('shipments.slots');
    Route::get('/recommendations', [ShipmentController::class, 'recommendations'])->name('shipments.recommendations');
    Route::get('/stats', [ShipmentController::class, 'stats'])->name('shipments.stats');
    Route::post('/from-recommendation/{recommendationId}', [ShipmentController::class, 'createFromRecommendation']);
    
    Route::get('/{id}', [ShipmentController::class, 'show'])->name('shipments.show');
    Route::post('/', [ShipmentController::class, 'store'])->name('shipments.store');
    Route::put('/{id}', [ShipmentController::class, 'update'])->name('shipments.update');
    Route::delete('/{id}', [ShipmentController::class, 'destroy'])->name('shipments.destroy');
    
    // Items management
    Route::post('/{id}/items', [ShipmentController::class, 'addItem'])->name('shipments.addItem');
    Route::put('/{id}/items/{itemId}', [ShipmentController::class, 'updateItem'])->name('shipments.updateItem');
    Route::delete('/{id}/items/{itemId}', [ShipmentController::class, 'removeItem'])->name('shipments.removeItem');
    
    // Workflow
    Route::post('/{id}/submit', [ShipmentController::class, 'submit'])->name('shipments.submit');
    Route::post('/{id}/approve', [ShipmentController::class, 'approve'])->name('shipments.approve');
    Route::post('/{id}/reject', [ShipmentController::class, 'reject'])->name('shipments.reject');
    Route::post('/{id}/send', [ShipmentController::class, 'send'])->name('shipments.send');
    Route::post('/{id}/deliver', [ShipmentController::class, 'deliver'])->name('shipments.deliver');
    
    // Slots
    Route::post('/{id}/book-slot', [ShipmentController::class, 'bookSlot'])->name('shipments.bookSlot');
    
    // Export
    Route::get('/{id}/export/pdf', [ShipmentController::class, 'exportPdf'])->name('shipments.export');
    Route::get('/{id}/export/csv', [ShipmentController::class, 'exportCsv']);
});

/*
|--------------------------------------------------------------------------
| Unit Economics Module
|--------------------------------------------------------------------------
*/
Route::prefix('unit-economics')->middleware('sellico.permission')->group(function () {
    Route::get('/comparison', [UnitEconomicsCacheController::class, 'comparison'])
        ->name('unit-economics.comparison');
    Route::get('/stats', [UnitEconomicsCacheController::class, 'stats'])
        ->name('unit-economics.stats');

    Route::get('/commissions/{marketplace}', [UnitEconomicsCacheController::class, 'commissions'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market'])
        ->name('unit-economics.commissions');
    Route::get('/tariffs/{marketplace}', [UnitEconomicsCacheController::class, 'tariffs'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market'])
        ->name('unit-economics.tariffs');
    Route::get('/stats/{marketplace}', [UnitEconomicsCacheController::class, 'statsByMarketplace'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);

    // Settings (PUT) — должны быть до /{marketplace} чтобы не конфликтовать
    Route::put('/settings/bulk', [UnitEconomicsCacheController::class, 'bulkUpdateSettings'])
        ->name('unit-economics.settings.bulk');
    Route::put('/settings/{sku}', [UnitEconomicsCacheController::class, 'updateSettings'])
        ->name('unit-economics.settings.update');

    // Recalculate
    Route::post('/recalculate/{integrationId}', [UnitEconomicsCacheController::class, 'recalculate'])
        ->name('unit-economics.recalculate');
    Route::get('/cache-stats/{integrationId}', [UnitEconomicsCacheController::class, 'cacheStats']);

    // Calculate
    Route::post('/calculate/{marketplace}', [UnitEconomicsCacheController::class, 'calculate'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market'])
        ->name('unit-economics.calculate');

    // By marketplace (main listing — использует кеш)
    Route::get('/{marketplace}', [UnitEconomicsCacheController::class, 'index'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market'])
        ->name('unit-economics.marketplace');
    Route::get('/{marketplace}/{sku}', [UnitEconomicsCacheController::class, 'show'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market'])
        ->name('unit-economics.details');
});

/*
|--------------------------------------------------------------------------
| Auto Supply Plans Module
|--------------------------------------------------------------------------
*/
Route::prefix('auto-supply-plans')->middleware('sellico.permission')->group(function () {
    Route::get('/warehouses', [AutoSupplyPlanController::class, 'warehouses']);
    Route::get('/', [AutoSupplyPlanController::class, 'index'])
        ->name('auto-supply-plans.index');
    Route::post('/', [AutoSupplyPlanController::class, 'store'])
        ->name('auto-supply-plans.store');
    Route::get('/{id}', [AutoSupplyPlanController::class, 'show'])
        ->name('auto-supply-plans.show');
    Route::delete('/{id}', [AutoSupplyPlanController::class, 'destroy'])
        ->name('auto-supply-plans.destroy');
    Route::post('/{id}/calculate', [AutoSupplyPlanController::class, 'calculate'])
        ->name('auto-supply-plans.calculate');
    Route::get('/{id}/lines', [AutoSupplyPlanController::class, 'lines'])
        ->name('auto-supply-plans.lines');
    Route::put('/{id}/lines/{lineId}', [AutoSupplyPlanController::class, 'updateLine']);
    Route::get('/{id}/simulate', [AutoSupplyPlanController::class, 'simulate'])
        ->name('auto-supply-plans.simulate');
    Route::get('/{id}/export/ozon', [AutoSupplyPlanController::class, 'exportOzon'])
        ->name('auto-supply-plans.export');
    Route::get('/{id}/export/ozon-matrix', [AutoSupplyPlanController::class, 'exportOzonMatrix'])
        ->name('auto-supply-plans.export.xlsx');
    Route::get('/{id}/export/ozon-by-warehouse', [AutoSupplyPlanController::class, 'exportOzonByWarehouse'])
        ->name('auto-supply-plans.export.csv');
    Route::get('/{id}/export/wb', [AutoSupplyPlanController::class, 'exportWb']);
});

/*
|--------------------------------------------------------------------------
| Suppliers Module
|--------------------------------------------------------------------------
*/
Route::prefix('suppliers')->middleware('sellico.permission')->group(function () {
    Route::get('/', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('/{id}', [SupplierController::class, 'show'])->name('suppliers.show');
    Route::post('/', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::put('/{id}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/{id}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
});

/*
|--------------------------------------------------------------------------
| Seller Warehouse Stocks Module
|--------------------------------------------------------------------------
*/
Route::prefix('seller-stocks')->middleware('sellico.permission')->group(function () {
    Route::get('/summary', [SellerStockController::class, 'summary'])->name('seller-stocks.summary');
    Route::get('/catalog', [SellerStockController::class, 'catalog'])->name('seller-stocks.catalog');
    Route::get('/', [SellerStockController::class, 'index'])->name('seller-stocks.index');
    Route::post('/bulk', [SellerStockController::class, 'bulkUpsert'])->name('seller-stocks.bulkUpsert');
    Route::post('/', [SellerStockController::class, 'upsert'])->name('seller-stocks.upsert');
    Route::delete('/{id}', [SellerStockController::class, 'destroy'])->name('seller-stocks.destroy');
});

/*
|--------------------------------------------------------------------------
| WB Barcode Costs Module
|--------------------------------------------------------------------------
*/
Route::prefix('wb-barcode-costs')->middleware('sellico.permission')->group(function () {
    Route::get('/', [WbBarcodeCostController::class, 'index'])->name('wb-barcode-costs.index');
    Route::post('/bulk', [WbBarcodeCostController::class, 'bulkUpsert'])->name('wb-barcode-costs.bulkUpsert');
    Route::delete('/', [WbBarcodeCostController::class, 'destroy'])->name('wb-barcode-costs.destroy');
});

/*
|--------------------------------------------------------------------------
| Ozon Order Reports Module
|--------------------------------------------------------------------------
*/
Route::prefix('ozon-reports')->middleware('sellico.permission')->group(function () {
    Route::get('/', [OzonOrderReportController::class, 'index'])->name('ozon-reports.index');
    Route::post('/upload', [OzonOrderReportController::class, 'upload'])->name('ozon-reports.upload');
    Route::get('/summary', [OzonOrderReportController::class, 'reportSummary'])->name('ozon-reports.summary');
    Route::get('/warehouse-sales', [OzonOrderReportController::class, 'warehouseSales'])->name('ozon-reports.warehouseSales');
    Route::delete('/{id}', [OzonOrderReportController::class, 'destroy'])->name('ozon-reports.destroy');
});

/*
|--------------------------------------------------------------------------
| WB Webhooks Module
|--------------------------------------------------------------------------
*/
Route::get('/wb-webhook/status', [WbWebhookController::class, 'status'])->name('wb-webhook.status');
Route::post('/wb-webhook/register', [WbWebhookController::class, 'register'])->name('wb-webhook.register');
Route::post('/wb-webhook/deactivate', [WbWebhookController::class, 'deactivate'])->name('wb-webhook.deactivate');
// Публичный роут для приёма событий от WB (без авторизации)
Route::post('/wb-webhook/receive/{integrationId}', [WbWebhookController::class, 'receive'])->name('wb-webhook.receive');
