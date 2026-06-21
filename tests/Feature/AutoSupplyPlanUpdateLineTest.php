<?php

namespace Tests\Feature;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AutoSupplyPlanUpdateLineTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_editing_aggregated_ozon_cluster_line_keeps_plan_and_export_in_sync(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9301]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 3,
            'total_qty' => 50,
        ]);

        // Кластер 77: три складские строки одного SKU, 20 + 15 + 15 = 50.
        $base = [
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-CL',
            'offer_id' => 'OFF-CL',
            'product_name' => 'Clustered',
            'cluster_id' => 77,
            'cluster_name' => 'Москва',
            'destination_type' => 'cluster',
            'qty_recommended' => 1,
            'risk_level' => 'high',
            'priority' => 'high',
        ];

        foreach (['WH-A' => 20, 'WH-B' => 15, 'WH-C' => 15] as $wh => $qty) {
            AutoSupplyPlanLine::create(array_merge($base, [
                'warehouse_id' => $wh,
                'warehouse_name' => $wh,
                'qty_rounded' => $qty,
            ]));
        }

        // Показанная (агрегированная) строка использует MIN(id) и суммарный qty.
        $shownLineId = $this->getJson("/api/auto-supply-plans/{$plan->id}/lines?per_page=50")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.qty_rounded', 50)
            ->json('data.data.0.id');

        // Правим агрегат 50 → 60.
        $this->putJson("/api/auto-supply-plans/{$plan->id}/lines/{$shownLineId}", [
            'qty_rounded' => 60,
        ])
            ->assertOk()
            ->assertJsonPath('data.old_qty', 50)
            ->assertJsonPath('data.new_qty', 60)
            ->assertJsonPath('data.plan_total_qty', 60)
            ->assertJsonPath('data.line.qty_rounded', 60);

        // План: сумма по всем строкам = введённое значение, кластер не схлопнут.
        $this->assertSame(60, (int) $plan->fresh()->lines()->sum('qty_rounded'));
        $this->assertSame(3, $plan->fresh()->lines()->count());

        // lines/show: агрегат показывает то же число.
        $this->getJson("/api/auto-supply-plans/{$plan->id}/lines?per_page=50")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.qty_rounded', 60);

        // Экспорт Ozon (группировка по offer_id, SUM qty_rounded) = введённое значение.
        $content = $this->get("/api/auto-supply-plans/{$plan->id}/export/ozon")
            ->assertOk()
            ->streamedContent();

        $tmp = sys_get_temp_dir() . "/ozon_export_{$plan->id}.xlsx";
        file_put_contents($tmp, $content);
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);

        $this->assertSame('OFF-CL', $sheet->getCell('A2')->getValue());
        $this->assertSame(60, (int) $sheet->getCell('C2')->getValue());
    }

    public function test_editing_aggregated_line_respects_pack_multiple(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9302]);

        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 3,
            'total_qty' => 50,
        ]);

        $base = [
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'SKU-PACK',
            'offer_id' => 'OFF-PACK',
            'product_name' => 'Packed',
            'cluster_id' => 88,
            'cluster_name' => 'Москва',
            'destination_type' => 'cluster',
            'qty_recommended' => 1,
            'risk_level' => 'high',
            'priority' => 'high',
            'explain_json' => ['inputs' => ['pack_multiple' => 5]],
        ];

        foreach (['WH-A' => 20, 'WH-B' => 15, 'WH-C' => 15] as $wh => $qty) {
            AutoSupplyPlanLine::create(array_merge($base, [
                'warehouse_id' => $wh,
                'warehouse_name' => $wh,
                'qty_rounded' => $qty,
            ]));
        }

        $shownLineId = $this->getJson("/api/auto-supply-plans/{$plan->id}/lines?per_page=50")
            ->json('data.data.0.id');

        $this->putJson("/api/auto-supply-plans/{$plan->id}/lines/{$shownLineId}", [
            'qty_rounded' => 60,
        ])->assertOk()->assertJsonPath('data.plan_total_qty', 60);

        // Сумма точно сохранена, и каждая строка кратна pack=5.
        $qtys = $plan->fresh()->lines()->pluck('qty_rounded')->map(fn ($q) => (int) $q);
        $this->assertSame(60, $qtys->sum());
        foreach ($qtys as $q) {
            $this->assertSame(0, $q % 5, "qty {$q} не кратно pack=5");
        }
    }
}
