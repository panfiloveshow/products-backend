<?php

namespace Tests\Unit;

use App\Services\UnitEconomicsService;
use PHPUnit\Framework\TestCase;

class UnitEconomicsServiceTest extends TestCase
{
    private UnitEconomicsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UnitEconomicsService();
    }

    /**
     * Тест расчёта юнит-экономики для Ozon FBO
     */
    public function test_calculate_ozon_fbo_basic(): void
    {
        $data = [
            'price' => 1000,
            'cost_price' => 300,
            'sales_count' => 1,
            'volume_liters' => 1,
            'fulfillment_type' => 'FBO',
            'commission_percent' => 15,
            'redemption_rate' => 80,
        ];

        $result = $this->service->calculate('ozon', $data);

        $this->assertArrayHasKey('revenue', $result);
        $this->assertArrayHasKey('total_costs', $result);
        $this->assertArrayHasKey('net_profit', $result);
        $this->assertArrayHasKey('margin_percent', $result);
        $this->assertArrayHasKey('to_settlement_account', $result);
        $this->assertArrayHasKey('route_key', $result);
        $this->assertArrayHasKey('price_segment', $result);
        
        $this->assertEquals(1000, $result['revenue']);
        $this->assertGreaterThan(0, $result['total_costs']);
    }

    /**
     * Тест расчёта юнит-экономики для Wildberries FBO
     */
    public function test_calculate_wildberries_fbo_basic(): void
    {
        $data = [
            'price' => 1500,
            'cost_price' => 500,
            'sales_count' => 1,
            'volume_liters' => 0.5,
            'fulfillment_type' => 'FBO',
            'commission_percent' => 15,
            'redemption_rate' => 85,
        ];

        $result = $this->service->calculate('wildberries', $data);

        $this->assertArrayHasKey('revenue', $result);
        $this->assertArrayHasKey('commission_percent', $result);
        $this->assertArrayHasKey('effective_logistics', $result);
        
        $this->assertEquals(1500, $result['revenue']);
        $this->assertEquals(15, $result['commission_percent']);
    }

    /**
     * Тест расчёта с нулевой себестоимостью
     */
    public function test_calculate_with_zero_cost_price(): void
    {
        $data = [
            'price' => 1000,
            'cost_price' => 0,
            'sales_count' => 1,
            'fulfillment_type' => 'FBO',
        ];

        $result = $this->service->calculate('ozon', $data);

        $this->assertEquals(0, $result['markup_percent']);
        $this->assertArrayHasKey('net_profit', $result);
    }

    /**
     * Тест расчёта с несколькими продажами
     */
    public function test_calculate_with_multiple_sales(): void
    {
        $data = [
            'price' => 500,
            'cost_price' => 150,
            'sales_count' => 10,
            'fulfillment_type' => 'FBO',
            'commission_percent' => 12,
        ];

        $result = $this->service->calculate('ozon', $data);

        $this->assertEquals(5000, $result['revenue']);
    }

    /**
     * Тест расчёта для FBS схемы
     */
    public function test_calculate_ozon_fbs(): void
    {
        $data = [
            'price' => 2000,
            'cost_price' => 600,
            'sales_count' => 1,
            'volume_liters' => 2,
            'fulfillment_type' => 'FBS',
            'commission_percent' => 18,
        ];

        $result = $this->service->calculate('ozon', $data);

        $this->assertArrayHasKey('fulfillment_type', $result);
        $this->assertEquals('FBS', $result['fulfillment_type']);
    }

    /**
     * Тест расчёта налогов
     */
    public function test_calculate_with_taxes(): void
    {
        $data = [
            'price' => 1000,
            'cost_price' => 300,
            'sales_count' => 1,
            'fulfillment_type' => 'FBO',
            'tax_percent' => 6,
            'vat_percent' => 0,
        ];

        $result = $this->service->calculate('ozon', $data);

        $this->assertEquals(6, $result['tax_percent']);
        $this->assertEquals(60, $result['tax_amount']); // 1000 * 6%
    }

    /**
     * Тест расчёта с ДРР (рекламными расходами)
     */
    public function test_calculate_with_drr(): void
    {
        $data = [
            'price' => 1000,
            'cost_price' => 300,
            'sales_count' => 1,
            'fulfillment_type' => 'FBO',
            'drr_percent' => 10,
        ];

        $result = $this->service->calculate('ozon', $data);

        $this->assertEquals(10, $result['drr_percent']);
        $this->assertEquals(100, $result['drr_amount']); // 1000 * 10%
    }

    /**
     * Тест неизвестного маркетплейса
     */
    public function test_calculate_unknown_marketplace_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = [
            'price' => 1000,
            'cost_price' => 300,
        ];

        $this->service->calculate('unknown', $data);
    }

    /**
     * Тест расчёта маржи
     */
    public function test_margin_calculation(): void
    {
        $data = [
            'price' => 1000,
            'cost_price' => 200,
            'sales_count' => 1,
            'fulfillment_type' => 'FBO',
            'commission_percent' => 0,
            'tax_percent' => 0,
            'vat_percent' => 0,
        ];

        $result = $this->service->calculate('ozon', $data);

        // Маржа = (прибыль / цена) * 100
        $this->assertGreaterThan(0, $result['margin_percent']);
    }

    /**
     * Тест расчёта для Yandex Market
     */
    public function test_calculate_yandex_basic(): void
    {
        $data = [
            'price' => 1200,
            'cost_price' => 400,
            'sales_count' => 1,
            'fulfillment_type' => 'FBO',
            'commission_percent' => 10,
        ];

        $result = $this->service->calculate('yandex', $data);

        $this->assertArrayHasKey('revenue', $result);
        $this->assertEquals(1200, $result['revenue']);
    }

    /**
     * Тест новой Ozon-модели: нелокальная продажа даёт route-based markup
     */
    public function test_calculate_ozon_with_route_markup(): void
    {
        $data = [
            'price' => 1000,
            'cost_price' => 300,
            'sales_count' => 1,
            'volume_liters' => 1,
            'fulfillment_type' => 'FBO',
            'redemption_rate' => 80,
            'route_key' => 'cluster_far',
        ];

        $result = $this->service->calculate('ozon', $data);

        $this->assertSame('cluster_far', $result['route_key']);
        $this->assertSame(false, $result['is_local_sale']);
        $this->assertEquals(8.0, $result['non_local_markup_percent']);
        $this->assertEquals(80.0, $result['non_local_markup_amount']);
    }

    /**
     * Тест новой Ozon-модели: price segment выбирается автоматически
     */
    public function test_calculate_ozon_resolves_price_segment(): void
    {
        $data = [
            'price' => 250,
            'cost_price' => 300,
            'sales_count' => 1,
            'volume_liters' => 1,
            'fulfillment_type' => 'FBO',
            'redemption_rate' => 80,
        ];

        $result = $this->service->calculate('ozon', $data);

        $this->assertSame('100.01-300', $result['price_segment']);
        $this->assertArrayHasKey('sales_fee_percent', $result);
    }

    public function test_calculate_ozon_uses_category_name_for_commission_matrix(): void
    {
        $data = [
            'price' => 1000,
            'cost_price' => 300,
            'sales_count' => 1,
            'volume_liters' => 1,
            'fulfillment_type' => 'FBO',
            'category_id' => 12345,
            'category_name' => 'Электроника',
        ];

        $result = $this->service->calculate('ozon', $data);

        $this->assertSame(9.0, $result['sales_fee_percent']);
        $this->assertSame(9.0, $result['commission_percent']);
    }

    public function test_calculate_ozon_uses_volume_weight_for_logistics_bucket(): void
    {
        $data = [
            'price' => 250,
            'cost_price' => 100,
            'sales_count' => 1,
            'volume_liters' => 1,
            'volume_weight' => 0.4,
            'fulfillment_type' => 'FBO',
            'shipping_cluster_name' => 'Воронеж',
            'destination_cluster_name' => 'Воронеж',
        ];

        $result = $this->service->calculate('ozon', $data);

        $this->assertSame(2.0, $result['chargeable_volume_liters']);
        $this->assertSame(29.48, $result['base_logistics_cost']);
    }
}
