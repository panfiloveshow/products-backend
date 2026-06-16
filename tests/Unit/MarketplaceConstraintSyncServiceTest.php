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
    private const ACCEPTANCE_URL = 'common-api.wildberries.ru/api/tariffs/v1/acceptance/coefficients*';

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

    /** Сырой формат записи коэффициентов приёмки WB (как отдаёт common-api). */
    private function acceptanceItem(string $whId, string $name, string $date, float $coef, bool $allow, float $storage, float $delivery): array
    {
        return [
            'date' => $date,
            'warehouseID' => $whId,
            'warehouseName' => $name,
            'coefficient' => $coef,
            'allowUnload' => $allow,
            'boxTypeID' => 2,
            'isSortingCenter' => false,
            'storageCoef' => $storage,
            'deliveryCoef' => $delivery,
        ];
    }

    public function test_maps_available_and_blocked_warehouses_with_coefficients(): void
    {
        Http::fake([
            self::ACCEPTANCE_URL => Http::response([
                // Коледино: бесплатное (0) и базовое (1) окно → доступен; берём лучшие коэф.
                $this->acceptanceItem('507', 'Коледино', '2026-06-12', 0, true, 1.0, 1.2),
                $this->acceptanceItem('507', 'Коледино', '2026-06-14', 1, true, 0.9, 1.1),
                // Подольск: только платный коэффициент 2 → недоступен (нет бесплатных окон).
                $this->acceptanceItem('117501', 'Подольск', '2026-06-13', 2, true, 1.5, 1.4),
            ], 200),
        ]);

        $snapshot = (new MarketplaceConstraintSyncService())->syncIntegration($this->makeWbIntegration());

        $this->assertSame('ok', $snapshot->sync_status);
        $this->assertNull($snapshot->cluster_constraints_json);

        $records = collect($snapshot->warehouse_constraints_json);
        $this->assertCount(2, $records);

        $koledino = $records->firstWhere('warehouse_id', '507');
        $this->assertNotNull($koledino);
        $this->assertTrue($koledino['is_available']);
        // assertEquals (не assertSame): целые float теряют .0 через JSON-сериализацию в БД.
        $this->assertEquals(1.0, $koledino['acceptance_coefficient']);
        $this->assertEquals(0.9, $koledino['storage_coefficient']);   // min по горизонту
        $this->assertEquals(1.1, $koledino['delivery_coefficient']);  // min по горизонту
        $this->assertNull($koledino['max_qty']);                    // WB API не отдаёт лимит штук
        $this->assertNull($koledino['need_qty']);
        $this->assertSame('marketplace_constraint', $koledino['source_type']);
        $this->assertSame('Коледино', $koledino['warehouse_name']);

        $podolsk = $records->firstWhere('warehouse_id', '117501');
        $this->assertNotNull($podolsk);
        $this->assertFalse($podolsk['is_available']);
        $this->assertNull($podolsk['acceptance_coefficient']);

        $summary = $snapshot->summary_json;
        $this->assertSame(2, $summary['warehouses_total']);
        $this->assertSame(1, $summary['warehouses_available']);
        $this->assertSame(1, $summary['warehouses_blocked']);
        $this->assertSame(14, $summary['horizon_days']);
    }

    public function test_filters_slots_outside_horizon(): void
    {
        Http::fake([
            self::ACCEPTANCE_URL => Http::response([
                $this->acceptanceItem('507', 'Коледино', '2026-06-12', 0, true, 1.0, 1.0),
                // За пределами горизонта (+14 дней = 2026-06-25): склад не должен попасть.
                $this->acceptanceItem('888', 'Будущее', '2026-07-30', 0, true, 1.0, 1.0),
            ], 200),
        ]);

        $snapshot = (new MarketplaceConstraintSyncService())->syncIntegration($this->makeWbIntegration());

        $records = collect($snapshot->warehouse_constraints_json);
        $this->assertCount(1, $records);
        $this->assertSame('507', $records->first()['warehouse_id']);
    }

    public function test_status_error_when_acceptance_api_returns_no_data(): void
    {
        Http::fake([
            self::ACCEPTANCE_URL => Http::response('', 500),
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
        Http::fake([
            self::ACCEPTANCE_URL => Http::response([
                $this->acceptanceItem('507', 'Коледино', '2026-06-12', 0, true, 1.0, 1.0),
            ], 200),
        ]);

        $service = new MarketplaceConstraintSyncService();
        $integration = $this->makeWbIntegration();

        $first = $service->syncIntegration($integration);
        $second = $service->syncIntegration($integration);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MarketplaceConstraintSnapshot::query()->count());
    }
}
