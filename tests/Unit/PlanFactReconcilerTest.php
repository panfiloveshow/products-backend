<?php

namespace Tests\Unit;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\PlanLineEvaluation;
use App\Services\AutoSupplyPlanning\PlanFactReconciler;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Тесты сверки прогноза с фактом (этап 2, метрика A) на sqlite — без прод-БД.
 */
class PlanFactReconcilerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-01 10:00:00');

        Schema::dropIfExists('postings');
        Schema::dropIfExists('posting_items');
        Schema::dropIfExists('inventory_history');

        Schema::create('postings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('integration_id');
            $table->string('marketplace', 50);
            $table->string('status', 50)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('financial_data')->nullable();
            $table->string('warehouse_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('posting_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('posting_id');
            $table->string('sku');
            $table->string('offer_id')->nullable();
            $table->string('barcode')->nullable();
            $table->unsignedInteger('quantity')->default(1);
        });

        Schema::create('inventory_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('integration_id')->nullable();
            $table->string('sku', 100);
            $table->string('warehouse_id', 100);
            $table->date('date');
            $table->integer('quantity')->nullable();
            $table->integer('sales')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('supplies');
        Schema::dropIfExists('supply_items');

        Schema::create('supplies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('status', 50);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('supply_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supply_id');
            $table->string('sku', 100);
            $table->integer('planned_qty')->default(0);
            $table->integer('accepted_qty')->nullable();
            $table->integer('rejected_qty')->nullable();
        });
    }

    private function insertSupply(int $integrationId, string $status, string $createdAt, string $sku, int $accepted, int $rejected): void
    {
        $supplyId = DB::table('supplies')->insertGetId([
            'integration_id' => $integrationId,
            'status' => $status,
            'created_at' => $createdAt,
        ]);
        DB::table('supply_items')->insert([
            'supply_id' => $supplyId,
            'sku' => $sku,
            'planned_qty' => 30,
            'accepted_qty' => $accepted,
            'rejected_qty' => $rejected,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function insertPosting(string $integrationId, string $cluster, string $createdAt, int $qty, ?string $cancelledAt = null, string $offer = 'ART1'): void
    {
        $id = (string) Str::uuid();
        DB::table('postings')->insert([
            'id' => $id,
            'integration_id' => $integrationId,
            'marketplace' => 'ozon',
            'cancelled_at' => $cancelledAt,
            'financial_data' => json_encode(['cluster_to' => $cluster]),
            'created_at' => $createdAt,
        ]);
        DB::table('posting_items')->insert([
            'posting_id' => $id,
            'sku' => $offer,
            'offer_id' => $offer,
            'quantity' => $qty,
        ]);
    }

    private function makePlanAndLine(): array
    {
        $plan = new AutoSupplyPlan([
            'integration_id' => 700,
            'marketplace' => 'ozon',
            'horizon_days' => 14,
        ]);
        $plan->id = (string) Str::uuid();
        $plan->created_at = Carbon::parse('2026-05-01 00:00:00');

        $line = new AutoSupplyPlanLine([
            'sku' => 'ART1',
            'offer_id' => 'ART1',
            'cluster_id' => 12,
            'cluster_name' => 'Москва',
            'demand_daily' => 2.0,   // forecast = 2 * 14 = 28
            'qty_rounded' => 30,
        ]);
        $line->id = 555;

        return [$plan, $line];
    }

    public function test_evaluates_forecast_vs_actual_with_window_cluster_and_cancel_filters(): void
    {
        [$plan, $line] = $this->makePlanAndLine();

        // В окне [2026-05-01; 2026-05-15], кластер Москва, не отменены → учитываются (12 + 8 = 20).
        $this->insertPosting('700', 'Москва', '2026-05-03 10:00:00', 12);
        $this->insertPosting('700', 'Москва', '2026-05-10 10:00:00', 8);
        // Отменён → исключается.
        $this->insertPosting('700', 'Москва', '2026-05-05 10:00:00', 5, '2026-05-06 10:00:00');
        // Другой кластер → исключается.
        $this->insertPosting('700', 'Питер', '2026-05-07 10:00:00', 7);
        // Вне окна → исключается.
        $this->insertPosting('700', 'Москва', '2026-05-30 10:00:00', 100);

        $result = (new PlanFactReconciler())->evaluateLine($line, $plan);

        $this->assertSame(PlanLineEvaluation::STATUS_OK, $result['status']);
        $this->assertEquals(28.0, $result['forecast_demand_qty']);
        $this->assertEquals(20.0, $result['actual_sales_qty']);
        $this->assertEquals(40.0, $result['abs_pct_error']);  // |28-20|/20*100
        $this->assertEquals(40.0, $result['bias_pct']);        // (28-20)/20*100 (перепрогноз)
        $this->assertSame(30, $result['planned_qty']);
        $this->assertSame('postings_fbo', $result['demand_fact_source']);
    }

    public function test_forecast_measured_against_pre_calibration_demand(): void
    {
        // fix E: цикл не должен мерить собственную поправку. demand_daily=2.0 — это УЖЕ
        // откалиброванное значение; модель прогнозировала 3.0/день до калибровки.
        [$plan, $line] = $this->makePlanAndLine();
        $line->explain_json = [
            'inputs' => [
                'calibration' => ['applied' => true, 'multiplier' => 0.667],
                'daily_demand_pre_calibration' => 3.0,
            ],
        ];

        // Факт = 42 = сырой прогноз 3.0 * 14: при правильном измерении bias=0.
        $this->insertPosting('700', 'Москва', '2026-05-03 10:00:00', 42);

        $result = (new PlanFactReconciler())->evaluateLine($line, $plan);

        // Меряем против сырого 3.0*14=42, а не калиброванного 2.0*14=28.
        $this->assertEquals(42.0, $result['forecast_demand_qty']);
        $this->assertEquals(0.0, $result['bias_pct']);
    }

    public function test_clustered_line_without_name_is_insufficient_not_nationwide(): void
    {
        // fix F: кластерная строка (cluster_id=12) с потерянным именем не должна
        // суммировать продажи всех кластеров — иначе ложный under-forecast.
        [$plan, $line] = $this->makePlanAndLine();
        $line->cluster_name = null;

        $this->insertPosting('700', 'Москва', '2026-05-03 10:00:00', 50);
        $this->insertPosting('700', 'Питер', '2026-05-04 10:00:00', 70);

        $result = (new PlanFactReconciler())->evaluateLine($line, $plan);

        $this->assertSame(PlanLineEvaluation::STATUS_INSUFFICIENT, $result['status']);
        $this->assertEquals(0.0, $result['actual_sales_qty']);
        $this->assertNull($result['bias_pct']);
    }

    public function test_insufficient_data_when_no_sales_does_not_fake_accuracy(): void
    {
        [$plan, $line] = $this->makePlanAndLine();
        // Никаких продаж в окне.

        $result = (new PlanFactReconciler())->evaluateLine($line, $plan);

        $this->assertSame(PlanLineEvaluation::STATUS_INSUFFICIENT, $result['status']);
        $this->assertEquals(0.0, $result['actual_sales_qty']);
        $this->assertNull($result['abs_pct_error']);
        $this->assertNull($result['bias_pct']);
    }

    public function test_metric_b_supply_execution_matched_in_window(): void
    {
        [$plan, $line] = $this->makePlanAndLine();

        // Принятая поставка в окне → учитывается (25 принято, 3 отклонено).
        $this->insertSupply(700, 'accepted_full', '2026-05-06 10:00:00', 'ART1', 25, 3);
        // Непринятая (готовится) → исключается.
        $this->insertSupply(700, 'preparing', '2026-05-07 10:00:00', 'ART1', 10, 0);

        $result = (new PlanFactReconciler())->evaluateLine($line, $plan);

        $this->assertSame(25, $result['accepted_qty']);
        $this->assertSame(3, $result['rejected_qty']);
        $this->assertEquals(83.33, $result['acceptance_rate']); // 25/30*100
    }

    public function test_metric_b_null_when_no_supply(): void
    {
        [$plan, $line] = $this->makePlanAndLine();

        $result = (new PlanFactReconciler())->evaluateLine($line, $plan);

        $this->assertNull($result['accepted_qty']);
        $this->assertNull($result['acceptance_rate']);
    }

    public function test_plan_fact_tracks_oos_excess_cover_and_manual_override_outcome(): void
    {
        [$plan, $line] = $this->makePlanAndLine();
        $plan->max_cover_days = 10;
        $line->warehouse_id = 'WH-1';
        $line->original_qty_rounded = 20;
        $line->manual_updated_at = Carbon::parse('2026-05-01 12:00:00');
        $line->manual_override_json = ['qty_rounded' => 30, 'reason' => 'season'];
        $line->manual_comment = 'Сезонный запас';

        $this->insertPosting('700', 'Москва', '2026-05-03 10:00:00', 14);
        DB::table('inventory_history')->insert([
            [
                'id' => (string) Str::uuid(),
                'integration_id' => 700,
                'sku' => 'ART1',
                'warehouse_id' => 'WH-1',
                'date' => '2026-05-02',
                'quantity' => 0,
                'sales' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'integration_id' => 700,
                'sku' => 'ART1',
                'warehouse_id' => 'WH-1',
                'date' => '2026-05-14',
                'quantity' => 30,
                'sales' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $result = (new PlanFactReconciler())->evaluateLine($line, $plan);

        $this->assertSame(1, $result['details_json']['inventory_outcome']['oos_days']);
        $this->assertSame(30, $result['details_json']['inventory_outcome']['ending_stock']);
        $this->assertEquals(20.0, $result['details_json']['inventory_outcome']['excess_cover_days']);
        $this->assertSame('oos_occurred', $result['details_json']['manual_override_outcome']['outcome']);
        $this->assertContains('oos_during_horizon', $result['details_json']['discrepancy_causes']);
        $this->assertContains('excess_cover_after_horizon', $result['details_json']['discrepancy_causes']);

        $aggregate = (new PlanFactReconciler())->aggregate([$result]);
        $this->assertSame(1, $aggregate['oos_days_total']);
        $this->assertSame(1, $aggregate['lines_with_oos']);
        $this->assertSame(1, $aggregate['lines_with_excess_cover']);
        $this->assertSame(1, $aggregate['manual_override_outcomes']['oos_occurred']);
    }

    public function test_aggregate_ignores_insufficient_lines(): void
    {
        $reconciler = new PlanFactReconciler();
        $evaluations = [
            ['status' => PlanLineEvaluation::STATUS_OK, 'abs_pct_error' => 40.0, 'bias_pct' => 40.0],
            ['status' => PlanLineEvaluation::STATUS_OK, 'abs_pct_error' => 20.0, 'bias_pct' => -20.0],
            ['status' => PlanLineEvaluation::STATUS_INSUFFICIENT, 'abs_pct_error' => null, 'bias_pct' => null],
        ];

        $agg = $reconciler->aggregate($evaluations);

        $this->assertSame(2, $agg['lines_evaluated']);
        $this->assertSame(1, $agg['lines_insufficient_data']);
        $this->assertEquals(30.0, $agg['mape']);   // (40+20)/2
        $this->assertEquals(10.0, $agg['bias']);    // (40-20)/2
        $this->assertEquals(70.0, $agg['accuracy']); // 100-30
    }

    public function test_aggregate_with_all_insufficient_returns_null_metrics(): void
    {
        $agg = (new PlanFactReconciler())->aggregate([
            ['status' => PlanLineEvaluation::STATUS_INSUFFICIENT, 'abs_pct_error' => null, 'bias_pct' => null],
        ]);

        $this->assertNull($agg['mape']);
        $this->assertNull($agg['bias']);
        $this->assertSame(0, $agg['lines_evaluated']);
    }

    public function test_aggregate_uses_volume_weighted_wape_and_acceptance(): void
    {
        $agg = (new PlanFactReconciler())->aggregate([
            [
                'status' => PlanLineEvaluation::STATUS_OK,
                'abs_pct_error' => 50.0,
                'bias_pct' => 50.0,
                'forecast_demand_qty' => 150,
                'actual_sales_qty' => 100,
                'planned_qty' => 100,
                'accepted_qty' => 90,
                'acceptance_rate' => 90.0,
            ],
            [
                'status' => PlanLineEvaluation::STATUS_OK,
                'abs_pct_error' => 100.0,
                'bias_pct' => -100.0,
                'forecast_demand_qty' => 0,
                'actual_sales_qty' => 10,
                'planned_qty' => 10,
                'accepted_qty' => 5,
                'acceptance_rate' => 50.0,
            ],
        ]);

        $this->assertEqualsWithDelta(54.55, $agg['wape'], 0.01);
        $this->assertEqualsWithDelta(36.36, $agg['weighted_bias'], 0.01);
        $this->assertEqualsWithDelta(86.36, $agg['acceptance_rate'], 0.01);
        $this->assertEqualsWithDelta(45.45, $agg['accuracy'], 0.01);
    }
}
