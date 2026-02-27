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
Route::prefix('integrations')->group(function () {
    Route::get('/', [IntegrationController::class, 'index']);
    Route::get('/{id}/premium-status', [IntegrationController::class, 'getPremiumStatus']);
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
Route::prefix('products')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/sync/status', [ProductController::class, 'syncStatus']);
    Route::post('/sync/{marketplace}', [ProductController::class, 'sync'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex']);
    Route::get('/cost-price', [CostPriceController::class, 'index']);
    Route::post('/cost-price/upload', [CostPriceController::class, 'upload']);
    Route::post('/cost-price/bulk', [CostPriceController::class, 'bulk']);
    Route::get('/cost-price/template', [CostPriceController::class, 'template']);
    Route::get('/{id}', [ProductController::class, 'show']);
    Route::post('/', [ProductController::class, 'store']);
    Route::put('/{id}', [ProductController::class, 'update']);
    Route::delete('/{id}', [ProductController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Inventory Module
|--------------------------------------------------------------------------
*/
Route::prefix('inventory')->group(function () {
    Route::get('/', [InventoryController::class, 'index']);
    Route::get('/sync/status', [InventoryController::class, 'syncStatus']);
    Route::get('/alerts', [InventoryController::class, 'alerts']);
    Route::get('/matrix', [InventoryController::class, 'matrix']);
    Route::get('/recommendations', [InventoryController::class, 'recommendations']);
    Route::get('/redistribution', [InventoryController::class, 'redistribution']);
    Route::get('/stats', [InventoryController::class, 'stats']);
    Route::post('/sync/{marketplace}', [InventoryController::class, 'sync'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex']);
    Route::get('/{sku}', [InventoryController::class, 'show']);
    Route::get('/{sku}/history', [InventoryController::class, 'history']);
    Route::get('/{sku}/forecast', [InventoryController::class, 'forecast']);
});

/*
|--------------------------------------------------------------------------
| Shipments Module
|--------------------------------------------------------------------------
*/
Route::prefix('shipments')->group(function () {
    Route::get('/', [ShipmentController::class, 'index']);
    Route::get('/slots', [ShipmentController::class, 'slots']);
    Route::get('/recommendations', [ShipmentController::class, 'recommendations']);
    Route::get('/stats', [ShipmentController::class, 'stats']);
    Route::post('/from-recommendation/{recommendationId}', [ShipmentController::class, 'createFromRecommendation']);
    
    Route::get('/{id}', [ShipmentController::class, 'show']);
    Route::post('/', [ShipmentController::class, 'store']);
    Route::put('/{id}', [ShipmentController::class, 'update']);
    Route::delete('/{id}', [ShipmentController::class, 'destroy']);
    
    // Items management
    Route::post('/{id}/items', [ShipmentController::class, 'addItem']);
    Route::put('/{id}/items/{itemId}', [ShipmentController::class, 'updateItem']);
    Route::delete('/{id}/items/{itemId}', [ShipmentController::class, 'removeItem']);
    
    // Workflow
    Route::post('/{id}/submit', [ShipmentController::class, 'submit']);
    Route::post('/{id}/approve', [ShipmentController::class, 'approve']);
    Route::post('/{id}/reject', [ShipmentController::class, 'reject']);
    Route::post('/{id}/send', [ShipmentController::class, 'send']);
    Route::post('/{id}/deliver', [ShipmentController::class, 'deliver']);
    
    // Slots
    Route::post('/{id}/book-slot', [ShipmentController::class, 'bookSlot']);
    
    // Export
    Route::get('/{id}/export/pdf', [ShipmentController::class, 'exportPdf']);
    Route::get('/{id}/export/csv', [ShipmentController::class, 'exportCsv']);
});

/*
|--------------------------------------------------------------------------
| Unit Economics Module
|--------------------------------------------------------------------------
*/
Route::prefix('unit-economics')->group(function () {
    Route::get('/comparison', [UnitEconomicsCacheController::class, 'comparison']);
    Route::get('/stats', [UnitEconomicsCacheController::class, 'stats']);

    Route::get('/commissions/{marketplace}', [UnitEconomicsCacheController::class, 'commissions'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);
    Route::get('/tariffs/{marketplace}', [UnitEconomicsCacheController::class, 'tariffs'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);
    Route::get('/stats/{marketplace}', [UnitEconomicsCacheController::class, 'statsByMarketplace'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);

    // Settings (PUT) — должны быть до /{marketplace} чтобы не конфликтовать
    Route::put('/settings/bulk', [UnitEconomicsCacheController::class, 'bulkUpdateSettings']);
    Route::put('/settings/{sku}', [UnitEconomicsCacheController::class, 'updateSettings']);

    // Recalculate
    Route::post('/recalculate/{integrationId}', [UnitEconomicsCacheController::class, 'recalculate']);
    Route::get('/cache-stats/{integrationId}', [UnitEconomicsCacheController::class, 'cacheStats']);

    // Calculate
    Route::post('/calculate/{marketplace}', [UnitEconomicsCacheController::class, 'calculate'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);

    // By marketplace (main listing — использует кеш)
    Route::get('/{marketplace}', [UnitEconomicsCacheController::class, 'index'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);
    Route::get('/{marketplace}/{sku}', [UnitEconomicsCacheController::class, 'show'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);
});

/*
|--------------------------------------------------------------------------
| Auto Supply Plans Module
|--------------------------------------------------------------------------
*/
Route::prefix('auto-supply-plans')->group(function () {
    Route::get('/warehouses', [AutoSupplyPlanController::class, 'warehouses']);
    Route::get('/', [AutoSupplyPlanController::class, 'index']);
    Route::post('/', [AutoSupplyPlanController::class, 'store']);
    Route::get('/{id}', [AutoSupplyPlanController::class, 'show']);
    Route::delete('/{id}', [AutoSupplyPlanController::class, 'destroy']);
    Route::post('/{id}/calculate', [AutoSupplyPlanController::class, 'calculate']);
    Route::get('/{id}/lines', [AutoSupplyPlanController::class, 'lines']);
    Route::put('/{id}/lines/{lineId}', [AutoSupplyPlanController::class, 'updateLine']);
    Route::get('/{id}/simulate', [AutoSupplyPlanController::class, 'simulate']);
    Route::get('/{id}/export/ozon', [AutoSupplyPlanController::class, 'exportOzon']);
    Route::get('/{id}/export/ozon-matrix', [AutoSupplyPlanController::class, 'exportOzonMatrix']);
    Route::get('/{id}/export/ozon-by-warehouse', [AutoSupplyPlanController::class, 'exportOzonByWarehouse']);
    Route::get('/{id}/export/wb', [AutoSupplyPlanController::class, 'exportWb']);
});

/*
|--------------------------------------------------------------------------
| Suppliers Module
|--------------------------------------------------------------------------
*/
Route::prefix('suppliers')->group(function () {
    Route::get('/', [SupplierController::class, 'index']);
    Route::get('/{id}', [SupplierController::class, 'show']);
    Route::post('/', [SupplierController::class, 'store']);
    Route::put('/{id}', [SupplierController::class, 'update']);
    Route::delete('/{id}', [SupplierController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Seller Warehouse Stocks Module
|--------------------------------------------------------------------------
*/
Route::prefix('seller-stocks')->group(function () {
    Route::get('/summary', [SellerStockController::class, 'summary']);
    Route::get('/catalog', [SellerStockController::class, 'catalog']);
    Route::get('/', [SellerStockController::class, 'index']);
    Route::post('/bulk', [SellerStockController::class, 'bulkUpsert']);
    Route::post('/', [SellerStockController::class, 'upsert']);
    Route::delete('/{id}', [SellerStockController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| WB Barcode Costs Module
|--------------------------------------------------------------------------
*/
Route::prefix('wb-barcode-costs')->group(function () {
    Route::get('/', [WbBarcodeCostController::class, 'index']);
    Route::post('/bulk', [WbBarcodeCostController::class, 'bulkUpsert']);
    Route::delete('/', [WbBarcodeCostController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Ozon Order Reports Module
|--------------------------------------------------------------------------
*/
Route::prefix('ozon-reports')->group(function () {
    Route::get('/', [OzonOrderReportController::class, 'index']);
    Route::post('/upload', [OzonOrderReportController::class, 'upload']);
    Route::get('/summary', [OzonOrderReportController::class, 'reportSummary']);
    Route::get('/warehouse-sales', [OzonOrderReportController::class, 'warehouseSales']);
    Route::delete('/{id}', [OzonOrderReportController::class, 'destroy']);
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
