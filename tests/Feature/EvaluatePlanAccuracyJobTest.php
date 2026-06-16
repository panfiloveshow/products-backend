<?php

namespace Tests\Feature;

use App\Jobs\EvaluatePlanAccuracyJob;
use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\PlanLineEvaluation;
use App\Services\AutoSupplyPlanning\PlanFactReconciler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvaluatePlanAccuracyJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function insertPosting(string $integrationId, string $cluster, string $createdAt, int $qty, string $postingNumber, string $offer = 'ART1'): void
    {
        $id = (string) Str::uuid();
        DB::table('postings')->insert([
            'id' => $id,
            'integration_id' => $integrationId,
            'marketplace' => 'ozon',
            'posting_number' => $postingNumber,
            'financial_data' => json_encode(['cluster_to' => $cluster]),
            'created_at' => $createdAt,
        ]);
        DB::table('posting_items')->insert([
            'id' => (string) Str::uuid(),
            'posting_id' => $id,
            'sku' => $offer,
            'name' => 'Test Product',
            'offer_id' => $offer,
            'quantity' => $qty,
        ]);
    }

    private function makeReadyPlan(int $integrationId, string $createdAt): AutoSupplyPlan
    {
        $plan = AutoSupplyPlan::create([
            'integration_id' => $integrationId,
            'mp_account_id' => $integrationId,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'horizon_days' => 14,
            'params' => [],
        ]);
        DB::table('auto_supply_plans')->where('id', $plan->id)->update(['created_at' => $createdAt]);

        return $plan->refresh();
    }

    public function test_job_writes_line_evaluations_and_plan_aggregate(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        $integration = Integration::factory()->ozon()->create(['id' => 9401, 'work_space_id' => 3]);
        $plan = $this->makeReadyPlan($integration->id, '2026-05-01 00:00:00'); // +14 = 2026-05-15 ≤ now

        $line = AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'ART1',
            'offer_id' => 'ART1',
            'cluster_id' => 12,
            'cluster_name' => 'Москва',
            'demand_daily' => 2.0, // прогноз = 2 * 14 = 28
            'qty_rounded' => 30,
        ]);

        // Факт в окне [2026-05-01; 2026-05-15], кластер Москва → 12 + 8 = 20.
        $this->insertPosting('9401', 'Москва', '2026-05-05 10:00:00', 12, 'P1');
        $this->insertPosting('9401', 'Москва', '2026-05-10 10:00:00', 8, 'P2');
        // Вне окна → не учитывается.
        $this->insertPosting('9401', 'Москва', '2026-05-30 10:00:00', 100, 'P3');

        (new EvaluatePlanAccuracyJob($plan->id))->handle(app(PlanFactReconciler::class));

        $eval = PlanLineEvaluation::where('auto_supply_plan_line_id', $line->id)->first();
        $this->assertNotNull($eval);
        $this->assertSame(PlanLineEvaluation::STATUS_OK, $eval->status);
        $this->assertEquals(28.0, $eval->forecast_demand_qty);
        $this->assertEquals(20.0, $eval->actual_sales_qty);
        $this->assertEquals(40.0, $eval->abs_pct_error);

        $plan->refresh();
        $this->assertEquals(40.0, $plan->accuracy_json['mape']);
        $this->assertSame(1, $plan->accuracy_json['lines_evaluated']);
    }

    public function test_accuracy_endpoint_returns_summary_and_lines(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9403, 'work_space_id' => 3]);
        $plan = $this->makeReadyPlan($integration->id, '2026-05-01 00:00:00');

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'ART1',
            'offer_id' => 'ART1',
            'cluster_id' => 12,
            'cluster_name' => 'Москва',
            'demand_daily' => 2.0,
            'qty_rounded' => 30,
        ]);
        $this->insertPosting('9403', 'Москва', '2026-05-05 10:00:00', 20, 'PX');

        (new EvaluatePlanAccuracyJob($plan->id))->handle(app(PlanFactReconciler::class));

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->getJson("/api/auto-supply-plans/{$plan->id}/accuracy");

        $response
            ->assertOk()
            ->assertJsonPath('data.evaluation_status', 'evaluated')
            ->assertJsonPath('data.summary.lines_evaluated', 1)
            ->assertJsonPath('data.lines.0.sku', 'ART1');
    }

    public function test_job_skips_premature_plan_without_writing_accuracy(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');

        $integration = Integration::factory()->ozon()->create(['id' => 9402, 'work_space_id' => 3]);
        // +14 = 2026-06-08 > now (2026-06-01) → ещё не созрел.
        $plan = $this->makeReadyPlan($integration->id, '2026-05-25 00:00:00');

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'ART1',
            'offer_id' => 'ART1',
            'demand_daily' => 2.0,
            'qty_rounded' => 30,
        ]);

        (new EvaluatePlanAccuracyJob($plan->id))->handle(app(PlanFactReconciler::class));

        $plan->refresh();
        $this->assertNull($plan->accuracy_json);
        $this->assertSame(0, PlanLineEvaluation::query()->count());
    }
}
