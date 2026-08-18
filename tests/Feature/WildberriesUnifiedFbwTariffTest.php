<?php

namespace Tests\Feature;

use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
use App\Models\WildberriesTariffSnapshot;
use App\Services\UnitEconomicsCacheService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * С 15.08.2026 WB унифицировал тарифы РФ: в /tariffs/box нет строк складов РФ,
 * только «Свой склад РФ» (КС 170%) / «Свой склад СГТ РФ» (КС 100%) + зарубежные.
 * FBW-ветка не должна считаться по замороженным снапшотам складов от 14.08.
 */
class WildberriesUnifiedFbwTariffTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const INTEGRATION_ID = 9901;

    private function seedSnapshots(): void
    {
        // Протухшая строка склада РФ (последний день старой схемы).
        WildberriesTariffSnapshot::create([
            'integration_id' => self::INTEGRATION_ID,
            'marketplace' => 'wildberries',
            'tariff_type' => 'box',
            'effective_date' => '2026-08-14',
            'warehouse_id' => 'name:koledino',
            'warehouse_name' => 'Коледино',
            'payload' => [
                'warehouse_name' => 'Коледино',
                'geo_name' => 'Центральный федеральный округ',
                'delivery_base' => 59.8,
                'delivery_liter' => 18.2,
                'delivery_coef_percent' => 130,
                'delivery_marketplace_base' => 0,
                'delivery_marketplace_liter' => 0,
                'delivery_marketplace_coef_percent' => 0,
            ],
            'fetched_at' => '2026-08-14 05:30:00',
        ]);

        // Протухший acceptance-оверлей (эндпоинт отключён WB 15.08).
        WildberriesTariffSnapshot::create([
            'integration_id' => self::INTEGRATION_ID,
            'marketplace' => 'wildberries',
            'tariff_type' => 'acceptance',
            'effective_date' => '2026-08-14',
            'warehouse_id' => '507',
            'warehouse_name' => 'Коледино',
            'payload' => ['warehouseName' => 'Коледино', 'deliveryCoef' => '160'],
            'fetched_at' => '2026-08-14 05:30:00',
        ]);

        // Единая строка нового формата: FBO-поля пустые, тариф в marketplace-полях.
        WildberriesTariffSnapshot::create([
            'integration_id' => self::INTEGRATION_ID,
            'marketplace' => 'wildberries',
            'tariff_type' => 'box',
            'effective_date' => '2026-08-18',
            'warehouse_id' => 'name:unified',
            'warehouse_name' => 'Свой склад РФ',
            'payload' => [
                'warehouse_name' => 'Свой склад РФ',
                'geo_name' => 'Россия',
                'delivery_base' => 0,
                'delivery_liter' => 0,
                'delivery_coef_percent' => 0,
                'delivery_marketplace_base' => 78.2,
                'delivery_marketplace_liter' => 23.8,
                'delivery_marketplace_coef_percent' => 170,
            ],
            'fetched_at' => '2026-08-18 05:30:00',
        ]);

        // Зарубежный склад остаётся в новом формате с FBO-полями — не перезаписывается.
        WildberriesTariffSnapshot::create([
            'integration_id' => self::INTEGRATION_ID,
            'marketplace' => 'wildberries',
            'tariff_type' => 'box',
            'effective_date' => '2026-08-18',
            'warehouse_id' => 'name:aktobe',
            'warehouse_name' => 'Актобе',
            'payload' => [
                'warehouse_name' => 'Актобе',
                'geo_name' => 'СНГ',
                'delivery_base' => 66.7,
                'delivery_liter' => 20.3,
                'delivery_coef_percent' => 145,
                'delivery_marketplace_base' => 0,
                'delivery_marketplace_liter' => 0,
                'delivery_marketplace_coef_percent' => 0,
            ],
            'fetched_at' => '2026-08-18 05:30:00',
        ]);
    }

    private function resolveBreakdown(string $scheme, array $marketplaceData): array
    {
        $service = new UnitEconomicsCacheService(
            $this->createMock(UnitEconomicsOrchestrator::class)
        );
        $method = new \ReflectionMethod(UnitEconomicsCacheService::class, 'resolveWildberriesTariffBreakdown');

        return $method->invoke($service, self::INTEGRATION_ID, $scheme, $marketplaceData, []);
    }

    public function test_fbw_stock_on_stale_rf_warehouse_uses_unified_tariff(): void
    {
        $this->seedSnapshots();

        $breakdown = $this->resolveBreakdown('FBO', [
            'stock_warehouses' => [['warehouse_name' => 'Коледино', 'quantity' => 10]],
        ]);

        $this->assertSame('2026-08-18', $breakdown['effective_date']);
        $this->assertEqualsWithDelta(78.2, (float) $breakdown['box']['delivery_base'], 0.001);
        $this->assertEqualsWithDelta(23.8, (float) $breakdown['box']['delivery_liter'], 0.001);
        $this->assertEqualsWithDelta(170.0, (float) $breakdown['box']['delivery_coef_percent'], 0.001);
        // Протухший acceptance-оверлей не должен перебивать единый КС.
        $this->assertArrayNotHasKey('boxDeliveryCoefExpr', $breakdown['box']);
    }

    public function test_fbw_without_warehouse_match_falls_back_to_unified_row(): void
    {
        $this->seedSnapshots();

        $breakdown = $this->resolveBreakdown('FBO', ['stock_warehouses' => []]);

        $this->assertSame('Свой склад РФ', $breakdown['warehouse_name']);
        $this->assertEqualsWithDelta(78.2, (float) $breakdown['box']['delivery_base'], 0.001);
    }

    public function test_fbw_stock_on_foreign_warehouse_keeps_its_own_tariff(): void
    {
        $this->seedSnapshots();

        $breakdown = $this->resolveBreakdown('FBO', [
            'stock_warehouses' => [['warehouse_name' => 'Актобе', 'quantity' => 5]],
        ]);

        $this->assertSame('Актобе', $breakdown['warehouse_name']);
        $this->assertEqualsWithDelta(66.7, (float) $breakdown['box']['delivery_base'], 0.001);
        $this->assertEqualsWithDelta(145.0, (float) $breakdown['box']['delivery_coef_percent'], 0.001);
    }

    public function test_fbs_still_uses_unified_marketplace_row(): void
    {
        $this->seedSnapshots();

        $breakdown = $this->resolveBreakdown('FBS', ['stock_warehouses' => []]);

        $this->assertSame('wildberries_tariff_snapshots_fbs_unified', $breakdown['source']);
        $this->assertEqualsWithDelta(78.2, (float) $breakdown['box']['delivery_marketplace_base'], 0.001);
    }
}
