<?php

namespace Tests\Feature;

use App\Jobs\CalculateAutoSupplyPlanJob;
use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\InventoryWarehouse;
use App\Models\OzonWarehouseCluster;
use App\Models\PlanningFactSnapshot;
use App\Models\Product;
use App\Models\Supply;
use App\Models\SupplyItem;
use App\Models\SupplySettings;
use App\Models\UnitEconomics;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Сквозной прогон расчётного ядра: посев фактов → CalculateAutoSupplyPlanJob → строки плана.
 *
 * Отдельно от AutoSupplyPlanCalculationTest, который проверяет формулы в отрыве
 * от пайплайна. Здесь важно, что план действительно собирается из данных БД,
 * воспроизводим и не задваивает «в пути».
 */
class OzonAutoSupplyPlanCalculationPipelineTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Внешние вызовы Ozon в расчёте необязательны: без них спрос берётся
        // из инвентаря. Ни один тест не должен ходить в сеть.
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 200)]);
    }

    public function test_pipeline_builds_cluster_lines_from_seeded_facts(): void
    {
        $integration = $this->seedOzonFacts();
        $plan = $this->runCalculation($integration);

        $this->assertSame(AutoSupplyPlan::STATUS_READY, $plan->status, (string) $plan->error_message);
        $this->assertNull($plan->error_message);

        $lines = $plan->lines()->get();
        $this->assertGreaterThan(0, $lines->count());

        // Каждая строка привязана к макролокальному кластеру, а не к складу.
        foreach ($lines as $line) {
            $this->assertNotNull($line->cluster_id, 'Строка без кластера: ' . $line->sku);
            $this->assertContains((string) $line->cluster_id, ['101', '202']);
        }

        // Два склада Москвы схлопнулись в один кластер, Казань — отдельный.
        $moscow = $lines->where('sku', 'SKU-A')->where('cluster_id', 101);
        $this->assertCount(1, $moscow, 'SKU-A в Москве обязан быть одной кластерной строкой');
        $this->assertSame(15, (int) $moscow->first()->current_stock, 'Остаток кластера = сумма складов');
        $this->assertSame(10, (int) $moscow->first()->in_transit, '«В пути» кластера = сумма складов');
        $this->assertGreaterThan(0, (int) $moscow->first()->qty_rounded);

        $this->assertCount(1, $lines->where('sku', 'SKU-A')->where('cluster_id', 202));
        $this->assertCount(1, $lines->where('sku', 'SKU-B')->where('cluster_id', 101));

        // Снимок фактов завершён — без него план нельзя валидировать.
        $snapshot = PlanningFactSnapshot::query()->where('auto_supply_plan_id', $plan->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('ready', $snapshot->status);
    }

    public function test_same_facts_produce_the_same_plan(): void
    {
        $integration = $this->seedOzonFacts();

        $first = $this->planFingerprint($this->runCalculation($integration));
        $second = $this->planFingerprint($this->runCalculation($integration));

        $this->assertSame($first, $second, 'Один и тот же снимок фактов обязан давать тот же план');
    }

    /**
     * Срок поставки входит в расчёт через страховой запас (SS = z × σ × √lead_time),
     * а не в целевое покрытие: формула из плана `forecast × (lead_time + cover)`
     * в коде не реализована. Тест фиксирует фактическое поведение.
     */
    public function test_lead_time_change_propagates_into_safety_stock(): void
    {
        $integration = $this->seedOzonFacts(volatileDemand: true);
        $wideCover = ['horizon_days' => 90, 'max_cover_days' => 90];

        SupplySettings::updateOrCreate(
            ['integration_id' => $integration->id],
            ['default_lead_time_days' => 3, 'safety_stock_days' => 0]
        );
        $short = $this->explainOf($this->runCalculation($integration, [], $wideCover), 'SKU-A');

        SupplySettings::updateOrCreate(
            ['integration_id' => $integration->id],
            ['default_lead_time_days' => 25, 'safety_stock_days' => 0]
        );
        $long = $this->explainOf($this->runCalculation($integration, [], $wideCover), 'SKU-A');

        $this->assertSame(3, (int) $short['inputs']['lead_time_days']);
        $this->assertSame(25, (int) $long['inputs']['lead_time_days']);
        $this->assertGreaterThan(
            (float) $short['math']['safety_stock'],
            (float) $long['math']['safety_stock'],
            'Более длинный срок поставки обязан увеличивать страховой запас'
        );
        $this->assertGreaterThan(
            (float) $short['math']['needed_before_caps'],
            (float) $long['math']['needed_before_caps'],
            'Страховой запас обязан доходить до потребности'
        );
    }

    public function test_effective_params_record_what_the_calculation_actually_applied(): void
    {
        $integration = $this->seedOzonFacts();
        SupplySettings::updateOrCreate(
            ['integration_id' => $integration->id],
            ['default_lead_time_days' => 9]
        );

        // Запрошено покрытие 60 дней при горизонте 14 — расчёт обязан зажать его.
        $plan = $this->runCalculation($integration, [], [
            'horizon_days' => 14,
            'max_cover_days' => 60,
        ]);

        $requested = $plan->requested_params_json;
        $effective = $plan->effective_params_json;

        $this->assertSame(60, (int) $requested['max_cover_days']);
        $this->assertSame(14, (int) $effective['max_cover_days'], 'Горизонт обязан зажимать покрытие');
        $this->assertTrue($effective['max_cover_days_clamped_by_horizon']);
        $this->assertSame(9, (int) $effective['lead_time_days'], 'Срок поставки приходит из SupplySettings');
        $this->assertSame('supply_settings', $effective['settings_source']);
        $this->assertNotEquals($requested, $effective);

        $snapshot = PlanningFactSnapshot::query()->where('auto_supply_plan_id', $plan->id)->firstOrFail();
        $this->assertSame($effective, $snapshot->params_json['effective']);
        $this->assertSame(64, strlen((string) $snapshot->summary_json['params_hash']));
    }

    public function test_budget_limit_is_never_exceeded(): void
    {
        $integration = $this->seedOzonFacts();
        $unlimited = $this->runCalculation($integration);
        $this->assertGreaterThan(0, $this->totalQty($unlimited));

        $budget = 5000.0;
        $limited = $this->runCalculation($integration, [], ['budget_limit' => $budget]);

        $spent = $limited->lines()
            ->where('is_excluded', false)
            ->get()
            ->sum(fn (AutoSupplyPlanLine $line): float => (float) $line->qty_rounded * (float) ($line->cost_price ?? 0));

        $this->assertLessThanOrEqual($budget, round($spent, 2));
        $this->assertLessThan($this->totalQty($unlimited), $this->totalQty($limited));
    }

    public function test_in_transit_from_own_supply_is_not_double_counted_with_ozon_analytics(): void
    {
        $integration = $this->seedOzonFacts();

        // Локальная поставка на тот же кластер, ещё не отгружена.
        $supply = Supply::create([
            'integration_id' => $integration->id,
            'supply_type' => Supply::TYPE_FBO,
            'supply_method' => Supply::METHOD_DIRECT,
            'cluster_id' => '101',
            'cluster_name' => 'Москва',
            'warehouse_id' => 'cluster:101',
            'warehouse_name' => 'Москва',
            'status' => Supply::STATUS_SLOT_BOOKED,
        ]);
        SupplyItem::create([
            'supply_id' => $supply->id,
            'sku' => 'SKU-A',
            'product_name' => 'Товар SKU-A',
            'planned_qty' => 40,
            'status' => SupplyItem::STATUS_PENDING,
        ]);

        $plan = $this->runCalculation($integration);
        $line = $plan->lines()->where('sku', 'SKU-A')->where('cluster_id', '101')->firstOrFail();

        // in_transit складов (6 + 4) + до-отгрузочная заявка (40) — каждое ровно один раз.
        $this->assertSame(50, (int) $line->in_transit);
        $this->assertLessThan(
            $this->qtyForSku($this->runCalculationWithoutSupplies($integration), 'SKU-A'),
            (int) $line->qty_rounded,
            'Товар в пути обязан уменьшать рекомендацию'
        );
    }

    public function test_plan_without_any_inventory_is_ready_and_empty_not_failed(): void
    {
        $integration = Integration::factory()->ozon()->create([
            'id' => random_int(100000, 999999),
            'work_space_id' => 94,
        ]);

        $plan = $this->runCalculation($integration);

        $this->assertSame(AutoSupplyPlan::STATUS_READY, $plan->status);
        $this->assertSame(0, (int) $plan->total_lines);
        $this->assertNull($plan->error_message);
    }

    private function seedOzonFacts(bool $volatileDemand = false): Integration
    {
        $integration = Integration::factory()->ozon()->create([
            'id' => random_int(100000, 999999),
            'work_space_id' => 94,
        ]);

        foreach ([
            ['МОСКВА_ХОРУГВИНО', 101, 'Москва'],
            ['МОСКВА_ПУШКИНО', 101, 'Москва'],
            ['КАЗАНЬ_ЗЕЛЕНОДОЛЬСК', 202, 'Казань'],
        ] as [$normalized, $clusterId, $clusterName]) {
            OzonWarehouseCluster::create([
                'warehouse_name' => $normalized,
                'warehouse_name_normalized' => $normalized,
                'cluster_id' => $clusterId,
                'cluster_name' => $clusterName,
                'region' => $clusterName,
            ]);
        }

        $catalog = [
            ['sku' => 'SKU-A', 'ozon_sku' => 700001, 'price' => 1500.0, 'cost' => 600.0, 'profit' => 400.0],
            ['sku' => 'SKU-B', 'ozon_sku' => 700002, 'price' => 900.0, 'cost' => 300.0, 'profit' => 150.0],
        ];

        foreach ($catalog as $item) {
            Product::factory()->ozon()->create([
                'integration_id' => $integration->id,
                'sku' => $item['sku'],
                'vendor_code' => $item['sku'],
                'name' => 'Товар ' . $item['sku'],
                'barcode' => '460000000000' . substr($item['sku'], -1),
                'price' => $item['price'],
                'marketplace_id' => (string) $item['ozon_sku'],
                'ozon_data' => ['sku' => $item['ozon_sku']],
            ]);

            UnitEconomics::create([
                'integration_id' => $integration->id,
                'marketplace' => 'ozon',
                'sku' => $item['sku'],
                'price' => $item['price'],
                'cost_price' => $item['cost'],
                'net_profit_per_unit' => $item['profit'],
                'sales_count' => 30,
            ]);
        }

        // SKU-A: два московских склада + Казань. SKU-B: только Москва.
        // Остатки заведомо ниже целевого покрытия — иначе строка не попадает в план.
        $rows = [
            ['SKU-A', 'МОСКВА_ХОРУГВИНО', 'WH-1', 10, 6, 90],
            ['SKU-A', 'МОСКВА_ПУШКИНО', 'WH-2', 5, 4, 60],
            ['SKU-A', 'КАЗАНЬ_ЗЕЛЕНОДОЛЬСК', 'WH-3', 5, 0, 45],
            ['SKU-B', 'МОСКВА_ХОРУГВИНО', 'WH-1', 10, 0, 30],
        ];

        foreach ($rows as [$sku, $warehouseName, $warehouseId, $quantity, $inTransit, $sales30]) {
            InventoryWarehouse::factory()->ozon()->create([
                'integration_id' => $integration->id,
                'sku' => $sku,
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $warehouseName,
                'fulfillment_type' => 'fbo',
                'quantity' => $quantity,
                'reserved' => 0,
                'in_transit' => $inTransit,
                'sales_7_days' => $volatileDemand
                    ? (int) round($sales30 / 2)
                    : (int) round($sales30 / 4),
                'sales_14_days' => $volatileDemand
                    ? (int) round($sales30 * 0.75)
                    : (int) round($sales30 / 2),
                'sales_30_days' => $sales30,
                'average_daily_sales' => round($sales30 / 30, 2),
                'real_avg_daily_sales' => round($sales30 / 30, 2),
                'effective_daily_sales' => round($sales30 / 30, 2),
                'days_in_stock_30' => 30,
                'days_of_stock' => 10,
                'turnover_days' => 20,
                'last_updated' => now(),
            ]);
        }

        return $integration;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $planOverrides
     */
    private function runCalculation(
        Integration $integration,
        array $params = [],
        array $planOverrides = []
    ): AutoSupplyPlan {
        $plan = AutoSupplyPlan::create(array_merge([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_PENDING,
            'business_status' => AutoSupplyPlan::BUSINESS_STATUS_DRAFT,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'horizon_days' => 30,
            'min_cover_days' => 7,
            'target_cover_days' => 21,
            'max_cover_days' => 30,
            'safety_stock_days' => 3,
            'params' => array_merge(['supply_method' => 'direct'], $params),
        ], $planOverrides));

        (new CalculateAutoSupplyPlanJob($plan->id))->handle(app(\App\Services\AutoSupplyPlanService::class));

        return $plan->fresh();
    }

    private function runCalculationWithoutSupplies(Integration $integration): AutoSupplyPlan
    {
        return $this->runCalculation($integration, ['include_in_transit' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function explainOf(AutoSupplyPlan $plan, string $sku): array
    {
        $line = $plan->lines()->where('sku', $sku)->where('cluster_id', 101)->firstOrFail();
        $explain = is_array($line->explain_json)
            ? $line->explain_json
            : (array) json_decode((string) $line->explain_json, true);

        return $explain;
    }

    private function totalQty(AutoSupplyPlan $plan): int
    {
        return (int) $plan->lines()->where('is_excluded', false)->sum('qty_rounded');
    }

    private function qtyForSku(AutoSupplyPlan $plan, string $sku): int
    {
        return (int) $plan->lines()->where('sku', $sku)->where('cluster_id', '101')->sum('qty_rounded');
    }

    /**
     * Сравнимый отпечаток результата: только то, что план обещает пользователю.
     *
     * @return list<array<string, mixed>>
     */
    private function planFingerprint(AutoSupplyPlan $plan): array
    {
        return $plan->lines()
            ->orderBy('sku')
            ->orderBy('cluster_id')
            ->get()
            ->map(fn (AutoSupplyPlanLine $line): array => [
                'sku' => $line->sku,
                'cluster_id' => (string) $line->cluster_id,
                'qty' => (int) $line->qty_rounded,
                'stock' => (int) $line->current_stock,
                'in_transit' => (int) $line->in_transit,
                'excluded' => (bool) $line->is_excluded,
            ])
            ->all();
    }
}
