<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\UnitEconomicsCacheController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth & Integrations (Sellico API)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::get('/me', [AuthController::class, 'me'])->middleware('throttle:api');
    Route::get('/workspaces', [AuthController::class, 'workspaces'])->middleware('throttle:api');
    Route::get('/workspaces/{workspaceId}/integrations', [AuthController::class, 'integrations'])->middleware('throttle:api');
});

/*
|--------------------------------------------------------------------------
| Integrations Module
|--------------------------------------------------------------------------
*/
Route::prefix('integrations')->middleware('throttle:api')->group(function () {
    Route::get('/', [IntegrationController::class, 'index']);
    Route::post('/test-connection', [IntegrationController::class, 'testConnection']);
    Route::get('/{id}', [IntegrationController::class, 'show']);
    Route::post('/', [IntegrationController::class, 'store']);
    Route::put('/{id}', [IntegrationController::class, 'update']);
    Route::delete('/{id}', [IntegrationController::class, 'destroy']);
    Route::post('/{id}/sync', [IntegrationController::class, 'sync'])->middleware('throttle:sync');
    Route::get('/{id}/sync-status', [IntegrationController::class, 'syncStatus']);
    Route::get('/{id}/premium-status', [IntegrationController::class, 'getPremiumStatus']);
    Route::post('/{id}/redemption-rate', [IntegrationController::class, 'setManualRedemptionRate']);
});

