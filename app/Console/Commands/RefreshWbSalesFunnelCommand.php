<?php

namespace App\Console\Commands;

use App\Domains\Wildberries\Api\ProductsApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use App\Jobs\RecalculateUnitEconomicsCacheJob;
use App\Models\Integration;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ротация воронки продаж WB (% выкупа) по протухшести.
 *
 * Проблема: воронка тянется только внутри полного синка товаров, а её квота у WB
 * ~час и делится с financial-dashboard. В итоге свежесть выкупа была лотереей:
 * у одних магазинов данные сегодняшние, у других — трёхнедельные (наблюдали
 * разброс 29.07–24.08), и клиенты сверяли юнитку с живым кабинетом впустую.
 *
 * Решение: каждый час обновлять воронку у N магазинов с САМОЙ старой
 * redemption_observed_at — за сутки очередь гарантированно обходит все
 * WB-магазины. Свежий выкуп кладётся в products.wb_data (как это делает синк
 * товаров), точечно — в unit_economics, и ставится пересчёт кэша юнитки.
 */
class RefreshWbSalesFunnelCommand extends Command
{
    protected $signature = 'wb:refresh-sales-funnel
        {--integration= : Обновить конкретную интеграцию (вне очереди)}
        {--limit=2 : Сколько самых протухших магазинов обновить за прогон}';

    protected $description = 'Обновляет % выкупа из воронки WB у самых протухших магазинов (ротация под квоту API)';

    public function handle(): int
    {
        $integrationId = $this->option('integration');
        if ($integrationId !== null) {
            $integration = Integration::find((int) $integrationId);
            if (! $integration || $integration->marketplace !== 'wildberries') {
                $this->error('Интеграция не найдена или не Wildberries');

                return self::FAILURE;
            }
            $this->refreshIntegration($integration);

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        // Очередь по протухшести: max(redemption_observed_at) по товарам магазина.
        // NULL (воронки не было никогда) — самые первые.
        $staleness = DB::table('products')
            ->where('marketplace', 'wildberries')
            ->whereNotNull('integration_id')
            ->selectRaw("integration_id, max(wb_data->>'redemption_observed_at') as latest")
            ->groupBy('integration_id')
            ->get()
            ->sortBy(fn ($row) => $row->latest ?? '');

        // Фильтры — ПОСЛЕ сортировки, слоты добираются следующими кандидатами.
        // Раньше take() шёл до фильтров: магазины с мёртвыми кредами или вечно
        // пустой воронкой (observed_at не двигается by design) занимали оба слота
        // каждый час, и живые магазины не обновлялись неделями (int 85 — с 29.07).
        // Метка «недавно пробовали» (4 ч) выкидывает пустых из головы очереди.
        $picked = 0;
        foreach ($staleness as $row) {
            if ($picked >= $limit) {
                break;
            }
            $attemptKey = "wb_funnel_attempt_{$row->integration_id}";
            if (\Illuminate\Support\Facades\Cache::has($attemptKey)) {
                continue;
            }
            $integration = Integration::find($row->integration_id);
            if (! $integration || ! $integration->is_active || ! $integration->hasUsableCredentials()) {
                continue;
            }
            \Illuminate\Support\Facades\Cache::put($attemptKey, now()->toIso8601String(), now()->addHours(4));
            $picked++;
            $this->line("Интеграция #{$integration->id} ({$integration->name}): воронка от ".($row->latest ?: 'никогда'));
            $this->refreshIntegration($integration);
        }

        return self::SUCCESS;
    }

    private function refreshIntegration(Integration $integration): void
    {
        try {
            $creds = $integration->resolveCredentials();
            if (empty($creds['api_key'])) {
                $this->warn('  Нет credentials — пропуск');

                return;
            }

            $nmIds = Product::query()
                ->where('integration_id', $integration->id)
                ->where('marketplace', 'wildberries')
                ->get(['id', 'sku', 'wb_data'])
                ->map(fn ($p) => (int) (($p->wb_data['nmID'] ?? 0)))
                ->filter()
                ->unique()
                ->values()
                ->all();
            if ($nmIds === []) {
                $this->warn('  Нет nmID у товаров — пропуск');

                return;
            }

            $productsApi = new ProductsApi(new WildberriesClient($creds['api_key']));
            $ratings = $productsApi->getCardRatings($nmIds);
            // Считаем прогон успешным только если воронка реально вернула выкуп:
            // пустой/бесполезный ответ (квота) не должен обновлять observed_at.
            $withRedemption = array_filter($ratings, fn ($r) => isset($r['redemption_rate']));
            if ($withRedemption === []) {
                $this->warn('  Воронка не вернула выкуп (квота WB?) — данные не трогаем');

                return;
            }

            $updatedProducts = 0;
            $updatedUe = 0;
            Product::query()
                ->where('integration_id', $integration->id)
                ->where('marketplace', 'wildberries')
                ->chunkById(200, function ($products) use ($ratings, $integration, &$updatedProducts, &$updatedUe) {
                    foreach ($products as $product) {
                        $wbData = is_array($product->wb_data) ? $product->wb_data : [];
                        $nmId = (string) ($wbData['nmID'] ?? '');
                        $entry = $ratings[$nmId] ?? null;
                        // Товары без свежей записи в воронке не трогаем (preserve).
                        if (! is_array($entry) || ! isset($entry['redemption_rate'])) {
                            continue;
                        }

                        $product->wb_data = array_merge($wbData, array_intersect_key($entry, array_flip([
                            'redemption_rate',
                            'redemption_orders_count',
                            'redemption_buyouts_count',
                            'redemption_source',
                            'redemption_observed_at',
                            'productRating',
                            'feedbackRating',
                        ])));
                        if ($product->isDirty('wb_data')) {
                            $product->save();
                            $updatedProducts++;
                        }

                        // Точечно доносим до юнитки, не дожидаясь ночного синка.
                        // Ручные значения не перетираем. Производные (эфф. логистика,
                        // прибыль) пересчитает RecalculateUnitEconomicsCacheJob ниже.
                        $updatedUe += DB::table('unit_economics')
                            ->where('integration_id', $integration->id)
                            ->where('sku', $product->sku)
                            ->where(function ($q) {
                                $q->whereNull('redemption_source')->orWhere('redemption_source', '!=', 'manual');
                            })
                            ->update([
                                'redemption_rate' => round((float) $entry['redemption_rate'], 2),
                                'redemption_source' => 'wb_sales_funnel',
                                'updated_at' => now(),
                            ]);
                    }
                });

            $this->info("  Воронка: {$updatedProducts} товаров, {$updatedUe} UE-строк обновлено");
            Log::info('WB sales funnel refreshed', [
                'integration_id' => $integration->id,
                'products' => $updatedProducts,
                'ue_rows' => $updatedUe,
            ]);

            if ($updatedProducts > 0) {
                RecalculateUnitEconomicsCacheJob::dispatch($integration->id);
            }
        } catch (\Throwable $e) {
            $this->warn('  Ошибка: '.$e->getMessage());
            Log::warning('WB sales funnel refresh failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
