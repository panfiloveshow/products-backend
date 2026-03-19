<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateProductsIntegrationId extends Command
{
    protected $signature = 'products:update-integration-id';
    protected $description = 'Проставляет integration_id для товаров на основе marketplace';

    public function handle()
    {
        $this->info('Начинаем обновление integration_id для товаров...');

        // Получаем первую интеграцию для каждого маркетплейса
        $integrations = DB::table('integrations')
            ->select('id', 'marketplace')
            ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex', 'yandex_market'])
            ->get()
            ->groupBy('marketplace')
            ->map(fn($group) => $group->first());

        $updated = 0;
        $skipped = 0;

        foreach ($integrations as $marketplace => $integration) {
            // Получаем товары без integration_id
            $products = DB::table('products')
                ->where('marketplace', $marketplace)
                ->whereNull('integration_id')
                ->select('id', 'marketplace_id')
                ->get();

            foreach ($products as $product) {
                // Проверяем нет ли уже товара с таким marketplace_id и другим integration_id
                $exists = DB::table('products')
                    ->where('marketplace', $marketplace)
                    ->where('marketplace_id', $product->marketplace_id)
                    ->whereNotNull('integration_id')
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                // Обновляем integration_id — игнорируем конфликт уникального индекса
                $affected = DB::table('products')
                    ->where('id', $product->id)
                    ->whereNull('integration_id')
                    ->updateOrIgnore(['integration_id' => $integration->id]);

                if ($affected) {
                    $updated++;
                } else {
                    $skipped++;
                }
            }

            $this->info("Обработано товаров для {$marketplace}: обновлено {$updated}, пропущено {$skipped}");
        }

        $this->info("Всего обновлено товаров: {$updated}, пропущено: {$skipped}");
        return 0;
    }
}
