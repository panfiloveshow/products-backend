#!/usr/bin/env php
<?php

// Простой скрипт для тестирования Yandex Market синхронизации

define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Domains\YandexMarket\YandexMarketMarketplace;
use App\Models\Integration;

echo "=== Тестирование Yandex Market API ===\n\n";

// Получаем первую активную Yandex интеграцию
$integration = Integration::where('marketplace', 'yandex_market')
    ->where('is_active', true)
    ->first();

if (! $integration) {
    echo "❌ Не найдено активных Yandex Market интеграций\n";
    exit(1);
}

echo "✅ Интеграция найдена: {$integration->name} (ID: {$integration->id})\n\n";

try {
    // Создаём клиент
    echo "🔄 Подключение к Yandex Market API...\n";
    $marketplace = new YandexMarketMarketplace($integration->getDecryptedCredentials());
    
    // Получаем товары
    echo "📦 Получение товаров...\n";
    $products = $marketplace->getProducts();
    
    echo "✅ Товаров получено: " . count($products) . "\n\n";
    
    // Проверяем наличие цен и остатков
    $withPrice = 0;
    $withStock = 0;
    $totalStock = 0;
    
    foreach ($products as $product) {
        if (! empty($product['price'])) {
            $withPrice++;
        }
        if (! empty($product['stock']) && $product['stock'] > 0) {
            $withStock++;
            $totalStock += $product['stock'];
        }
    }
    
    echo "📊 Статистика:\n";
    echo "  - Товаров с ценой: {$withPrice} из " . count($products) . "\n";
    echo "  - Товаров в наличии: {$withStock} из " . count($products) . "\n";
    echo "  - Общий остаток: {$totalStock} шт.\n\n";
    
    // Показываем первые 3 товара
    echo "📋 Примеры товаров:\n";
    foreach (array_slice($products, 0, 3) as $i => $product) {
        echo "\n" . ($i + 1) . ". {$product['name']}\n";
        echo "   SKU: {$product['sku']}\n";
        echo "   Цена: " . ($product['price'] ? number_format($product['price'], 2) : '—') . " ₽\n";
        echo "   Остаток: {$product['stock']} шт.\n";
        echo "   Marketplace ID: {$product['marketplace_id']}\n";
    }
    
    echo "\n✅ Тест завершён успешно!\n";
    
} catch (\Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "   " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
