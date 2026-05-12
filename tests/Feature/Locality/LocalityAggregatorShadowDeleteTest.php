<?php

namespace Tests\Feature\Locality;

use App\Domains\Locality\Calculation\LocalityAggregator;
use App\Models\LocalityMetricDaily;
use App\Models\OzonOrderUnitEconomics;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Защита от бага, найденного 22.04.2026: если SKU перестал продаваться за
 * последние 28 дней, старый snapshot с orders_count=701 оставался навсегда,
 * а в попапе LocalityExplanationService (считает на лету из таблицы)
 * корректно показывалось «нет продаж, 0 заказов». Расхождение между
 * таблицей и попапом ломало доверие к UI.
 *
 * Shadow-update/delete: runDaily() фиксирует startedAt, обновляет SKU, у
 * которых есть заказы в окне, и удаляет осиротевшие snapshot'ы за ту же
 * (integration, snapshot_date, period_days), чьи updated_at остались < startedAt.
 */
class LocalityAggregatorShadowDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregator_deletes_orphan_snapshot_for_sku_without_orders(): void
    {
        $integrationId = 17;
        $today = Carbon::parse('2026-04-22');

        // Сажаем «осиротевший» snapshot: SKU, у которого раньше были заказы,
        // а теперь их нет (или уехали за окно 28 дней).
        $orphan = LocalityMetricDaily::create([
            'integration_id' => $integrationId,
            'sku' => '1960/black1',
            'snapshot_date' => $today->toDateString(),
            'period_days' => 28,
            'orders_count' => 701,
            'local_orders_count' => 0,
            'non_local_orders_count' => 0,
            'local_share_percent' => 0,
            'revenue_total' => 0,
            'base_logistics_total' => 0,
            'non_local_markup_total' => 0,
            'overpayment_amount' => 124980.40,
            'lost_margin_amount' => 0,
            'avg_base_tariff' => 0,
            'avg_markup_percent' => 0,
            'factual_orders_count' => 0,
            'estimate_orders_count' => 0,
            'calculation_confidence' => 'low',
        ]);

        // Искусственно состариваем updated_at — shadow-delete должен убить строку.
        $orphan->update(['updated_at' => $today->copy()->subHour()]);

        // В `ozon_order_unit_economics` ни одной записи за окно: SKU не активен.
        $this->assertSame(
            0,
            OzonOrderUnitEconomics::where('integration_id', $integrationId)
                ->where('sku', '1960/black1')
                ->where('order_date', '>=', $today->copy()->subDays(28))
                ->count()
        );

        $aggregator = app(LocalityAggregator::class);
        $result = $aggregator->runDaily($integrationId, $today, 28);

        $this->assertGreaterThanOrEqual(
            1,
            $result['sku_orphans_pruned'],
            'runDaily должен вернуть счётчик pruned-сирот'
        );

        $this->assertNull(
            LocalityMetricDaily::where('integration_id', $integrationId)
                ->where('sku', '1960/black1')
                ->where('snapshot_date', $today->toDateString())
                ->where('period_days', 28)
                ->first(),
            'Осиротевший snapshot без заказов в окне должен быть удалён'
        );
    }

    public function test_aggregator_does_not_delete_snapshots_of_other_periods(): void
    {
        $integrationId = 17;
        $today = Carbon::parse('2026-04-22');

        $otherPeriod = LocalityMetricDaily::create([
            'integration_id' => $integrationId,
            'sku' => '1960/black1',
            'snapshot_date' => $today->toDateString(),
            'period_days' => 7, // другой period
            'orders_count' => 50,
            'local_orders_count' => 10,
            'non_local_orders_count' => 40,
            'local_share_percent' => 20,
            'revenue_total' => 0,
            'base_logistics_total' => 0,
            'non_local_markup_total' => 0,
            'overpayment_amount' => 0,
            'lost_margin_amount' => 0,
            'avg_base_tariff' => 0,
            'avg_markup_percent' => 0,
            'factual_orders_count' => 0,
            'estimate_orders_count' => 0,
            'calculation_confidence' => 'low',
        ]);
        $otherPeriod->update(['updated_at' => $today->copy()->subHour()]);

        app(LocalityAggregator::class)->runDaily($integrationId, $today, 28);

        $this->assertNotNull(
            LocalityMetricDaily::where('integration_id', $integrationId)
                ->where('sku', '1960/black1')
                ->where('period_days', 7)
                ->first(),
            'Prune должен фильтроваться по period_days — snapshot другого периода трогать нельзя'
        );
    }

    public function test_aggregator_uses_last_28_completed_days_like_ozon_widget(): void
    {
        $integrationId = 59;
        $snapshotDate = Carbon::parse('2026-05-04');
        $sku = '5690/black-white';

        $this->createOzonOrderUnitEconomics($integrationId, $sku, '2026-04-05 12:00:00');
        $this->createOzonOrderUnitEconomics($integrationId, $sku, '2026-04-06 12:00:00');
        $this->createOzonOrderUnitEconomics($integrationId, $sku, '2026-05-03 12:00:00');
        $this->createOzonOrderUnitEconomics($integrationId, $sku, '2026-05-04 12:00:00');

        app(LocalityAggregator::class)->runDaily($integrationId, $snapshotDate, 28);

        $metric = LocalityMetricDaily::where('integration_id', $integrationId)
            ->where('sku', $sku)
            ->where('snapshot_date', $snapshotDate->toDateString())
            ->where('period_days', 28)
            ->first();

        $this->assertNotNull($metric);
        $this->assertSame(2, $metric->orders_count);
    }

    private function createOzonOrderUnitEconomics(int $integrationId, string $sku, string $orderDate): void
    {
        OzonOrderUnitEconomics::create([
            'integration_id' => $integrationId,
            'posting_id' => (string) Str::uuid(),
            'posting_item_id' => (string) Str::uuid(),
            'posting_number' => (string) Str::uuid(),
            'sku' => $sku,
            'offer_id' => $sku,
            'order_date' => $orderDate,
            'sale_price' => 1000,
            'shipping_cluster_name' => 'Москва, МО и Дальние регионы',
            'destination_cluster_name' => 'Москва, МО и Дальние регионы',
            'base_logistics_tariff' => 100,
            'non_local_markup_percent' => 0,
            'non_local_markup_amount' => 0,
            'markup_applied' => false,
            'calculation_mode' => 'factual',
            'calculation_confidence' => 'high',
        ]);
    }
}
