<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Models\MarketplaceConstraintSnapshot;
use App\Services\AutoSupplyPlanning\MarketplaceConstraintSyncService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * Тесты авто-синка ограничений WB (этап 1, WB-1).
 * Гоняются на sqlite :memory: с Http::fake — без реального API и без прод-БД.
 */
class MarketplaceConstraintSyncServiceTest extends TestCase
{
    private const BOX_TARIFFS_URL = 'common-api.wildberries.ru/api/v1/tariffs/box*';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-11 10:00:00');

        Schema::dropIfExists('integrations');
        Schema::dropIfExists('marketplace_constraint_snapshots');

        Schema::create('integrations', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('work_space_id')->nullable();
            $table->string('marketplace')->nullable();
            $table->text('credentials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('marketplace_constraint_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id')->index();
            $table->string('marketplace', 50)->index();
            $table->json('cluster_constraints_json')->nullable();
            $table->json('warehouse_constraints_json')->nullable();
            $table->json('summary_json')->nullable();
            $table->json('sources_json')->nullable();
            $table->string('sync_status', 20)->default('ok');
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['integration_id', 'marketplace'], 'mp_constraint_snap_unique');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeWbIntegration(array $credentials = ['api_key' => 'test-key']): Integration
    {
        return Integration::create([
            'id' => 9001,
            'marketplace' => 'wildberries',
            'credentials' => $credentials,
            'is_active' => true,
        ]);
    }

    /** Тарифы коробов WB (common-api /api/v1/tariffs/box), проценты строками. */
    private function fakeBoxTariffs(array $warehouses): void
    {
        Http::fake([
            self::BOX_TARIFFS_URL => Http::response([
                'response' => ['data' => ['warehouseList' => $warehouses]],
            ], 200),
        ]);
    }

    public function test_wb_uses_box_tariffs_while_acceptance_is_disabled(): void
    {
        // «Тарифы на поставку» WB временно отключил с 15.08.2026: метод не зовём,
        // склады не блокируем, коэффициенты — из тарифов коробов, статус partial.
        $this->fakeBoxTariffs([
            ['warehouseName' => 'Коледино', 'boxDeliveryCoefExpr' => '160', 'boxStorageCoefExpr' => '115'],
            ['warehouseName' => 'Подольск', 'boxDeliveryCoefExpr' => '200', 'boxStorageCoefExpr' => '180'],
        ]);

        $snapshot = (new MarketplaceConstraintSyncService())->syncIntegration($this->makeWbIntegration());

        $this->assertSame('partial', $snapshot->sync_status);
        $this->assertTrue($snapshot->isUsable());
        $this->assertNull($snapshot->cluster_constraints_json);

        $records = collect($snapshot->warehouse_constraints_json);
        $this->assertCount(2, $records);

        $koledino = $records->firstWhere('warehouse_name', 'Коледино');
        $this->assertTrue($koledino['is_available']);
        $this->assertNull($koledino['acceptance_coefficient']);
        // assertEquals (не assertSame): float идёт через JSON-сериализацию в БД.
        $this->assertEquals(1.6, $koledino['delivery_coefficient']);
        $this->assertEquals(1.15, $koledino['storage_coefficient']);
        $this->assertNull($koledino['max_qty']);
        $this->assertSame('marketplace_constraint', $koledino['source_type']);

        $summary = $snapshot->summary_json;
        $this->assertSame(2, $summary['warehouses_total']);
        $this->assertSame(0, $summary['warehouses_blocked']);
        $this->assertStringContainsString('временно отключил', $summary['reason']);
        $this->assertTrue($snapshot->sources_json['acceptance_coefficients']['disabled']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'acceptance/coefficients'));
    }

