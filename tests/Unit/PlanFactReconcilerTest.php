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
}
