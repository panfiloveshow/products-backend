#!/usr/bin/env php
<?php

define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Domains\Wildberries\WildberriesMarketplace;
use App\Models\Integration;

echo "=== Тестирование Wildberries API ===\n\n";

// Получаем все WB интеграции
$integrations = Integration::where('marketplace', 'wildberries')->get();

if ($integrations->isEmpty()) {
    echo "❌ Не найдено Wildberries интеграций\n";
    exit(1);
}

echo 'Найдено интеграций: '.count($integrations)."\n\n";

foreach ($integrations as $integration) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Интеграция: {$integration->name}\n";
    echo "ID: {$integration->id}\n";
    echo 'Активна: '.($integration->is_active ? '✅ YES' : '❌ NO')."\n\n";

    try {
        $credentials = $integration->getDecryptedCredentials();
        $apiKey = $credentials['api_key'] ?? null;

        if (empty($apiKey)) {
            echo "  ❌ API ключ пустой!\n\n";

            continue;
        }

        echo '  🔑 API ключ: '.substr($apiKey, 0, 8).'...'.substr($apiKey, -4)."\n";

        // Тестируем Marketplace API (получение складов)
        echo "  📦 Тест /api/v3/warehouses...\n";
        $client = WildberriesMarketplace::fromIntegration($integration);

        if (method_exists($client, 'getWarehouses')) {
            $warehouses = $client->getWarehouses();
            echo '    Склады найдены: '.count($warehouses)."\n";

            if (! empty($warehouses)) {
                echo "    ✅ SUCCESS! Первый склад:\n";
                $first = $warehouses[0];
                echo "      - ID: {$first['id']}\n";
                echo "      - Name: {$first['name']}\n";
            } else {
                echo "    ❌ Пусто (возможно нет FBS складов)\n";
            }
        } else {
            echo "    ⚠️  Метод getWarehouses не найден\n";
        }

    } catch (\Exception $e) {
        echo '  ❌ Ошибка: '.$e->getMessage()."\n";
    }

    echo "\n";
}

echo "✅ Тест завершён!\n";
