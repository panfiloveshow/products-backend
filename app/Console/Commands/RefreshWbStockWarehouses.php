<?php

namespace App\Console\Commands;

use App\Domains\Wildberries\Api\InventoryApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use App\Jobs\RecalculateUnitEconomicsCacheJob;
use App\Models\Integration;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Обновляет wb_data.stock_warehouses — разбивку остатков по реальным складам WB
 * (Коледино, Электросталь, Невинномысск…) — отдельно от тяжёлого синка товаров.
 *
 * Зачем: отчёт Statistics API /api/v1/supplier/stocks лимитирован ~1 запрос/мин
 * на токен. Внутри полного синка лимит часто уже сожжён (sales/orders,
 * локализация ИЛ/ИРП), отчёт молча пустеет, и склады товара деградируют до
 * FBS-«Мой склад» — КС юнит-экономики теряет разбивку по складам (поле = средний
 * КС магазина у всех товаров, тултип без реальных складов). Здесь — один запрос
 * с агрессивным ретраем, затем пересчёт кэша юнит-экономики.
 */
class RefreshWbStockWarehouses extends Command
{
    protected $signature = 'wb:refresh-stock-warehouses
                            {--integration= : ID интеграции}
                            {--all : Все активные WB интеграции}';

    protected $description = 'Обновить разбивку остатков по складам WB (КС юнит-экономики)';

    public function handle(): int
    {
        $query = Integration::query()->where('marketplace', 'wildberries');
        if ($id = $this->option('integration')) {
            $query->where('id', (int) $id);
        } elseif ($this->option('all')) {
            $query->where('is_active', true);
        } else {
            $this->error('Укажите --integration=ID или --all');

            return self::FAILURE;
        }

        foreach ($query->get() as $integration) {
            try {
                $this->refreshIntegration($integration);
            } catch (\Throwable $e) {
                $this->warn("  Интеграция #{$integration->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function refreshIntegration(Integration $integration): void
    {
        $this->info("Интеграция #{$integration->id} ({$integration->name})...");

        $apiKey = $integration->credentials['api_key'] ?? null;
        if (! $apiKey) {
            $sellico = new \App\Services\SellicoApiService;
            $result = $sellico->getIntegrationById($integration->id);
            $apiKey = $result['credentials']['api_key'] ?? null;
        }
        if (! $apiKey) {
            $this->warn('  Нет credentials — пропуск');

            return;
        }

        $inventory = new InventoryApi(new WildberriesClient($apiKey));
        // 4 ретрая по 65с: команда живёт отдельно от синка и может позволить себе ждать.
        $rows = $inventory->getStocksReport(retriesOn429: 4);

        if (empty($rows)) {
            $this->warn('  Отчёт остатков пуст (лимит/права токена) — данные не трогаем');

            return;
        }

        // Группируем строки отчёта по barcode (для WB sku товара = barcode).
        $byBarcode = [];
        foreach ($rows as $item) {
            $barcode = (string) ($item['barcode'] ?? '');
            $qty = (int) ($item['quantity'] ?? 0);
            if ($barcode === '' || $qty <= 0) {
                continue;
            }
            $byBarcode[$barcode][] = [
                'warehouse_id' => $item['warehouseId'] ?? null,
                'warehouse_name' => $item['warehouseName'] ?? 'Unknown',
                'region_name' => $item['regionName'] ?? null,
                'quantity' => $qty,
                'quantityFull' => $item['quantityFull'] ?? $qty,
                'inWayToClient' => $item['inWayToClient'] ?? 0,
                'inWayFromClient' => $item['inWayFromClient'] ?? 0,
                'fulfillment_type' => 'FBO',
            ];
        }

        $updated = 0;
        Product::query()
            ->where('integration_id', $integration->id)
            ->where('marketplace', 'wildberries')
            ->chunkById(200, function ($products) use ($byBarcode, &$updated) {
                foreach ($products as $product) {
                    $wbData = is_array($product->wb_data) ? $product->wb_data : [];
                    $existing = is_array($wbData['stock_warehouses'] ?? null) ? $wbData['stock_warehouses'] : [];
                    // FBS-склады продавца оставляем как есть, FBO-разбивку заменяем
                    // свежим отчётом целиком (отсутствие в отчёте = остаток 0).
                    $fbs = array_values(array_filter($existing, static fn ($w) => is_array($w)
                        && strtoupper((string) ($w['fulfillment_type'] ?? 'FBO')) !== 'FBO'));
                    $fbo = $byBarcode[(string) $product->sku] ?? [];
                    $wbData['stock_warehouses'] = array_merge($fbo, $fbs);
                    unset($wbData['stock_warehouses_fbo_stale']);
                    $product->wb_data = $wbData;
                    if ($product->isDirty('wb_data')) {
                        $product->save();
                        $updated++;
                    }
                }
            });

        $this->info('  Товаров обновлено: '.$updated.' (barcode в отчёте: '.count($byBarcode).')');

        if ($updated > 0) {
            RecalculateUnitEconomicsCacheJob::dispatch($integration->id);
            $this->info('  Пересчёт кэша юнит-экономики поставлен в очередь');
        }
    }
}