/*
|--------------------------------------------------------------------------
| Products Module
|--------------------------------------------------------------------------
*/
Route::prefix('products')->middleware('throttle:api')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/sync/status', [ProductController::class, 'syncStatus']);
    Route::post('/cost-price/upload', [ProductController::class, 'uploadCostPrice'])->middleware('throttle:bulk');
    Route::post('/cost-price/bulk', [ProductController::class, 'updateCostPriceBulk'])->middleware('throttle:bulk');
    Route::get('/cost-price/template', [ProductController::class, 'exportCostPriceTemplate'])->middleware('throttle:export');
    Route::get('/{id}', [ProductController::class, 'show']);
    
    // Защищённые роуты (требуют авторизации через Sellico)
    Route::middleware('sellico.auth')->group(function () {
        Route::post('/sync/{marketplace}', [ProductController::class, 'sync'])
            ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex'])
            ->middleware('throttle:sync');
        Route::post('/export/{marketplace}', [ProductController::class, 'export'])
            ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex'])
            ->middleware('throttle:sync');
        Route::post('/', [ProductController::class, 'store']);
        Route::put('/{id}', [ProductController::class, 'update']);
        Route::delete('/{id}', [ProductController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| Inventory Module
|--------------------------------------------------------------------------
*/
Route::prefix('inventory')->middleware('throttle:api')->group(function () {
    Route::get('/', [InventoryController::class, 'index']);
    Route::get('/matrix', [InventoryController::class, 'matrix']);
    Route::get('/sync/status', [InventoryController::class, 'syncStatus']);
    Route::get('/alerts', [InventoryController::class, 'alerts']);
    Route::get('/recommendations', [InventoryController::class, 'recommendations']);
    Route::get('/redistribution', [InventoryController::class, 'redistribution']);
    Route::get('/stats', [InventoryController::class, 'stats']);
    Route::get('/{sku}', [InventoryController::class, 'show']);
    Route::get('/{sku}/history', [InventoryController::class, 'history']);
    Route::get('/{sku}/forecast', [InventoryController::class, 'forecast']);
    Route::get('/{sku}/storage-cost', [InventoryController::class, 'storageCost']);
    Route::get('/{sku}/analytics', [InventoryController::class, 'analytics']);
    Route::post('/sync-storage-cost', [InventoryController::class, 'syncStorageCost'])->middleware('throttle:sync');
    
    // Защищённые роуты (требуют авторизации через Sellico)
    Route::middleware('sellico.auth')->group(function () {
        Route::post('/sync/{marketplace}', [InventoryController::class, 'sync'])
            ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex'])
            ->middleware('throttle:sync');
    });
});

/*
|--------------------------------------------------------------------------
| Shipments Module (FBO Поставки) — скрыто, отложено на будущие разработки
|--------------------------------------------------------------------------
*/
/* Route::prefix('shipments')->middleware('throttle:api')->group(function () {
    // Список и статистика
    Route::get('/', [ShipmentController::class, 'index']);
    Route::get('/statistics', [ShipmentController::class, 'statistics']);
    Route::get('/stats', [ShipmentController::class, 'stats']); // Legacy
    
    // Синхронизация с Ozon
    Route::post('/sync-from-ozon', [ShipmentController::class, 'syncFromOzon']);
    
    // Склады, слоты и товары
    Route::get('/warehouses', [ShipmentController::class, 'warehouses']);
    Route::get('/products', [ShipmentController::class, 'products']); // Поиск товаров для добавления в поставку
    Route::get('/slots', [ShipmentController::class, 'slots']);
    Route::get('/marketplace-slots', [ShipmentController::class, 'marketplaceSlots']);
    
    // Черновик и реальные слоты от Ozon
    Route::post('/create-draft', [ShipmentController::class, 'createDraft']);
    Route::post('/draft-timeslots', [ShipmentController::class, 'getDraftTimeslots']);
    Route::post('/confirm-draft', [ShipmentController::class, 'confirmDraft']);
    
    // Рекомендации
    Route::get('/recommendations', [ShipmentController::class, 'recommendations']);
    Route::post('/from-recommendation/{recommendationId}', [ShipmentController::class, 'createFromRecommendation']);
    Route::post('/from-inventory-recommendations', [ShipmentController::class, 'createFromInventoryRecommendations']);
    
    // CRUD
    Route::get('/{id}', [ShipmentController::class, 'show']);
    Route::post('/', [ShipmentController::class, 'store']);
    Route::put('/{id}', [ShipmentController::class, 'update']);
    Route::delete('/{id}', [ShipmentController::class, 'destroy']);
    
    // Items management
    Route::post('/{id}/items', [ShipmentController::class, 'addItem']);
    Route::put('/{id}/items/{itemId}', [ShipmentController::class, 'updateItem']);
    Route::delete('/{id}/items/{itemId}', [ShipmentController::class, 'removeItem']);
    Route::get('/items/template', [ShipmentController::class, 'downloadItemsTemplate']);
    Route::post('/items/upload', [ShipmentController::class, 'uploadItemsFile']);
    
    // Workflow
    Route::post('/{id}/submit', [ShipmentController::class, 'submit']);
    Route::post('/{id}/approve', [ShipmentController::class, 'approve']);
    Route::post('/{id}/reject', [ShipmentController::class, 'reject']);
    Route::post('/{id}/send', [ShipmentController::class, 'send']);
    Route::post('/{id}/deliver', [ShipmentController::class, 'deliver']);
    Route::post('/{id}/cancel', [ShipmentController::class, 'cancel']);
    Route::post('/{id}/sync-status', [ShipmentController::class, 'syncStatus']);
    
    // Slots
    Route::post('/{id}/book-slot', [ShipmentController::class, 'bookSlot']);
    
    // Грузоместа и этикетки (FBO)
    Route::get('/{id}/cargoes', [ShipmentController::class, 'getCargoes']);
    Route::post('/{id}/cargoes', [ShipmentController::class, 'createCargoes']);
    Route::post('/{id}/cargoes/sync-ozon', [ShipmentController::class, 'syncCargoesFromOzon']);
    Route::put('/{id}/cargoes/{cargoId}/items', [ShipmentController::class, 'updateCargoItems']);
    Route::post('/{id}/cargoes/labels', [ShipmentController::class, 'createCargoLabels']);
    Route::get('/{id}/cargoes/labels/{taskId}', [ShipmentController::class, 'getCargoLabelsStatus']);
    Route::get('/{id}/cargoes/labels/{fileGuid}/download', [ShipmentController::class, 'downloadCargoLabels']);

    // Документы поставки (ГТД)
    Route::post('/{id}/gtd', [ShipmentController::class, 'uploadGtd']);
    Route::get('/{id}/gtd/download', [ShipmentController::class, 'downloadGtd'])->name('api.shipments.gtd.download');
    Route::get('/{id}/gtd/template', [ShipmentController::class, 'downloadGtdTemplate'])->name('api.shipments.gtd.template');

    // Прогноз стоимости поставки (Ozon)
    Route::post('/{id}/cost-forecast', [ShipmentController::class, 'getCostForecast']);
    
    // Состав заявки
    Route::get('/{id}/bundle', [ShipmentController::class, 'getBundle']);
    
    // Счётчики Ozon
    Route::get('/ozon-counters', [ShipmentController::class, 'ozonCounters']);
    
    // Labels & Export
    Route::get('/{id}/labels', [ShipmentController::class, 'generateLabels'])->middleware('throttle:export');
    Route::get('/{id}/export/pdf', [ShipmentController::class, 'exportPdf'])->middleware('throttle:export');
    Route::get('/{id}/export/csv', [ShipmentController::class, 'exportCsv'])->middleware('throttle:export');
}); */

/*
|--------------------------------------------------------------------------
| Postings Module (FBS Отгрузки)
|--------------------------------------------------------------------------
*/
Route::prefix('postings')->middleware('throttle:api')->group(function () {
    // Список и статистика
    Route::get('/', [App\Http\Controllers\Api\PostingController::class, 'index']);
    Route::get('/statistics', [App\Http\Controllers\Api\PostingController::class, 'statistics']);
    
    // Синхронизация
    Route::post('/sync', [App\Http\Controllers\Api\PostingController::class, 'sync'])->middleware('throttle:sync');
    
    // Массовые операции
    Route::post('/bulk-labels', [App\Http\Controllers\Api\PostingController::class, 'bulkLabels'])->middleware('throttle:export');
    Route::post('/bulk-ship', [App\Http\Controllers\Api\PostingController::class, 'bulkShip']);
    
    // Акты приёма-передачи
    Route::post('/act/create', [App\Http\Controllers\Api\PostingController::class, 'createAct']);
    Route::get('/act/{actId}/download', [App\Http\Controllers\Api\PostingController::class, 'downloadAct'])->middleware('throttle:export');
    
    // CRUD
    Route::get('/{id}', [App\Http\Controllers\Api\PostingController::class, 'show']);
    
    // Workflow
    Route::post('/{id}/assemble', [App\Http\Controllers\Api\PostingController::class, 'assemble']);
    Route::post('/{id}/pack', [App\Http\Controllers\Api\PostingController::class, 'pack']);
    Route::post('/{id}/ship', [App\Http\Controllers\Api\PostingController::class, 'ship']);
    Route::post('/{id}/cancel', [App\Http\Controllers\Api\PostingController::class, 'cancel']);
    
    // Этикетки
    Route::get('/{id}/label', [App\Http\Controllers\Api\PostingController::class, 'label'])->middleware('throttle:export');
    
    // Причины отмены
    Route::get('/cancel-reasons', [App\Http\Controllers\Api\PostingController::class, 'cancelReasons']);
    
    // Возвраты
    Route::get('/returns', [App\Http\Controllers\Api\PostingController::class, 'returns']);
});

/*
|--------------------------------------------------------------------------
| Unit Economics Module (единый API, бывший v2)
|--------------------------------------------------------------------------
*/
Route::prefix('unit-economics')->middleware('throttle:api')->group(function () {
    // Статистика и сравнение (должны быть ДО динамических маршрутов)
    Route::get('/comparison', [UnitEconomicsCacheController::class, 'comparison']);
    Route::get('/product-comparison', [UnitEconomicsCacheController::class, 'productComparison']);
    Route::get('/stats', [UnitEconomicsCacheController::class, 'stats']);
    Route::get('/cache-stats/{integrationId}', [UnitEconomicsCacheController::class, 'cacheStats']);
    
    // Настройки пользователя
    Route::put('/settings/bulk', [UnitEconomicsCacheController::class, 'bulkUpdateSettings'])->middleware('throttle:bulk');
    Route::put('/settings/{sku}', [UnitEconomicsCacheController::class, 'updateSettings'])
        ->where('sku', '.*');
    
    // Синхронизация
    Route::post('/sync/{integrationId}', [UnitEconomicsCacheController::class, 'sync'])->middleware('throttle:sync');
    Route::post('/sync-now/{integrationId}', [UnitEconomicsCacheController::class, 'syncNow'])->middleware('throttle:sync');
    Route::post('/recalculate/{integrationId}', [UnitEconomicsCacheController::class, 'recalculate'])->middleware('throttle:sync');
    
    // Массовое сохранение
    Route::post('/save', [UnitEconomicsCacheController::class, 'save'])->middleware('throttle:bulk');
    
    // Справочники по маркетплейсу
    Route::get('/commissions/{marketplace}', [UnitEconomicsCacheController::class, 'commissions'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market', 'yandex']);
    Route::get('/tariffs/{marketplace}', [UnitEconomicsCacheController::class, 'tariffs'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market', 'yandex']);
    Route::get('/stats/{marketplace}', [UnitEconomicsCacheController::class, 'statsByMarketplace'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market', 'yandex']);
    
    // Расчёт
    Route::post('/calculate/{marketplace}', [UnitEconomicsCacheController::class, 'calculate'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market', 'yandex']);
    
    // Данные по маркетплейсу (динамические маршруты в конце)
    Route::get('/{marketplace}', [UnitEconomicsCacheController::class, 'index'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market', 'yandex']);
    Route::get('/{marketplace}/{sku}', [UnitEconomicsCacheController::class, 'show'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market', 'yandex']);
});

// Legacy v1 Unit Economics удалён — используйте единый /api/unit-economics

/*
|--------------------------------------------------------------------------
| Suppliers Module
|--------------------------------------------------------------------------
*/
Route::prefix('suppliers')->middleware('throttle:api')->group(function () {
    Route::get('/', [SupplierController::class, 'index']);
    Route::get('/{id}', [SupplierController::class, 'show']);
    Route::post('/', [SupplierController::class, 'store']);
    Route::put('/{id}', [SupplierController::class, 'update']);
    Route::delete('/{id}', [SupplierController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Supply Plans Module — скрыто, отложено на будущие разработки
|--------------------------------------------------------------------------
*/
/* Route::prefix('supply-plans')->middleware('throttle:api')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\SupplyPlanController::class, 'index']);
    Route::post('/', [App\Http\Controllers\Api\SupplyPlanController::class, 'store']);
    Route::get('/{id}', [App\Http\Controllers\Api\SupplyPlanController::class, 'show']);
    Route::put('/{id}', [App\Http\Controllers\Api\SupplyPlanController::class, 'update']);
    Route::delete('/{id}', [App\Http\Controllers\Api\SupplyPlanController::class, 'destroy']);
    Route::get('/{id}/calculate', [App\Http\Controllers\Api\SupplyPlanController::class, 'calculate']);
    Route::post('/{id}/approve', [App\Http\Controllers\Api\SupplyPlanController::class, 'approve']);
    Route::post('/{id}/cancel', [App\Http\Controllers\Api\SupplyPlanController::class, 'cancel']);
}); */

/*
|--------------------------------------------------------------------------
| Supply Recommendations Module — скрыто, отложено на будущие разработки
|--------------------------------------------------------------------------
*/
/* Route::prefix('supply-recommendations')->middleware('throttle:api')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\SupplyRecommendationController::class, 'index']);
    Route::get('/stats', [App\Http\Controllers\Api\SupplyRecommendationController::class, 'stats']);
    Route::get('/by-warehouse', [App\Http\Controllers\Api\SupplyRecommendationController::class, 'byWarehouse']);
    Route::post('/generate', [App\Http\Controllers\Api\SupplyRecommendationController::class, 'generate'])->middleware('throttle:sync');
    Route::get('/{id}', [App\Http\Controllers\Api\SupplyRecommendationController::class, 'show']);
    Route::post('/{id}/apply', [App\Http\Controllers\Api\SupplyRecommendationController::class, 'apply']);
    Route::post('/{id}/dismiss', [App\Http\Controllers\Api\SupplyRecommendationController::class, 'dismiss']);
}); */

/*
|--------------------------------------------------------------------------
| Warehouse Slots Module — скрыто, отложено на будущие разработки
|--------------------------------------------------------------------------
*/
/* Route::prefix('warehouse-slots')->middleware('throttle:api')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\WarehouseSlotController::class, 'index']);
    Route::get('/warehouses', [App\Http\Controllers\Api\WarehouseSlotController::class, 'warehouses']);
    Route::get('/for-supply/{supplyId}', [App\Http\Controllers\Api\WarehouseSlotController::class, 'forSupply']);
    Route::post('/sync', [App\Http\Controllers\Api\WarehouseSlotController::class, 'sync'])->middleware('throttle:sync');
    Route::post('/{id}/book', [App\Http\Controllers\Api\WarehouseSlotController::class, 'book']);
    Route::post('/{id}/release', [App\Http\Controllers\Api\WarehouseSlotController::class, 'release']);
}); */

/*
|--------------------------------------------------------------------------
| Ozon Draft/Supply API (Proxy to Ozon Seller API) — скрыто, отложено на будущие разработки
|--------------------------------------------------------------------------
*/
/* Route::prefix('ozon')->middleware('throttle:api')->group(function () {
    // Legacy Draft endpoints (черновики - до 16.02.2026)
    Route::post('/draft/create', [App\Http\Controllers\Api\OzonDraftController::class, 'createDraft']);
    Route::post('/draft/info', [App\Http\Controllers\Api\OzonDraftController::class, 'getDraftInfo']);
    Route::post('/draft/timeslots', [App\Http\Controllers\Api\OzonDraftController::class, 'getTimeslots']);
    Route::post('/draft/supply/create', [App\Http\Controllers\Api\OzonDraftController::class, 'createSupplyFromDraft']);
    Route::post('/draft/items/add', [App\Http\Controllers\Api\OzonDraftController::class, 'addItems']);
    Route::post('/supply/create/status', [App\Http\Controllers\Api\OzonDraftController::class, 'getSupplyCreateStatus']);
    Route::post('/warehouses', [App\Http\Controllers\Api\OzonDraftController::class, 'getWarehouses']);
    
    // New Cluster-based Draft endpoints (с 16.02.2026 - кластерная модель)
    Route::post('/clusters/list', [App\Http\Controllers\Api\OzonDraftController::class, 'getClusters']);
    Route::post('/draft/direct/create', [App\Http\Controllers\Api\OzonDraftController::class, 'createDirectDraft']);
    Route::post('/draft/crossdock/create', [App\Http\Controllers\Api\OzonDraftController::class, 'createCrossdockDraft']);
    Route::post('/draft/multi-cluster/create', [App\Http\Controllers\Api\OzonDraftController::class, 'createMultiClusterDraft']);
    Route::post('/draft/v2/info', [App\Http\Controllers\Api\OzonDraftController::class, 'getDraftInfoV2']);
    Route::post('/draft/v2/timeslots', [App\Http\Controllers\Api\OzonDraftController::class, 'getDraftTimeslotsV2']);
    Route::post('/draft/v2/supply/create', [App\Http\Controllers\Api\OzonDraftController::class, 'createSupplyFromDraftV2']);
    Route::post('/draft/v2/supply/status', [App\Http\Controllers\Api\OzonDraftController::class, 'getSupplyCreateStatusV2']);
    Route::post('/warehouses/fbo/list', [App\Http\Controllers\Api\OzonDraftController::class, 'getFboWarehouses']);
    Route::post('/warehouses/seller/list', [App\Http\Controllers\Api\OzonDraftController::class, 'getSellerWarehouses']);
    Route::post('/cargoes/get', [App\Http\Controllers\Api\OzonDraftController::class, 'getCargoes']);
    
    // Supply Order endpoints (заявки на поставку)
    Route::post('/supply/orders', [App\Http\Controllers\Api\OzonSupplyController::class, 'getSupplyOrders']);
    Route::post('/supply/order', [App\Http\Controllers\Api\OzonSupplyController::class, 'getSupplyOrder']);
    Route::post('/supply/order/details', [App\Http\Controllers\Api\OzonSupplyController::class, 'getSupplyOrderDetailsV1']);
    Route::post('/supply/order/status-counter', [App\Http\Controllers\Api\OzonSupplyController::class, 'getSupplyOrderStatusCounter']);
    
    // Timeslot endpoints (таймслоты)
    Route::post('/supply/order/timeslots', [App\Http\Controllers\Api\OzonSupplyController::class, 'getSupplyOrderTimeslots']);
    Route::post('/supply/order/timeslot/get', [App\Http\Controllers\Api\OzonSupplyController::class, 'getSupplyOrderTimeslot']);
    Route::post('/supply/order/timeslot/update', [App\Http\Controllers\Api\OzonSupplyController::class, 'updateSupplyOrderTimeslot']);
    Route::post('/supply/order/timeslot/status', [App\Http\Controllers\Api\OzonSupplyController::class, 'getSupplyOrderTimeslotStatus']);
    Route::post('/supply/order/timeslot/update/status', [App\Http\Controllers\Api\OzonSupplyController::class, 'getTimeslotUpdateStatus']);

    // Content endpoints (редактирование состава заявки)
    Route::post('/supply/order/content/update', [App\Http\Controllers\Api\OzonSupplyController::class, 'updateSupplyOrderContent']);
    Route::post('/supply/order/content/update/validation', [App\Http\Controllers\Api\OzonSupplyController::class, 'validateSupplyOrderContent']);
    Route::post('/supply/order/content/update/status', [App\Http\Controllers\Api\OzonSupplyController::class, 'getSupplyOrderContentUpdateStatus']);
    
    // Pass endpoints (пропуска - данные водителя и ТС)
    Route::post('/supply/order/pass/create', [App\Http\Controllers\Api\OzonSupplyController::class, 'createSupplyOrderPass']);
    Route::post('/supply/order/pass/status', [App\Http\Controllers\Api\OzonSupplyController::class, 'getPassCreateStatus']);

    // FBP endpoints (акты/черновики)
    Route::post('/fbp/act/create', [App\Http\Controllers\Api\OzonSupplyController::class, 'createFbpAcceptanceAct']);
    Route::post('/fbp/act/status', [App\Http\Controllers\Api\OzonSupplyController::class, 'getFbpAcceptanceActStatus']);
    Route::post('/fbp/draft/direct/timeslot/edit', [App\Http\Controllers\Api\OzonSupplyController::class, 'editFbpDirectDraftTimeslot']);

    // FBO postings
    Route::post('/posting/fbo/list', [App\Http\Controllers\Api\OzonSupplyController::class, 'getFboPostings']);
    Route::post('/posting/fbo/get', [App\Http\Controllers\Api\OzonSupplyController::class, 'getFboPosting']);
    Route::post('/posting/fbo/cancel-reasons', [App\Http\Controllers\Api\OzonSupplyController::class, 'getFboCancelReasons']);

    // Returns/Removal
    Route::post('/returns/list', [App\Http\Controllers\Api\OzonSupplyController::class, 'getReturns']);
    Route::post('/removal/from-stock/list', [App\Http\Controllers\Api\OzonSupplyController::class, 'getRemovalFromStock']);
    Route::post('/removal/from-supply/list', [App\Http\Controllers\Api\OzonSupplyController::class, 'getRemovalFromSupply']);
    
    // Cargoes endpoints (грузоместа)
    Route::post('/supply/cargoes/create', [App\Http\Controllers\Api\OzonSupplyController::class, 'createCargoes']);
    Route::post('/supply/cargoes/info', [App\Http\Controllers\Api\OzonSupplyController::class, 'getCargoesInfo']);
    
    // Labels endpoints (этикетки)
    Route::post('/supply/cargoes/labels/create', [App\Http\Controllers\Api\OzonSupplyController::class, 'createCargoesLabels']);
    Route::post('/supply/cargoes/labels/status', [App\Http\Controllers\Api\OzonSupplyController::class, 'getCargoesLabelsStatus']);
    Route::get('/supply/cargoes/labels/download', [App\Http\Controllers\Api\OzonSupplyController::class, 'downloadCargoesLabels']);
    
    // Warehouses endpoints (склады)
    Route::post('/clusters', [App\Http\Controllers\Api\OzonSupplyController::class, 'getOzonClusters']);
    Route::post('/warehouses/fbo', [App\Http\Controllers\Api\OzonSupplyController::class, 'getOzonFboWarehouses']);
    Route::post('/warehouses/availability', [App\Http\Controllers\Api\OzonSupplyController::class, 'checkWarehouseAvailability']);
}); */

/*
|--------------------------------------------------------------------------
| Supplies Module (Модуль поставок Ozon FBO) — скрыто, отложено на будущие разработки
|--------------------------------------------------------------------------
*/
/* Route::prefix('supplies')->middleware('throttle:api')->group(function () {
    // Рекомендации
    Route::get('/recommendations', [App\Http\Controllers\Api\SupplyController::class, 'getRecommendations']);
    Route::post('/recommendations/calculate', [App\Http\Controllers\Api\SupplyController::class, 'calculateRecommendations']);
    Route::post('/recommendations/{id}/accept', [App\Http\Controllers\Api\SupplyController::class, 'acceptRecommendation']);
    Route::post('/recommendations/{id}/reject', [App\Http\Controllers\Api\SupplyController::class, 'rejectRecommendation']);
    Route::post('/recommendations/{id}/postpone', [App\Http\Controllers\Api\SupplyController::class, 'postponeRecommendation']);
    Route::get('/recommendations/by-sku/{sku}', [App\Http\Controllers\Api\SupplyController::class, 'getRecommendationsBySku']);
    Route::get('/recommendations/summary', [App\Http\Controllers\Api\SupplyController::class, 'getRecommendationsSummary']);
    Route::get('/recommendations/map', [App\Http\Controllers\Api\SupplyController::class, 'getRecommendationsMap']);
    Route::get('/recommendations/map-warehouses', [App\Http\Controllers\Api\SupplyController::class, 'getRecommendationsMapWarehouses']);
    
    // Кластеры и слоты приёмки (новый flow фронтенда)
    Route::get('/clusters', [App\Http\Controllers\Api\SupplyController::class, 'getClusters']);
    Route::get('/clusters/{clusterId}/products', [App\Http\Controllers\Api\SupplyController::class, 'getClusterProducts']);
    Route::post('/clusters/{clusterId}/add-products', [App\Http\Controllers\Api\SupplyController::class, 'addClusterProducts']);
    Route::post('/clusters/{clusterId}/delivery', [App\Http\Controllers\Api\SupplyController::class, 'setClusterDeliveryMethod']);
    Route::post('/clusters/{clusterId}/warehouse', [App\Http\Controllers\Api\SupplyController::class, 'setClusterWarehouse']);
    Route::get('/slots', [App\Http\Controllers\Api\SupplyController::class, 'getSlots']);
    Route::post('/sync-slots', [App\Http\Controllers\Api\SupplyController::class, 'syncSlots']);
    Route::get('/products-for-supply', [App\Http\Controllers\Api\SupplyController::class, 'getProductsForSupply']);
    Route::post('/create-with-slot', [App\Http\Controllers\Api\SupplyController::class, 'createWithSlot']);
    
    // Поставки CRUD
    Route::get('/', [App\Http\Controllers\Api\SupplyController::class, 'index']);
    Route::post('/', [App\Http\Controllers\Api\SupplyController::class, 'store']);
    Route::post('/manual', [App\Http\Controllers\Api\SupplyController::class, 'storeManual']);
    Route::get('/stats', [App\Http\Controllers\Api\SupplyController::class, 'getStats']);
    Route::get('/{id}', [App\Http\Controllers\Api\SupplyController::class, 'show']);
    Route::get('/{id}/events', [App\Http\Controllers\Api\SupplyController::class, 'getEvents']);
    
    // Действия с поставкой
    Route::post('/{id}/create-draft', [App\Http\Controllers\Api\SupplyController::class, 'createDraft']);
    Route::get('/{id}/timeslots', [App\Http\Controllers\Api\SupplyController::class, 'getTimeslots']);
    Route::post('/{id}/book-slot', [App\Http\Controllers\Api\SupplyController::class, 'bookSlot']);
    Route::post('/{id}/start-preparing', [App\Http\Controllers\Api\SupplyController::class, 'startPreparing']);
    Route::post('/{id}/ready-to-ship', [App\Http\Controllers\Api\SupplyController::class, 'markReadyToShip']);
    Route::post('/{id}/ship', [App\Http\Controllers\Api\SupplyController::class, 'markShipped']);
    Route::post('/{id}/cancel', [App\Http\Controllers\Api\SupplyController::class, 'cancel']);
    Route::post('/{id}/sync-status', [App\Http\Controllers\Api\SupplyController::class, 'syncStatus']);
    
    // Настройки
    Route::get('/settings', [App\Http\Controllers\Api\SupplyController::class, 'getSettings']);
    Route::put('/settings', [App\Http\Controllers\Api\SupplyController::class, 'updateSettings']);
    
    // Аналитика
    Route::get('/analytics', [App\Http\Controllers\Api\SupplyController::class, 'getAnalytics']);
    
    // Грузоместа
    Route::get('/{supplyId}/packages', [App\Http\Controllers\Api\SupplyPackageController::class, 'index']);
    Route::post('/{supplyId}/packages', [App\Http\Controllers\Api\SupplyPackageController::class, 'store']);
    Route::get('/{supplyId}/packages/summary', [App\Http\Controllers\Api\SupplyPackageController::class, 'summary']);
    Route::post('/{supplyId}/packages/auto-pack', [App\Http\Controllers\Api\SupplyPackageController::class, 'autoPack']);
    Route::get('/{supplyId}/packages/{packageId}', [App\Http\Controllers\Api\SupplyPackageController::class, 'show']);
    Route::put('/{supplyId}/packages/{packageId}', [App\Http\Controllers\Api\SupplyPackageController::class, 'update']);
    Route::delete('/{supplyId}/packages/{packageId}', [App\Http\Controllers\Api\SupplyPackageController::class, 'destroy']);
    Route::post('/{supplyId}/packages/{packageId}/items', [App\Http\Controllers\Api\SupplyPackageController::class, 'addItem']);
    Route::delete('/{supplyId}/packages/{packageId}/items/{itemId}', [App\Http\Controllers\Api\SupplyPackageController::class, 'removeItem']);
    Route::post('/{supplyId}/packages/{packageId}/pack', [App\Http\Controllers\Api\SupplyPackageController::class, 'pack']);
    Route::post('/{supplyId}/packages/{packageId}/label', [App\Http\Controllers\Api\SupplyDocumentController::class, 'generatePackageLabel']);
    
    // Документы
    Route::get('/{supplyId}/documents', [App\Http\Controllers\Api\SupplyDocumentController::class, 'index']);
    Route::get('/{supplyId}/documents/{documentId}', [App\Http\Controllers\Api\SupplyDocumentController::class, 'show']);
    Route::get('/{supplyId}/documents/{documentId}/download', [App\Http\Controllers\Api\SupplyDocumentController::class, 'download'])->name('api.supplies.documents.download');
    Route::post('/{supplyId}/labels/generate-all', [App\Http\Controllers\Api\SupplyDocumentController::class, 'generateAllLabels']);
    Route::post('/{supplyId}/documents/packing-list', [App\Http\Controllers\Api\SupplyDocumentController::class, 'generatePackingList']);
}); */

/*
|--------------------------------------------------------------------------
| Ozon Delivery Analytics (Clusters, Recommendations) — скрыто, отложено на будущие разработки
|--------------------------------------------------------------------------
*/
/* Route::prefix('ozon/delivery-analytics')->middleware('throttle:api')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\OzonDeliveryAnalyticsController::class, 'index']);
    Route::get('/details', [App\Http\Controllers\Api\OzonDeliveryAnalyticsController::class, 'details']);
    Route::get('/recommendations', [App\Http\Controllers\Api\OzonDeliveryAnalyticsController::class, 'recommendations']);
    Route::get('/clusters', [App\Http\Controllers\Api\OzonDeliveryAnalyticsController::class, 'clusters']);
    Route::get('/by-clusters', [App\Http\Controllers\Api\OzonDeliveryAnalyticsController::class, 'byClusters']);
    Route::get('/by-products', [App\Http\Controllers\Api\OzonDeliveryAnalyticsController::class, 'byProducts']);
}); */

/*
|--------------------------------------------------------------------------
| Auto Supply Plans Module (Автопланирование поставок)
|--------------------------------------------------------------------------
*/
Route::prefix('auto-supply-plans')->middleware('throttle:api')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\AutoSupplyPlanController::class, 'index']);
    Route::post('/', [App\Http\Controllers\Api\AutoSupplyPlanController::class, 'store']);
    Route::get('/{id}', [App\Http\Controllers\Api\AutoSupplyPlanController::class, 'show']);
    Route::post('/{id}/calculate', [App\Http\Controllers\Api\AutoSupplyPlanController::class, 'calculate']);
    Route::get('/{id}/lines', [App\Http\Controllers\Api\AutoSupplyPlanController::class, 'lines']);
    Route::get('/{id}/clusters', [App\Http\Controllers\Api\AutoSupplyPlanController::class, 'clusters']);
    Route::get('/{id}/simulate', [App\Http\Controllers\Api\AutoSupplyPlanController::class, 'simulate']);
    Route::delete('/{id}', [App\Http\Controllers\Api\AutoSupplyPlanController::class, 'destroy']);
    Route::patch('/{planId}/lines/{lineId}', [App\Http\Controllers\Api\AutoSupplyPlanController::class, 'updateLine']);
    Route::get('/{id}/export/ozon', [App\Http\Controllers\Api\AutoSupplyPlanController::class, 'exportOzon'])->middleware('throttle:export');
    Route::get('/{id}/export/wb', [App\Http\Controllers\Api\AutoSupplyPlanController::class, 'exportWb'])->middleware('throttle:export');
});

/*
|--------------------------------------------------------------------------
| Seller Warehouse Stocks (Остатки собственного склада)
|--------------------------------------------------------------------------
*/
Route::prefix('seller-stocks')->middleware('throttle:api')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\SellerWarehouseStockController::class, 'index']);
    Route::get('/catalog', [App\Http\Controllers\Api\SellerWarehouseStockController::class, 'catalog']);
    Route::post('/', [App\Http\Controllers\Api\SellerWarehouseStockController::class, 'upsert']);
    Route::post('/bulk', [App\Http\Controllers\Api\SellerWarehouseStockController::class, 'bulkUpsert']);
    Route::get('/summary', [App\Http\Controllers\Api\SellerWarehouseStockController::class, 'summary']);
    Route::delete('/{id}', [App\Http\Controllers\Api\SellerWarehouseStockController::class, 'destroy']);
});
