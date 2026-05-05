<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\WorkSpaceController;
use App\Http\Controllers\Api\AutoSupplyPlanController;
use App\Http\Controllers\Api\CostPriceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\PlaceholderController;
use App\Http\Controllers\Api\UnitEconomicsController;
use App\Http\Controllers\Api\UnitEconomicsCacheController;
use App\Http\Controllers\Api\SellerStockController;
use App\Http\Controllers\Api\WbBarcodeCostController;
use App\Http\Controllers\Api\WbWebhookController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\OzonOrderReportController;
use App\Http\Controllers\Api\Locality\LocalityOverviewController;
use App\Http\Controllers\Api\Locality\LocalitySkuController;
use App\Http\Controllers\Api\Locality\LocalityClusterController;
use App\Http\Controllers\Api\Locality\LocalityExplainController;
use App\Http\Controllers\Api\Locality\LocalityReconciliationController;
use App\Http\Controllers\Api\Locality\LocalityRecommendationController;
use App\Http\Controllers\Api\Locality\LocalityRecomputeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Integrations Module
|--------------------------------------------------------------------------
*/
Route::prefix('integrations')->middleware('sellico.permission')->group(function () {
    Route::get('/', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::get('/{id}/premium-status', [IntegrationController::class, 'getPremiumStatus'])->name('integrations.premiumStatus');
    Route::put('/{id}/manual-redemption-rate', [IntegrationController::class, 'setManualRedemptionRate'])->name('integrations.manualRedemptionRate');
    Route::get('/{id}/sync-status', [IntegrationController::class, 'syncStatus'])->name('integrations.syncStatus');
    Route::get('/{id}/status', [IntegrationController::class, 'checkStatus'])->name('integrations.status');
});

// Integration sync - outside sellico.permission middleware, т.к. метод
// сам проверяет доступ через IntegrationAccessService::ensureAccessibleIntegration.
// Ограничиваем методы POST/GET чтобы запретить CSRF-like трюки через PUT/DELETE.
Route::match(['GET', 'POST'], 'integrations/{id}/sync', [IntegrationController::class, 'sync'])
    ->name('integrations.sync.direct');

/*
|--------------------------------------------------------------------------
| Auth & Integrations (Sellico API)
|--------------------------------------------------------------------------
*/
Route::get('/placeholder/{width}/{height}', [PlaceholderController::class, 'show'])
    ->whereNumber('width')
    ->whereNumber('height');

Route::prefix('auth')->group(function () {
    // throttle:10,1 — не более 10 попыток логина в минуту с одного IP.
    // Защита от credential stuffing / bruteforce.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/me', [AuthController::class, 'me'])->middleware('throttle:60,1');
    Route::get('/workspaces', [AuthController::class, 'workspaces'])->middleware('throttle:60,1');
    Route::get('/workspaces/{workspaceId}/integrations', [AuthController::class, 'integrations'])->middleware('throttle:60,1');
});

Route::middleware('throttle:60,1')->group(function () {
    Route::get('/workspaces/{workspace}/limits-external', [WorkSpaceController::class, 'getLimitsExternal'])
        ->whereNumber('workspace')
        ->name('workspaces.limits-external.show');
    Route::post('/workspaces/{workspace}/limits-external', [WorkSpaceController::class, 'storeLimitExternal'])
        ->whereNumber('workspace')
        ->name('workspaces.limits-external.store');
    Route::put('/workspaces/{workspace}/limits-external/sync', [WorkSpaceController::class, 'syncLimitExternal'])
        ->whereNumber('workspace')
        ->name('workspaces.limits-external.sync');
});

/*
|--------------------------------------------------------------------------
| Products Module
|--------------------------------------------------------------------------
*/
Route::prefix('products')->middleware('sellico.permission')->group(function () {
    Route::get('/', [ProductController::class, 'index'])->name('products.index');
    Route::get('/sync/status', [ProductController::class, 'syncStatus'])->name('products.syncStatus');
    // Без integration.access: метод поддерживает запуск без integration_id
    // для sync всех интеграций workspace (см. test_products_sync_without_integration_id_*).
    // Проверка доступа — в ProductService::startSync / контроллере по workspace-заголовку.
    Route::post('/sync/{marketplace}', [ProductController::class, 'sync'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market'])
        ->name('products.sync');
    Route::get('/cost-price', [CostPriceController::class, 'index'])->name('products.cost-price.index');
    Route::post('/cost-price/upload', [CostPriceController::class, 'upload'])->name('products.cost-price.upload');
    Route::post('/cost-price/bulk', [CostPriceController::class, 'bulk'])->name('products.cost-price.bulk');
    Route::get('/cost-price/template', [CostPriceController::class, 'template'])->name('products.cost-price.template');
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
    // integration.access: integration_id обязательно приходит в body,
    // проверяем доступ пользователя до вызова контроллера (закрывает IDOR).
    Route::post('/sync/{marketplace}', [InventoryController::class, 'sync'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market'])
        ->middleware('integration.access')
        ->name('inventory.sync');
    Route::post('/sync-storage-fees', [InventoryController::class, 'syncStorageFees'])
        ->middleware('integration.access')
        ->name('inventory.syncStorageFees');
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
    Route::post('/from-recommendation/{recommendationId}', [ShipmentController::class, 'createFromRecommendation'])->name('shipments.createFromRecommendation');
    
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
    Route::get('/{id}/export/csv', [ShipmentController::class, 'exportCsv'])->name('shipments.export.csv');
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
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market'])
        ->name('unit-economics.stats.marketplace');

    // Settings (PUT) — должны быть до /{marketplace} чтобы не конфликтовать
    Route::put('/settings/bulk', [UnitEconomicsCacheController::class, 'bulkUpdateSettings'])
        ->name('unit-economics.settings.bulk');
    Route::put('/settings/{sku}', [UnitEconomicsCacheController::class, 'updateSettings'])
        ->name('unit-economics.settings.update');

    // Recalculate — закрываем IDOR: middleware проверяет доступ к integrationId
    // до вызова контроллера (раньше любой мог запустить пересчёт чужой интеграции).
    Route::post('/recalculate/{integrationId}', [UnitEconomicsCacheController::class, 'recalculate'])
        ->middleware('integration.access')
        ->name('unit-economics.recalculate');
    Route::get('/cache-stats/{integrationId}', [UnitEconomicsCacheController::class, 'cacheStats'])
        ->middleware('integration.access')
        ->name('unit-economics.cache-stats');

    // Светофор «свежести» данных UE + этапы синхронизации.
    // Фронт поллит этот endpoint каждые 2–3 сек во время sync для progress-bar.
    Route::get('/freshness/{integrationId}', [UnitEconomicsCacheController::class, 'freshness'])
        ->middleware('integration.access')
        ->name('unit-economics.freshness');

    // Calculate
    Route::post('/calculate/{marketplace}', [UnitEconomicsCacheController::class, 'calculate'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market'])
        ->name('unit-economics.calculate');

    // Export
    Route::get('/{marketplace}/export/excel', [UnitEconomicsCacheController::class, 'exportExcel'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market'])
        ->name('unit-economics.export.excel');

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
    Route::get('/warehouses', [AutoSupplyPlanController::class, 'warehouses'])->name('auto-supply-plans.warehouses');
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
    Route::put('/{id}/lines/{lineId}', [AutoSupplyPlanController::class, 'updateLine'])->name('auto-supply-plans.lines.update');
    Route::get('/{id}/simulate', [AutoSupplyPlanController::class, 'simulate'])
        ->name('auto-supply-plans.simulate');
    Route::get('/{id}/export/ozon', [AutoSupplyPlanController::class, 'exportOzon'])
        ->name('auto-supply-plans.export');
    Route::get('/{id}/export/ozon-matrix', [AutoSupplyPlanController::class, 'exportOzonMatrix'])
        ->name('auto-supply-plans.export.xlsx');
    Route::get('/{id}/export/ozon-by-warehouse', [AutoSupplyPlanController::class, 'exportOzonByWarehouse'])
        ->name('auto-supply-plans.export.csv');
    Route::get('/{id}/export/wb', [AutoSupplyPlanController::class, 'exportWb'])->name('auto-supply-plans.export.wb');

    // Locality integration
    Route::get('/{id}/locality-impact', [AutoSupplyPlanController::class, 'localityImpact'])
        ->name('auto-supply-plans.locality-impact');
    Route::get('/{id}/cluster-split', [AutoSupplyPlanController::class, 'clusterSplit'])
        ->name('auto-supply-plans.cluster-split');
    Route::get('/{id}/locality-recommendations', [AutoSupplyPlanController::class, 'localityRecommendations'])
        ->name('auto-supply-plans.locality-recommendations');
    Route::get('/{id}/cluster-draft-preview', [AutoSupplyPlanController::class, 'clusterDraftPreview'])
        ->name('auto-supply-plans.cluster-draft-preview');
    Route::post('/{id}/create-cluster-drafts', [AutoSupplyPlanController::class, 'createClusterDrafts'])
        ->name('auto-supply-plans.create-cluster-drafts');
    Route::post('/from-locality-recommendations', [AutoSupplyPlanController::class, 'createFromLocalityRecommendations'])
        ->name('auto-supply-plans.from-locality-recommendations');
    Route::post('/preview-split-by-cluster', [AutoSupplyPlanController::class, 'previewSplitByCluster'])
        ->name('auto-supply-plans.preview-split-by-cluster');
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
| Locality Engine Module
|--------------------------------------------------------------------------
*/
Route::prefix('v1/locality')->middleware('sellico.permission')->group(function () {
    Route::get('/overview', [LocalityOverviewController::class, 'index'])->name('locality.overview');
    Route::get('/skus', [LocalitySkuController::class, 'index'])->name('locality.skus');
    Route::get('/clusters', [LocalityClusterController::class, 'index'])->name('locality.clusters');
    Route::get('/sku/{sku}/explain', [LocalityExplainController::class, 'show'])
        ->where('sku', '.+')
        ->name('locality.explain');
    Route::post('/sku/{sku}/counterfactual', [LocalityExplainController::class, 'counterfactual'])
        ->where('sku', '.+')
        ->name('locality.counterfactual');
    // Query-based варианты (для SKU со слэшами — path-версия ломается на encoded %2F в nginx)
    Route::get('/explain', [LocalityExplainController::class, 'show'])->name('locality.explain.q');
    Route::post('/counterfactual', [LocalityExplainController::class, 'counterfactual'])->name('locality.counterfactual.q');
    Route::get('/recommendations', [LocalityRecommendationController::class, 'index'])->name('locality.recommendations.index');
    Route::get('/recommendations/{id}', [LocalityRecommendationController::class, 'show'])->name('locality.recommendations.show');
    Route::post('/recommendations/{id}/dismiss', [LocalityRecommendationController::class, 'dismiss'])->name('locality.recommendations.dismiss');
    Route::post('/recommendations/{id}/draft/preview', [LocalityRecommendationController::class, 'draftPreview'])->name('locality.recommendations.draftPreview');
    Route::post('/recommendations/{id}/draft/create', [LocalityRecommendationController::class, 'draftCreate'])->name('locality.recommendations.draftCreate');
    Route::get('/reconciliation', [LocalityReconciliationController::class, 'index'])->name('locality.reconciliation');
    Route::post('/recompute', [LocalityRecomputeController::class, 'store'])->name('locality.recompute');
});

/*
|--------------------------------------------------------------------------
| WB Webhooks Module
|--------------------------------------------------------------------------
*/
Route::get('/wb-webhook/status', [WbWebhookController::class, 'status'])->name('wb-webhook.status');
Route::post('/wb-webhook/register', [WbWebhookController::class, 'register'])->name('wb-webhook.register');
Route::post('/wb-webhook/deactivate', [WbWebhookController::class, 'deactivate'])->name('wb-webhook.deactivate');
// Публичный роут для приёма событий от WB. Авторизация — через HMAC-подпись
// внутри контроллера (см. WbWebhookController::isSignatureValid).
// throttle:300,1 — защита от спам-запросов без подписи (они всё равно получат 401,
// но лимитер освобождает worker'ы).
Route::post('/wb-webhook/receive/{integrationId}', [WbWebhookController::class, 'receive'])
    ->middleware('throttle:300,1')
    ->name('wb-webhook.receive');
