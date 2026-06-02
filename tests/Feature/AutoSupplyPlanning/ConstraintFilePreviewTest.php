<?php

namespace Tests\Feature\AutoSupplyPlanning;

use App\Models\AutoSupplyConstraintFile;
use App\Models\Integration;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ConstraintFilePreviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ozon_constraint_file_preview_returns_cluster_constraints(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->ozon()->create([
            'id' => 9911,
            'work_space_id' => 3,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'ozon_constraints.csv',
            "ID кластера;Артикул;Лимит шт;Доступен;Комментарий\n154;SKU-1;12;да;Можно поставить\n155;SKU-2;0;нет;Закрыт\n;SKU-GLOBAL;7;да;Общий лимит SKU\n156;;100;да;Кластер целиком\n"
        );

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->post('/api/auto-supply-plans/constraints/preview', [
                'integration_id' => $integration->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.marketplace', 'Ozon')
            ->assertJsonPath('data.file.name', 'ozon_constraints.csv')
            ->assertJsonPath('data.cluster_constraints.0.cluster_id', 154)
            ->assertJsonPath('data.cluster_constraints.0.sku', 'SKU-1')
            ->assertJsonPath('data.cluster_constraints.0.max_qty', 12)
            ->assertJsonPath('data.cluster_constraints.1.is_available', false)
            ->assertJsonPath('data.cluster_constraints.2.sku', 'SKU-GLOBAL')
            ->assertJsonPath('data.cluster_constraints.2.scope', 'SKU во всех направлениях')
            ->assertJsonPath('data.cluster_constraints.3.cluster_id', 156)
            ->assertJsonPath('data.cluster_constraints.3.scope', 'кластер целиком')
            ->assertJsonPath('data.summary.global_sku_count', 1)
            ->assertJsonPath('data.summary.destination_only_count', 1)
            ->assertJsonPath('data.summary.source_type_counts.marketplace_constraint', 4)
            ->assertJsonPath('data.summary.limit_lines_count', 4)
            ->assertJsonPath('data.summary.blocked_lines_count', 1)
            ->assertJsonPath('data.summary.coefficient_lines_count', 0)
            ->assertJsonPath('data.summary.planning_roles.used_as_constraints', true)
            ->assertJsonPath('data.summary.planning_roles.used_as_marketplace_needs', false)
            ->assertJsonPath('data.summary.planning_roles.used_as_coefficients', false)
            ->assertJsonPath('data.summary.human_status', 'Ограничения готовы к использованию в расчёте');

        $this->assertStringContainsString('Файл будет использоваться как источник планирования', $response->json('data.summary.decision_ru'));

        $this->assertNotEmpty($response->json('data.file.sha256'));
        $this->assertNotEmpty($response->json('data.file.parsed_at'));
        $this->assertNotEmpty($response->json('data.constraint_file_id'));
        $this->assertDatabaseHas('auto_supply_constraint_files', [
            'integration_id' => $integration->id,
            'marketplace' => 'ozon',
            'file_name' => 'ozon_constraints.csv',
            'constraints_count' => 4,
        ]);
    }

    public function test_constraint_files_history_returns_saved_preview_files(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->ozon()->create([
            'id' => 9915,
            'work_space_id' => 3,
        ]);

        AutoSupplyConstraintFile::query()->create([
            'integration_id' => $integration->id,
            'marketplace' => 'ozon',
            'file_name' => 'ozon_limits.csv',
            'file_size_bytes' => 123,
            'file_hash' => 'hash-1',
            'parser_version' => 'marketplace-constraints-2',
            'rows_total' => 2,
            'constraints_count' => 2,
            'warnings_count' => 1,
            'cluster_constraints_json' => [
                ['cluster_id' => 154, 'sku' => 'SKU-1', 'max_qty' => 10],
            ],
            'summary_json' => ['parser_version' => 'marketplace-constraints-2', 'constraints_count' => 2],
            'warnings_json' => ['Строка 2: тестовое предупреждение'],
            'parsed_at' => now(),
        ]);

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->getJson('/api/auto-supply-plans/constraints?integration_id=' . $integration->id);

        $response->assertOk()
            ->assertJsonPath('data.0.file_name', 'ozon_limits.csv')
            ->assertJsonPath('data.0.constraints_count', 2)
            ->assertJsonPath('data.0.usable_for_planning', true)
            ->assertJsonPath('data.0.cluster_constraints.0.cluster_id', 154)
            ->assertJsonPath('data.0.summary.parser_version', 'marketplace-constraints-2');
    }

    public function test_wb_constraint_file_preview_returns_warehouse_constraints(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->wildberries()->create([
            'id' => 9912,
            'work_space_id' => 3,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'wb_constraints.csv',
            "Склад;Артикул;Лимит;Коэффициент\nЭлектросталь;WB-1;20;1,5\n"
        );

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->post('/api/auto-supply-plans/constraints/preview', [
                'integration_id' => $integration->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.marketplace', 'Wildberries')
            ->assertJsonPath('data.warehouse_constraints.0.warehouse_id', 'Электросталь')
            ->assertJsonPath('data.warehouse_constraints.0.sku', 'WB-1')
            ->assertJsonPath('data.warehouse_constraints.0.max_qty', 20)
            ->assertJsonPath('data.warehouse_constraints.0.coefficient', 1.5);
    }

    public function test_constraint_file_preview_keeps_marketplace_need_separate_from_limit(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->ozon()->create([
            'id' => 9916,
            'work_space_id' => 3,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'ozon_needs.csv',
            "Макрокластер;Артикул;Потребность;Коэффициент логистики\nМосква;OZ-NEED-1;42;1,2\n"
        );

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->post('/api/auto-supply-plans/constraints/preview', [
                'integration_id' => $integration->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cluster_constraints.0.cluster_id', 'Москва')
            ->assertJsonPath('data.cluster_constraints.0.sku', 'OZ-NEED-1')
            ->assertJsonPath('data.cluster_constraints.0.need_qty', 42)
            ->assertJsonPath('data.cluster_constraints.0.logistics_coefficient', 1.2)
            ->assertJsonPath('data.cluster_constraints.0.coefficient', 1.2)
            ->assertJsonPath('data.cluster_constraints.0.source_type', 'marketplace_need')
            ->assertJsonPath('data.summary.marketplace_needs_count', 1)
            ->assertJsonPath('data.summary.total_marketplace_need_qty', 42)
            ->assertJsonPath('data.summary.source_type_counts.marketplace_need', 1)
            ->assertJsonPath('data.summary.limit_lines_count', 0)
            ->assertJsonPath('data.summary.blocked_lines_count', 0)
            ->assertJsonPath('data.summary.coefficient_lines_count', 1)
            ->assertJsonPath('data.summary.planning_roles.used_as_constraints', false)
            ->assertJsonPath('data.summary.planning_roles.used_as_marketplace_needs', true)
            ->assertJsonPath('data.summary.planning_roles.used_as_coefficients', true);

        $this->assertStringContainsString('Потребности маркетплейса: 1 строк', $response->json('data.summary.decision_ru'));
        $this->assertStringContainsString('Коэффициенты: 1 строк', $response->json('data.summary.decision_ru'));

        $this->assertNull($response->json('data.cluster_constraints.0.max_qty'));
    }

    public function test_ozon_constraint_preview_accepts_realistic_macrocluster_file_headers(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->ozon()->create([
            'id' => 9913,
            'work_space_id' => 3,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'ozon_macrocluster_limits.csv',
            "Артикул продавца;Макрокластер;Лимит поставки;Можно поставить;Коэффициент логистики;Причина ограничения\nOZ-1;Москва, МО и Дальние регионы;1 200;да;150%;Дневной лимит товара\nOZ-2;Самара;0;нельзя;×2,5;Кластер закрыт\n"
        );

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->post('/api/auto-supply-plans/constraints/preview', [
                'integration_id' => $integration->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cluster_constraints.0.cluster_id', 'Москва, МО и Дальние регионы')
            ->assertJsonPath('data.cluster_constraints.0.cluster_name', 'Москва, МО и Дальние регионы')
            ->assertJsonPath('data.cluster_constraints.0.sku', 'OZ-1')
            ->assertJsonPath('data.cluster_constraints.0.max_qty', 1200)
            ->assertJsonPath('data.cluster_constraints.0.coefficient', 1.5)
            ->assertJsonPath('data.cluster_constraints.0.logistics_coefficient', 1.5)
            ->assertJsonPath('data.cluster_constraints.1.is_available', false)
            ->assertJsonPath('data.cluster_constraints.1.max_qty', 0)
            ->assertJsonPath('data.cluster_constraints.1.coefficient', 2.5)
            ->assertJsonPath('data.summary.parser_version', 'marketplace-constraints-4')
            ->assertJsonPath('data.summary.supported_fields.0', 'товар + кластер/склад');
    }

    public function test_constraint_preview_understands_barcode_and_ozon_sku_headers_as_product_keys(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->ozon()->create([
            'id' => 9922,
            'work_space_id' => 3,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'ozon_product_keys.csv',
            "Штрихкод;SKU Ozon;Макрокластер;Лимит поставки\n4601234567890;998877;Москва;12\n"
        );

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->post('/api/auto-supply-plans/constraints/preview', [
                'integration_id' => $integration->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cluster_constraints.0.sku', '998877')
            ->assertJsonPath('data.cluster_constraints.0.cluster_id', 'Москва')
            ->assertJsonPath('data.cluster_constraints.0.max_qty', 12)
            ->assertJsonPath('data.summary.sku_specific_count', 1);
    }

    public function test_ozon_constraint_preview_finds_header_after_service_preamble(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->ozon()->create([
            'id' => 9918,
            'work_space_id' => 3,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'ozon_export_with_preamble.csv',
            "Отчёт по ограничениям Ozon\nСформировано: 2026-05-28\nВаш артикул;Регион кластера;Разрешённое количество;Приёмка доступна;Объём потребности;Итоговый коэффициент\nOZ-PRE-1;Москва, МО и Дальние регионы;300;да;420;1,3\n"
        );

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->post('/api/auto-supply-plans/constraints/preview', [
                'integration_id' => $integration->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cluster_constraints.0.sku', 'OZ-PRE-1')
            ->assertJsonPath('data.cluster_constraints.0.cluster_id', 'Москва, МО и Дальние регионы')
            ->assertJsonPath('data.cluster_constraints.0.max_qty', 300)
            ->assertJsonPath('data.cluster_constraints.0.need_qty', 420)
            ->assertJsonPath('data.cluster_constraints.0.coefficient', 1.3)
            ->assertJsonPath('data.cluster_constraints.0.source_type', 'constraint_and_need')
            ->assertJsonPath('data.summary.header_row_number', 3)
            ->assertJsonPath('data.summary.skipped_preamble_rows', 2)
            ->assertJsonPath('data.summary.parser_version', 'marketplace-constraints-4');

        $this->assertStringContainsString('Пропущено служебных строк', $response->json('data.warnings.0'));
    }

    public function test_ozon_constraint_preview_understands_quantity_headers_with_units_and_status_phrases(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->ozon()->create([
            'id' => 9923,
            'work_space_id' => 3,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'ozon_units_and_status.csv',
            "Артикул продавца;Макрокластер доставки;Доступно для поставки, шт;Потребность кластера, шт;Статус поставки;Коэф. логистики\nOZ-UNIT-1;Москва;150;180;Доступно;1,4\nOZ-UNIT-2;Самара;0;55;Закрыта для поставки;2,1\n"
        );

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->post('/api/auto-supply-plans/constraints/preview', [
                'integration_id' => $integration->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cluster_constraints.0.cluster_id', 'Москва')
            ->assertJsonPath('data.cluster_constraints.0.sku', 'OZ-UNIT-1')
            ->assertJsonPath('data.cluster_constraints.0.max_qty', 150)
            ->assertJsonPath('data.cluster_constraints.0.need_qty', 180)
            ->assertJsonPath('data.cluster_constraints.0.is_available', true)
            ->assertJsonPath('data.cluster_constraints.0.logistics_coefficient', 1.4)
            ->assertJsonPath('data.cluster_constraints.1.cluster_id', 'Самара')
            ->assertJsonPath('data.cluster_constraints.1.max_qty', 0)
            ->assertJsonPath('data.cluster_constraints.1.need_qty', 55)
            ->assertJsonPath('data.cluster_constraints.1.is_available', false)
            ->assertJsonPath('data.cluster_constraints.1.reason', 'Направление закрыто в файле ограничений')
            ->assertJsonPath('data.summary.marketplace_needs_count', 2)
            ->assertJsonPath('data.summary.total_marketplace_need_qty', 235);
    }

    public function test_ozon_constraint_preview_understands_reverse_block_flags(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->ozon()->create([
            'id' => 9920,
            'work_space_id' => 3,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'ozon_reverse_block_flags.csv',
            "Макрокластер;Артикул продавца;Потребность кластера;Запрет поставки\nМосква;OZ-BLOCKED;40;да\nСамара;OZ-OPEN;20;нет\n"
        );

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->post('/api/auto-supply-plans/constraints/preview', [
                'integration_id' => $integration->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cluster_constraints.0.cluster_id', 'Москва')
            ->assertJsonPath('data.cluster_constraints.0.sku', 'OZ-BLOCKED')
            ->assertJsonPath('data.cluster_constraints.0.need_qty', 40)
            ->assertJsonPath('data.cluster_constraints.0.is_available', false)
            ->assertJsonPath('data.cluster_constraints.0.reason', 'Направление запрещено в файле ограничений')
            ->assertJsonPath('data.cluster_constraints.1.cluster_id', 'Самара')
            ->assertJsonPath('data.cluster_constraints.1.is_available', true)
            ->assertJsonPath('data.summary.marketplace_needs_count', 2)
            ->assertJsonPath('data.summary.total_marketplace_need_qty', 60);
    }

    public function test_wb_constraint_preview_accepts_acceptance_coefficient_file_headers(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->wildberries()->create([
            'id' => 9914,
            'work_space_id' => 3,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'wb_acceptance_limits.csv',
            "Склад поставки;Артикул продавца;Остаток лимита;allowUnload;Коэффициент приёмки;Причина ограничения\nКоледино;WB-REAL-1;500;true;×1,7;Платная приёмка\nЭлектросталь;WB-REAL-2;0;false;3;Нет слотов\n"
        );

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->post('/api/auto-supply-plans/constraints/preview', [
                'integration_id' => $integration->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.warehouse_constraints.0.warehouse_id', 'Коледино')
            ->assertJsonPath('data.warehouse_constraints.0.sku', 'WB-REAL-1')
            ->assertJsonPath('data.warehouse_constraints.0.max_qty', 500)
            ->assertJsonPath('data.warehouse_constraints.0.coefficient', 1.7)
            ->assertJsonPath('data.warehouse_constraints.0.acceptance_coefficient', 1.7)
            ->assertJsonPath('data.warehouse_constraints.1.is_available', false)
            ->assertJsonPath('data.warehouse_constraints.1.reason', 'Нет слотов');
    }

    public function test_wb_constraint_preview_preserves_separate_delivery_storage_and_acceptance_coefficients(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->wildberries()->create([
            'id' => 9917,
            'work_space_id' => 3,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'wb_detailed_coefficients.csv',
            "Склад;Артикул;Лимит;Коэффициент приёмки;Коэффициент доставки;Коэффициент хранения\nКоледино;WB-COEF-1;25;1,4;2,2;3,1\n"
        );

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->post('/api/auto-supply-plans/constraints/preview', [
                'integration_id' => $integration->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.warehouse_constraints.0.acceptance_coefficient', 1.4)
            ->assertJsonPath('data.warehouse_constraints.0.delivery_coefficient', 2.2)
            ->assertJsonPath('data.warehouse_constraints.0.storage_coefficient', 3.1)
            ->assertJsonPath('data.warehouse_constraints.0.coefficient', 1.4)
            ->assertJsonPath('data.summary.parser_version', 'marketplace-constraints-4')
            ->assertJsonPath('data.summary.supported_fields.4', 'отдельные коэффициенты приёмки/доставки/логистики/хранения');
    }

    public function test_wb_constraint_preview_finds_header_after_service_preamble(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->wildberries()->create([
            'id' => 9919,
            'work_space_id' => 3,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'wb_export_with_preamble.csv',
            "Коэффициенты складов WB\nДата выгрузки;28.05.2026\nСклад приёмки;Ваш артикул;Можно поставить шт;Разрешена поставка;Коэффициент приёмки коробов;Коэффициент платного хранения\nКоледино;WB-PRE-1;90;да;2,1;1,4\n"
        );

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->post('/api/auto-supply-plans/constraints/preview', [
                'integration_id' => $integration->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.warehouse_constraints.0.warehouse_id', 'Коледино')
            ->assertJsonPath('data.warehouse_constraints.0.sku', 'WB-PRE-1')
            ->assertJsonPath('data.warehouse_constraints.0.max_qty', 90)
            ->assertJsonPath('data.warehouse_constraints.0.acceptance_coefficient', 2.1)
            ->assertJsonPath('data.warehouse_constraints.0.storage_coefficient', 1.4)
            ->assertJsonPath('data.summary.header_row_number', 3)
            ->assertJsonPath('data.summary.skipped_preamble_rows', 2)
            ->assertJsonPath('data.summary.parser_version', 'marketplace-constraints-4');
    }

    public function test_wb_constraint_preview_understands_free_limit_and_acceptance_status_phrases(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->wildberries()->create([
            'id' => 9924,
            'work_space_id' => 3,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'wb_free_limit_status.csv',
            "Склад назначения;Артикул продавца;Свободный лимит, шт;Статус приёмки;Коэф. приёмки;Коэф. логистики\nКоледино;WB-FREE-1;240;Приёмка доступна;1,6;2,2\nЭлектросталь;WB-FREE-2;0;Приёмка временно закрыта;2,5;3,1\n"
        );

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->post('/api/auto-supply-plans/constraints/preview', [
                'integration_id' => $integration->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.warehouse_constraints.0.warehouse_id', 'Коледино')
            ->assertJsonPath('data.warehouse_constraints.0.sku', 'WB-FREE-1')
            ->assertJsonPath('data.warehouse_constraints.0.max_qty', 240)
            ->assertJsonPath('data.warehouse_constraints.0.is_available', true)
            ->assertJsonPath('data.warehouse_constraints.0.acceptance_coefficient', 1.6)
            ->assertJsonPath('data.warehouse_constraints.0.logistics_coefficient', 2.2)
            ->assertJsonPath('data.warehouse_constraints.1.warehouse_id', 'Электросталь')
            ->assertJsonPath('data.warehouse_constraints.1.max_qty', 0)
            ->assertJsonPath('data.warehouse_constraints.1.is_available', false)
            ->assertJsonPath('data.warehouse_constraints.1.acceptance_coefficient', 2.5)
            ->assertJsonPath('data.warehouse_constraints.1.logistics_coefficient', 3.1);
    }

    public function test_wb_constraint_preview_understands_reverse_block_flags(): void
    {
        Config::set('services.sellico.skip_permission_check', true);
        $this->fakeSellico();

        $integration = Integration::factory()->wildberries()->create([
            'id' => 9921,
            'work_space_id' => 3,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'wb_reverse_block_flags.csv',
            "Склад поставки;Артикул продавца;Остаток лимита;Приёмка закрыта;Коэффициент приёмки\nКоледино;WB-BLOCKED;100;да;2,0\nЭлектросталь;WB-OPEN;100;нет;1,0\n"
        );

        $response = $this
            ->withHeader('X-Workspace-Id', '3')
            ->post('/api/auto-supply-plans/constraints/preview', [
                'integration_id' => $integration->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.warehouse_constraints.0.warehouse_id', 'Коледино')
            ->assertJsonPath('data.warehouse_constraints.0.sku', 'WB-BLOCKED')
            ->assertJsonPath('data.warehouse_constraints.0.max_qty', 100)
            ->assertJsonPath('data.warehouse_constraints.0.is_available', false)
            ->assertJsonPath('data.warehouse_constraints.0.reason', 'Направление запрещено в файле ограничений')
            ->assertJsonPath('data.warehouse_constraints.1.warehouse_id', 'Электросталь')
            ->assertJsonPath('data.warehouse_constraints.1.is_available', true)
            ->assertJsonPath('data.warehouse_constraints.1.acceptance_coefficient', 1);
    }

    private function fakeSellico(): void
    {
        $this->app->instance(SellicoApiService::class, new class extends SellicoApiService {
            public function getWorkspaceLimitsExternal(int $workspaceId, ?string $type = null): array
            {
                return ['success' => true, 'status' => 200, 'limits' => []];
            }
        });
    }
}
