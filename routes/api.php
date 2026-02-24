<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CostPriceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\UnitEconomicsController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

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
    Route::get('/', [UnitEconomicsController::class, 'index']);
    Route::get('/comparison', [UnitEconomicsController::class, 'comparison']);
    Route::get('/stats', [UnitEconomicsController::class, 'stats']);
    
    Route::get('/commissions/{marketplace}', [UnitEconomicsController::class, 'commissions'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);
    Route::get('/tariffs/{marketplace}', [UnitEconomicsController::class, 'tariffs'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);
    
    Route::get('/stats/{marketplace}', [UnitEconomicsController::class, 'statsByMarketplace'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);
    
    Route::get('/{marketplace}', [UnitEconomicsController::class, 'byMarketplace'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);
    Route::get('/{marketplace}/{sku}', [UnitEconomicsController::class, 'show'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);
    Route::post('/{marketplace}', [UnitEconomicsController::class, 'store'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);
    Route::put('/{marketplace}/{id}', [UnitEconomicsController::class, 'update'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);
    Route::delete('/{marketplace}/{id}', [UnitEconomicsController::class, 'destroy'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);
    
    Route::post('/calculate/{marketplace}', [UnitEconomicsController::class, 'calculate'])
        ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex_market']);
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
