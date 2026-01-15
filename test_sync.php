<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Integration;
use App\Models\SyncLog;
use App\Jobs\SyncProductsJob;
use App\Jobs\SyncInventoryJob;

echo "🧹 База данных очищена\n\n";

// Создаём тестовую интеграцию Ozon
echo "📦 Создание тестовой интеграции Ozon...\n";

$integration = Integration::create([
    'name' => 'Test Ozon Integration',
    'marketplace' => 'ozon',
    'is_active' => true,
    'credentials' => [
        'client_id' => env('OZON_CLIENT_ID', '1453000'),
        'api_key' => env('OZON_API_KEY', 'test-key'),
    ],
]);

echo "✅ Интеграция создана: ID {$integration->id}\n\n";

// Создаём SyncLog для синхронизации товаров
echo "🔄 Запуск синхронизации товаров...\n";

$productsSyncLog = SyncLog::create([
    'sync_type' => 'products',
    'marketplace' => 'ozon',
    'integration_id' => $integration->id,
    'status' => 'pending',
    'credentials' => $integration->credentials,
]);

try {
    $job = new SyncProductsJob($productsSyncLog);
    $job->handle();
    echo "✅ Синхронизация товаров завершена\n";
    echo "   Статус: {$productsSyncLog->fresh()->status}\n";
    echo "   Синхронизировано: {$productsSyncLog->fresh()->synced_count}\n";
    echo "   Ошибок: {$productsSyncLog->fresh()->failed_count}\n\n";
} catch (\Exception $e) {
    echo "❌ Ошибка синхронизации товаров: {$e->getMessage()}\n\n";
}

// Создаём SyncLog для синхронизации остатков
echo "📊 Запуск синхронизации остатков...\n";

$inventorySyncLog = SyncLog::create([
    'sync_type' => 'inventory',
    'marketplace' => 'ozon',
    'integration_id' => $integration->id,
    'status' => 'pending',
    'credentials' => $integration->credentials,
]);

try {
    $job = new SyncInventoryJob($inventorySyncLog);
    $job->handle();
    echo "✅ Синхронизация остатков завершена\n";
    echo "   Статус: {$inventorySyncLog->fresh()->status}\n";
    echo "   Синхронизировано: {$inventorySyncLog->fresh()->synced_count}\n";
    echo "   Ошибок: {$inventorySyncLog->fresh()->failed_count}\n\n";
} catch (\Exception $e) {
    echo "❌ Ошибка синхронизации остатков: {$e->getMessage()}\n\n";
}

// Показываем статистику
echo "📈 Итоговая статистика:\n";
echo "   Товаров в БД: " . \App\Models\Product::count() . "\n";
echo "   Остатков на складах: " . \App\Models\InventoryWarehouse::count() . "\n";
echo "   Записей истории: " . \App\Models\InventoryHistory::count() . "\n";

echo "\n✨ Тестирование завершено!\n";
