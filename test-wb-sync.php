#!/usr/bin/env php
<?php

define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Domains\Wildberries\Api\InventoryApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use App\Models\Integration;

echo "=== Тестирование Wildberries остатков ===\n\n";

// Получаем первую активную WB интеграцию
$integration = Integration::where('marketplace', 'wildberries')
    ->where('is_active', true)
    ->first();

if (! $integration) {
    echo "❌ Не найдено активных Wildberries интеграций\n";
    exit(1);
}

echo "✅ Интеграция найдена: {$integration->name} (ID: {$integration->id})\n\n";

try {
    $credentials = $integration->getDecryptedCredentials();
    $apiKey = $credentials['api_key'] ?? null;

    if (empty($apiKey)) {
        echo "❌ API ключ пустой!\n";
        exit(1);
    }

    echo '🔑 API ключ: '.substr($apiKey, 0, 10)."...\n\n";

    // Тестируем Inventory API
    echo "📦 Тестирование Inventory API...\n";
    $client = new WildberriesClient($apiKey);
    $inventoryApi = new InventoryApi($client);

    // Пробуем получить склады
    echo "  - Получение списка складов...\n";
    $warehouses = $inventoryApi->getWarehouses($integration);
    echo '    Склады найдены: '.count($warehouses)."\n";

    if (! empty($warehouses)) {
        foreach (array_slice($warehouses, 0, 3) as $wh) {
            echo "    • {$wh['name']} (ID: {$wh['id']})\n";
        }
    }
    echo "\n";

    // Пробуем получить остатки
    echo "  - Получение остатков...\n";
    $stocks = $inventoryApi->getStocks($integration);
    echo '    Остатки найдены: '.count($stocks)." SKU\n";

    if (! empty($stocks)) {
        $totalQty = array_sum(array_column($stocks, 'total'));
        echo "    Общее количество: {$totalQty} шт.\n";

        // Показываем первые 3
        echo "\n    Примеры:\n";
        foreach (array_slice($stocks, 0, 3) as $stock) {
            $sku = isset($stock['sku']) ? (string) $stock['sku'] : 'N/A';
            $qty = isset($stock['total']) ? $stock['total'] : 0;
            echo "    - {$sku}: {$qty} шт.\n";
        }
    } else {
        echo "    ❌ Остатки не получены!\n";
    }
    echo "\n";

    // Проверяем FBS склады
    echo "📦 Проверка FBS складов...\n";
    $fbsStocks = $inventoryApi->getStocksFromFbsWarehousesDirect($integration);
    echo '    FBS остатки: '.count($fbsStocks)." SKU\n";
    if (! empty($fbsStocks)) {
        $fbsQty = array_sum(array_column($fbsStocks, 'total'));
        echo "    FBS количество: {$fbsQty} шт.\n";
    }
    echo "\n";

    echo "✅ Тест завершён!\n";

} catch (\Exception $e) {
    echo '❌ Ошибка: '.$e->getMessage()."\n";
    echo '   Файл: '.$e->getFile().':'.$e->getLine()."\n";
    echo "\nStack trace:\n".$e->getTraceAsString()."\n";
    exit(1);
}
