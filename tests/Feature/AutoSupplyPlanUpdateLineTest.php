<?php

namespace Tests\Feature;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AutoSupplyPlanUpdateLineTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ozon_exports_preserve_external_text_and_do_not_delete_foreign_temp_files(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->ozon()->create(['id' => 9310]);
        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'ozon',
            'status' => AutoSupplyPlan::STATUS_READY,
            'business_status' => AutoSupplyPlan::BUSINESS_STATUS_APPROVED,
            'approval_fingerprint' => hash('sha256', 'spreadsheet-security-test'),
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 1,
            'total_qty' => 5,
        ]);

        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => '=1+1',
            'offer_id' => '=1+1',
            'product_name' => '=2+2',
            'warehouse_id' => 'warehouse-security-test',
            'warehouse_name' => '=3+3',
            'cluster_id' => 77,
            'cluster_name' => '=3+3',
            'destination_type' => 'cluster',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $standardContent = $this->get("/api/auto-supply-plans/{$plan->id}/export/ozon")
            ->assertOk()
            ->streamedContent();
        $standard = $this->loadSpreadsheet($standardContent, 'ozon-standard');
        $this->assertSame('=1+1', $standard->getActiveSheet()->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $standard->getActiveSheet()->getCell('A2')->getDataType());
        $this->assertSame('=2+2', $standard->getActiveSheet()->getCell('B2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $standard->getActiveSheet()->getCell('B2')->getDataType());

        $matrixContent = $this->get("/api/auto-supply-plans/{$plan->id}/export/ozon-matrix")
            ->assertOk()
            ->streamedContent();
        $matrix = $this->loadSpreadsheet($matrixContent, 'ozon-matrix');
        $this->assertSame('=1+1', $matrix->getActiveSheet()->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $matrix->getActiveSheet()->getCell('A2')->getDataType());
        $this->assertSame('=3+3', $matrix->getActiveSheet()->getCell('B1')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $matrix->getActiveSheet()->getCell('B1')->getDataType());

        $foreignTempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $this->assertNotFalse($foreignTempFile);
        file_put_contents($foreignTempFile, 'belongs to another request');

        try {
            $zipContent = $this->get("/api/auto-supply-plans/{$plan->id}/export/ozon-by-warehouse")
                ->assertOk()
                ->streamedContent();

            $this->assertFileExists($foreignTempFile);

            $zipPath = sys_get_temp_dir()."/ozon-by-warehouse-{$plan->id}.zip";
            file_put_contents($zipPath, $zipContent);
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($zipPath));
            $workbookContent = $zip->getFromIndex(0);
            $zip->close();
            @unlink($zipPath);
            $this->assertIsString($workbookContent);

            $warehouse = $this->loadSpreadsheet($workbookContent, 'ozon-warehouse');
            $this->assertSame('=1+1', $warehouse->getActiveSheet()->getCell('A2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $warehouse->getActiveSheet()->getCell('A2')->getDataType());
            $this->assertSame('=2+2', $warehouse->getActiveSheet()->getCell('B2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $warehouse->getActiveSheet()->getCell('B2')->getDataType());
        } finally {
            @unlink($foreignTempFile);
        }

        $standard->disconnectWorksheets();
        $matrix->disconnectWorksheets();
    }

    public function test_wildberries_export_preserves_formula_like_barcode_as_text(): void
    {
        Config::set('services.sellico.skip_permission_check', true);

        $integration = Integration::factory()->wildberries()->create(['id' => 9311]);
        Product::factory()->wildberries()->create([
            'integration_id' => $integration->id,
            'sku' => 'WB-SECURITY',
            'barcode' => '=1+1',
        ]);
        $plan = AutoSupplyPlan::create([
            'integration_id' => $integration->id,
            'mp_account_id' => $integration->id,
            'marketplace' => 'wildberries',
            'status' => AutoSupplyPlan::STATUS_READY,
            'mode' => AutoSupplyPlan::MODE_BALANCED,
            'params' => [],
            'total_lines' => 1,
            'total_qty' => 5,
        ]);
        AutoSupplyPlanLine::create([
            'auto_supply_plan_id' => $plan->id,
            'sku' => 'WB-SECURITY',
            'barcode' => '=1+1',
            'product_name' => 'WB security product',
            'warehouse_id' => 'wb-warehouse',
            'warehouse_name' => 'WB warehouse',
            'destination_type' => 'warehouse',
            'qty_recommended' => 5,
            'qty_rounded' => 5,
            'risk_level' => 'low',
            'priority' => 'low',
        ]);

        $content = $this->get("/api/auto-supply-plans/{$plan->id}/export/wb")
            ->assertOk()
            ->streamedContent();
        $spreadsheet = $this->loadSpreadsheet($content, 'wb-export');

        $this->assertSame('=1+1', $spreadsheet->getActiveSheet()->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $spreadsheet->getActiveSheet()->getCell('A2')->getDataType());

        $spreadsheet->disconnectWorksheets();
    }

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
            'reason' => 'Проверка агрегированного ручного изменения',
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

        // Ручное изменение сбрасывает утверждение: экспорт старой версии запрещён.
        $this->get("/api/auto-supply-plans/{$plan->id}/export/ozon")
            ->assertStatus(409);

        // Этот тест проверяет только согласованность агрегата и файла, поэтому
        // фиксируем завершённое повторное утверждение текущей версии как fixture.
        $plan->update([
            'business_status' => AutoSupplyPlan::BUSINESS_STATUS_APPROVED,
            'approved_at' => now(),
            'approval_fingerprint' => hash('sha256', "approved-test-version:{$plan->id}:60"),
        ]);

        // После повторного утверждения экспорт Ozon (SUM по offer_id)
        // содержит ровно введённое пользователем количество.
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

    private function loadSpreadsheet(string $content, string $name): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $path = sys_get_temp_dir()."/{$name}-".bin2hex(random_bytes(6)).'.xlsx';
        file_put_contents($path, $content);

        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
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
            'reason' => 'Проверка кратности после ручного изменения',
        ])->assertOk()->assertJsonPath('data.plan_total_qty', 60);

        // Сумма точно сохранена, и каждая строка кратна pack=5.
        $qtys = $plan->fresh()->lines()->pluck('qty_rounded')->map(fn ($q) => (int) $q);
        $this->assertSame(60, $qtys->sum());
        foreach ($qtys as $q) {
            $this->assertSame(0, $q % 5, "qty {$q} не кратно pack=5");
        }
    }
}
