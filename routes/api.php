<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\WorkSpaceController;
use App\Http\Controllers\Api\AutoSupplyPlanController;
use App\Http\Controllers\Api\CostPriceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\SupplyController;
use App\Http\Controllers\Api\SupplyDocumentController;
use App\Http\Controllers\Api\SupplyPackageController;
use App\Http\Controllers\Api\SupplyPlanController;
use App\Http\Controllers\Api\SupplyRecommendationController;
use App\Http\Controllers\Api\PostingController;
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
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'products-backend',
    'time' => now()->toIso8601String(),
]))->name('health');

Route::prefix('integrations')->middleware('sellico.permission')->group(function () {
    Route::get('/', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::get('/{id}/premium-status', [IntegrationController::class, 'getPremiumStatus'])->name('integrations.premiumStatus');
    Route::get('/{id}/performance-status', [IntegrationController::class, 'performanceStatus'])->name('integrations.performanceStatus');
    Route::get('/{id}/performance-summary', [IntegrationController::class, 'performanceSummary'])->name('integrations.performanceSummary');
    Route::get('/{id}/performance-campaigns/{campaignId}/objects', [IntegrationController::class, 'performanceCampaignObjects'])->name('integrations.performanceCampaignObjects');
    Route::post('/{id}/performance-product-report', [IntegrationController::class, 'requestPerformanceProductReport'])->name('integrations.performanceProductReport');
    Route::get('/{id}/performance-reports/{uuid}/preview', [IntegrationController::class, 'performanceReportPreview'])->name('integrations.performanceReportPreview');
    Route::get('/{id}/performance-reports/{uuid}/advertising-impact', [IntegrationController::class, 'performanceAdvertisingImpact'])->name('integrations.performanceAdvertisingImpact');
    Route::get('/{id}/performance-reports/{uuid}', [IntegrationController::class, 'performanceReportStatus'])->name('integrations.performanceReportStatus');
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
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex', 'yandex_market'])
        ->name('products.sync');
    Route::post('/export/{marketplace}', [ProductController::class, 'export'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex', 'yandex_market'])
        ->name('products.export');
    Route::get('/export/{exportId}/status', [ProductController::class, 'exportStatus'])
        ->name('products.export.status');
    Route::get('/export/{exportId}/download', [ProductController::class, 'downloadExport'])
        ->name('products.export.download');
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
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex', 'yandex_market'])
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
| Supply Recommendations Module
|--------------------------------------------------------------------------
*/
Route::prefix('supply-recommendations')->middleware('sellico.permission')->group(function () {
    Route::get('/', [SupplyRecommendationController::class, 'index'])->name('supply-recommendations.index');
    Route::get('/by-warehouse', [SupplyRecommendationController::class, 'byWarehouse'])->name('supply-recommendations.byWarehouse');
    Route::get('/stats', [SupplyRecommendationController::class, 'stats'])->name('supply-recommendations.stats');
    Route::post('/generate', [SupplyRecommendationController::class, 'generate'])->name('supply-recommendations.generate');
    Route::get('/{id}', [SupplyRecommendationController::class, 'show'])->name('supply-recommendations.show');
    Route::post('/{id}/apply', [SupplyRecommendationController::class, 'apply'])->name('supply-recommendations.apply');
    Route::post('/{id}/dismiss', [SupplyRecommendationController::class, 'dismiss'])->name('supply-recommendations.dismiss');
});

/*
|--------------------------------------------------------------------------
| Supply Plans Module
|--------------------------------------------------------------------------
*/
Route::prefix('supply-plans')->middleware('sellico.permission')->group(function () {
    Route::get('/', [SupplyPlanController::class, 'index'])->name('supply-plans.index');
    Route::post('/', [SupplyPlanController::class, 'store'])->name('supply-plans.store');
    Route::get('/{id}', [SupplyPlanController::class, 'show'])->name('supply-plans.show');
    Route::put('/{id}', [SupplyPlanController::class, 'update'])->name('supply-plans.update');
    Route::delete('/{id}', [SupplyPlanController::class, 'destroy'])->name('supply-plans.destroy');
    Route::get('/{id}/calculate', [SupplyPlanController::class, 'calculate'])->name('supply-plans.calculate');
    Route::post('/{id}/approve', [SupplyPlanController::class, 'approve'])->name('supply-plans.approve');
    Route::post('/{id}/cancel', [SupplyPlanController::class, 'cancel'])->name('supply-plans.cancel');
});

/*
|--------------------------------------------------------------------------
| Warehouse Slots Module
|--------------------------------------------------------------------------
*/
Route::prefix('warehouse-slots')->middleware('sellico.permission')->group(function () {
    Route::get('/', [SupplyController::class, 'getSlots'])->name('warehouse-slots.index');
    Route::get('/warehouses', [AutoSupplyPlanController::class, 'warehouses'])->name('warehouse-slots.warehouses');
    Route::post('/sync', [SupplyController::class, 'syncSlots'])->name('warehouse-slots.sync');
    Route::post('/{slotId}/book', [SupplyController::class, 'bookWarehouseSlot'])->name('warehouse-slots.book');
    Route::post('/{slotId}/release', [SupplyController::class, 'releaseWarehouseSlot'])->name('warehouse-slots.release');
});

/*
|--------------------------------------------------------------------------
| Ozon FBO Supplies Module
|--------------------------------------------------------------------------
*/
Route::prefix('supplies')->middleware('sellico.permission')->group(function () {
    Route::get('/stats', [SupplyController::class, 'getStats'])->name('supplies.stats');
    Route::get('/settings', [SupplyController::class, 'getSettings'])->name('supplies.settings.index');
    Route::put('/settings', [SupplyController::class, 'updateSettings'])->name('supplies.settings.update');
    Route::get('/analytics', [SupplyController::class, 'getAnalytics'])->name('supplies.analytics');
    Route::get('/clusters', [SupplyController::class, 'getClusters'])->name('supplies.clusters');
    Route::get('/clusters/{clusterId}/products', [SupplyController::class, 'getClusterProducts'])->name('supplies.cluster-products');
    Route::post('/clusters/{clusterId}/add-products', [SupplyController::class, 'addClusterProducts'])->name('supplies.add-cluster-products');
    Route::post('/clusters/{clusterId}/delivery', [SupplyController::class, 'setClusterDeliveryMethod'])->name('supplies.set-cluster-delivery-method');
    Route::post('/clusters/{clusterId}/warehouse', [SupplyController::class, 'setClusterWarehouse'])->name('supplies.set-cluster-warehouse');
    Route::get('/slots', [SupplyController::class, 'getSlots'])->name('supplies.slots');
    Route::post('/sync-slots', [SupplyController::class, 'syncSlots'])->name('supplies.sync-slots');
    Route::get('/products-for-supply', [SupplyController::class, 'getProductsForSupply'])->name('supplies.products-for-supply');
    Route::post('/create-with-slot', [SupplyController::class, 'createWithSlot'])->name('supplies.create-with-slot');

    Route::prefix('recommendations')->group(function () {
        Route::get('/', [SupplyController::class, 'getRecommendations'])->name('supplies.recommendations.index');
        Route::get('/map', [SupplyController::class, 'getRecommendationsMap'])->name('supplies.recommendations.map');
        Route::get('/map-warehouses', [SupplyController::class, 'getRecommendationsMapWarehouses'])->name('supplies.recommendations.map-warehouses');
        Route::post('/calculate', [SupplyController::class, 'calculateRecommendations'])->name('supplies.recommendations.calculate');
        Route::get('/by-sku/{sku}', [SupplyController::class, 'getRecommendationsBySku'])->where('sku', '.*')->name('supplies.recommendations.by-sku');
        Route::get('/summary', [SupplyController::class, 'getRecommendationsSummary'])->name('supplies.recommendations.summary');
        Route::post('/{id}/accept', [SupplyController::class, 'acceptRecommendation'])->whereNumber('id')->name('supplies.recommendations.accept');
        Route::post('/{id}/reject', [SupplyController::class, 'rejectRecommendation'])->whereNumber('id')->name('supplies.recommendations.reject');
        Route::post('/{id}/postpone', [SupplyController::class, 'postponeRecommendation'])->whereNumber('id')->name('supplies.recommendations.postpone');
    });

    Route::get('/', [SupplyController::class, 'index'])->name('supplies.index');
    Route::post('/', [SupplyController::class, 'store'])->name('supplies.store');
    Route::post('/manual', [SupplyController::class, 'storeManual'])->name('supplies.store-manual');

    Route::get('/{id}/packages/summary', [SupplyPackageController::class, 'summary'])->whereNumber('id')->name('supplies.packages.summary');
    Route::post('/{id}/packages/auto-pack', [SupplyPackageController::class, 'autoPack'])->whereNumber('id')->name('supplies.packages.auto-pack');
    Route::get('/{id}/packages', [SupplyPackageController::class, 'index'])->whereNumber('id')->name('supplies.packages.index');
    Route::post('/{id}/packages', [SupplyPackageController::class, 'store'])->whereNumber('id')->name('supplies.packages.store');
    Route::get('/{id}/packages/{packageId}', [SupplyPackageController::class, 'show'])->whereNumber('id')->whereNumber('packageId')->name('supplies.packages.show');
    Route::put('/{id}/packages/{packageId}', [SupplyPackageController::class, 'update'])->whereNumber('id')->whereNumber('packageId')->name('supplies.packages.update');
    Route::delete('/{id}/packages/{packageId}', [SupplyPackageController::class, 'destroy'])->whereNumber('id')->whereNumber('packageId')->name('supplies.packages.destroy');
    Route::post('/{id}/packages/{packageId}/items', [SupplyPackageController::class, 'addItem'])->whereNumber('id')->whereNumber('packageId')->name('supplies.packages.add-item');
    Route::delete('/{id}/packages/{packageId}/items/{itemId}', [SupplyPackageController::class, 'removeItem'])->whereNumber('id')->whereNumber('packageId')->whereNumber('itemId')->name('supplies.packages.remove-item');
    Route::post('/{id}/packages/{packageId}/pack', [SupplyPackageController::class, 'pack'])->whereNumber('id')->whereNumber('packageId')->name('supplies.packages.pack');
    Route::post('/{id}/packages/{packageId}/label', [SupplyDocumentController::class, 'generatePackageLabel'])->whereNumber('id')->whereNumber('packageId')->name('supplies.documents.package-label');

    Route::get('/{id}/documents', [SupplyDocumentController::class, 'index'])->whereNumber('id')->name('supplies.documents.index');
    Route::get('/{id}/documents/{documentId}', [SupplyDocumentController::class, 'show'])->whereNumber('id')->whereNumber('documentId')->name('supplies.documents.show');
    Route::get('/{id}/documents/{documentId}/download', [SupplyDocumentController::class, 'download'])->whereNumber('id')->whereNumber('documentId')->name('supplies.documents.download');
    Route::post('/{id}/labels/generate-all', [SupplyDocumentController::class, 'generateAllLabels'])->whereNumber('id')->name('supplies.documents.all-labels');
    Route::post('/{id}/documents/packing-list', [SupplyDocumentController::class, 'generatePackingList'])->whereNumber('id')->name('supplies.documents.packing-list');

    Route::post('/{id}/create-draft', [SupplyController::class, 'createDraft'])->whereNumber('id')->name('supplies.create-draft');
    Route::get('/{id}/timeslots', [SupplyController::class, 'getTimeslots'])->whereNumber('id')->name('supplies.timeslots');
    Route::post('/{id}/book-slot', [SupplyController::class, 'bookSlot'])->whereNumber('id')->name('supplies.book-slot');
    Route::post('/{id}/start-preparing', [SupplyController::class, 'startPreparing'])->whereNumber('id')->name('supplies.start-preparing');
    Route::post('/{id}/ready-to-ship', [SupplyController::class, 'markReadyToShip'])->whereNumber('id')->name('supplies.ready-to-ship');
    Route::post('/{id}/ship', [SupplyController::class, 'markShipped'])->whereNumber('id')->name('supplies.ship');
    Route::post('/{id}/cancel', [SupplyController::class, 'cancel'])->whereNumber('id')->name('supplies.cancel');
    Route::post('/{id}/sync-status', [SupplyController::class, 'syncStatus'])->whereNumber('id')->name('supplies.sync-status');
    Route::get('/{id}/events', [SupplyController::class, 'getEvents'])->whereNumber('id')->name('supplies.events');
    Route::get('/{id}', [SupplyController::class, 'show'])->whereNumber('id')->name('supplies.show');
});

/*
|--------------------------------------------------------------------------
| Ozon FBS Postings Module
|--------------------------------------------------------------------------
*/
Route::prefix('postings')->middleware('sellico.permission')->group(function () {
    Route::get('/', [PostingController::class, 'index'])->name('postings.index');
    Route::get('/statistics', [PostingController::class, 'statistics'])->name('postings.statistics');
    Route::post('/sync', [PostingController::class, 'sync'])->name('postings.sync');
    Route::post('/bulk-labels', [PostingController::class, 'bulkLabels'])->name('postings.bulk-labels');
    Route::post('/bulk-ship', [PostingController::class, 'bulkShip'])->name('postings.bulk-ship');
    Route::post('/act/create', [PostingController::class, 'createAct'])->name('postings.act.create');
    Route::get('/act/{actId}/download', [PostingController::class, 'downloadAct'])->whereNumber('actId')->name('postings.act.download');
    Route::get('/cancel-reasons', [PostingController::class, 'cancelReasons'])->name('postings.cancel-reasons');
    Route::get('/returns', [PostingController::class, 'returns'])->name('postings.returns');
    Route::get('/{id}', [PostingController::class, 'show'])->name('postings.show');
    Route::post('/{id}/assemble', [PostingController::class, 'assemble'])->name('postings.assemble');
    Route::post('/{id}/pack', [PostingController::class, 'pack'])->name('postings.pack');
    Route::post('/{id}/ship', [PostingController::class, 'ship'])->name('postings.ship');
    Route::post('/{id}/cancel', [PostingController::class, 'cancel'])->name('postings.cancel');
    Route::get('/{id}/label', [PostingController::class, 'label'])->name('postings.label');
});

/*
|--------------------------------------------------------------------------
| Unit Economics Module
|--------------------------------------------------------------------------
*/
Route::prefix('unit-economics')->middleware('sellico.permission')->group(function () {
    Route::get('/', [UnitEconomicsController::class, 'index'])
        ->name('unit-economics.index');
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
        ->where('sku', '.*')
        ->name('unit-economics.settings.update');
    Route::post('/wildberries/indexes/import', [UnitEconomicsCacheController::class, 'importWildberriesIndexes'])
        ->name('unit-economics.wildberries.indexes.import');

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
    Route::get('/capabilities', [AutoSupplyPlanController::class, 'capabilities'])
        ->name('auto-supply-plans.capabilities');
    Route::get('/warehouses', [AutoSupplyPlanController::class, 'warehouses'])->name('auto-supply-plans.warehouses');
    Route::get('/data-health', [AutoSupplyPlanController::class, 'dataHealth'])->name('auto-supply-plans.data-health');
    Route::get('/constraints', [AutoSupplyPlanController::class, 'constraintFiles'])
        ->name('auto-supply-plans.constraints.index');
    Route::post('/constraints/preview', [AutoSupplyPlanController::class, 'previewConstraints'])
        ->name('auto-supply-plans.constraints.preview');
    Route::get('/crossdock-drop-off-points', [AutoSupplyPlanController::class, 'crossdockDropOffPoints'])
        ->name('auto-supply-plans.crossdock-drop-off-points');
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
    Route::post('/{id}/fix-ktr-baseline', [AutoSupplyPlanController::class, 'fixKtrBaseline'])
        ->name('auto-supply-plans.fix-ktr-baseline');
    Route::get('/{id}/lines', [AutoSupplyPlanController::class, 'lines'])
        ->name('auto-supply-plans.lines');
    Route::get('/{id}/clusters', [AutoSupplyPlanController::class, 'clusters'])
        ->name('auto-supply-plans.clusters');
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