    public function test_status_error_when_box_tariffs_return_no_data(): void
    {
        Http::fake([
            self::BOX_TARIFFS_URL => Http::response('', 500),
        ]);

        $snapshot = (new MarketplaceConstraintSyncService())->syncIntegration($this->makeWbIntegration());

        $this->assertSame('error', $snapshot->sync_status);
        $this->assertSame(0, $snapshot->summary_json['warehouses_total']);
        $this->assertArrayHasKey('reason', $snapshot->summary_json);
    }

    public function test_missing_credentials_returns_error_without_calling_api(): void
    {
        Http::fake(); // на случай Sellico-фолбэка — ничего реального не уйдёт

        $snapshot = (new MarketplaceConstraintSyncService())->syncIntegration(
            $this->makeWbIntegration(['api_key' => ''])
        );

        $this->assertSame('error', $snapshot->sync_status);
        $this->assertStringContainsStringIgnoringCase('api_key', (string) $snapshot->sync_error);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'acceptance/coefficients'));
    }

    public function test_ozon_maps_cluster_availability_from_workload(): void
    {
        Http::fake([
            'api-seller.ozon.ru/v1/cluster/list' => Http::response([
                'result' => ['clusters' => [
                    ['id' => 100, 'name' => 'Москва', 'logistic_clusters' => [
                        ['warehouses' => [
                            ['warehouse_id' => 'W1', 'type' => 'FULL_FILLMENT', 'name' => 'Хоругвино'],
                            ['warehouse_id' => 'W2', 'type' => 'FULL_FILLMENT', 'name' => 'Пушкино'],
                        ]],
                    ]],
                    ['id' => 200, 'name' => 'СПб', 'logistic_clusters' => [
                        ['warehouses' => [
                            ['warehouse_id' => 'W3', 'type' => 'FULL_FILLMENT', 'name' => 'Шушары'],
                        ]],
                    ]],
                ]],
            ], 200),
            'api-seller.ozon.ru/v1/supplier/available_warehouses' => Http::response([
                'result' => [
                    ['warehouse' => ['id' => 'W1', 'name' => 'Хоругвино'], 'schedule' => ['date' => '2026-06-12', 'capacity' => [['value' => 500]]]],
                    // W2 отсутствует; W3 ёмкость 0 → кластер 200 заблокирован.
                    ['warehouse' => ['id' => 'W3', 'name' => 'Шушары'], 'schedule' => ['date' => '2026-06-13', 'capacity' => [['value' => 0]]]],
                ],
            ], 200),
        ]);

        $integration = Integration::create([
            'id' => 9002,
            'marketplace' => 'ozon',
            'credentials' => ['client_id' => '123', 'api_key' => 'test-key'],
            'is_active' => true,
        ]);

        $snapshot = (new MarketplaceConstraintSyncService())->syncIntegration($integration);

        $this->assertSame('ok', $snapshot->sync_status);
        $this->assertNull($snapshot->warehouse_constraints_json);

        $records = collect($snapshot->cluster_constraints_json);
        $this->assertCount(2, $records);

        $moscow = $records->firstWhere('cluster_id', '100');
        $this->assertTrue($moscow['is_available']);
        $this->assertNull($moscow['max_qty']);
        $this->assertNull($moscow['acceptance_coefficient']);

        $spb = $records->firstWhere('cluster_id', '200');
        $this->assertFalse($spb['is_available']);

        $summary = $snapshot->summary_json;
        $this->assertSame(1, $summary['clusters_available']);
        $this->assertSame(1, $summary['clusters_blocked']);
    }

    public function test_upsert_is_idempotent(): void
    {
        $this->fakeBoxTariffs([
            ['warehouseName' => 'Коледино', 'boxDeliveryCoefExpr' => '100', 'boxStorageCoefExpr' => '100'],
        ]);

        $service = new MarketplaceConstraintSyncService();
        $integration = $this->makeWbIntegration();

        $first = $service->syncIntegration($integration);
        $second = $service->syncIntegration($integration);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MarketplaceConstraintSnapshot::query()->count());
    }
}
