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
            ->whereIn('marketplace', ['wildberries', 'ozon', 'yandex'])
            ->get()
            ->groupBy('marketplace')
            ->map(fn($group) => $group->first());

        $updated = 0;

        foreach ($integrations as $marketplace => $integration) {
            $count = DB::table('products')
                ->where('marketplace', $marketplace)
                ->whereNull('integration_id')
                ->update(['integration_id' => $integration->id]);

            $this->info("Обновлено {$count} товаров для {$marketplace} (integration_id={$integration->id})");
            $updated += $count;
        }

        $this->info("Всего обновлено товаров: {$updated}");
        return 0;
    }
}
