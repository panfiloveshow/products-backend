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
     * Тест использования коэффициентов из API Ozon (localization_index)
     * Проверяет, что коэффициенты из API имеют приоритет над расчётом по таблице
     */
    public function test_calculate_ozon_with_api_coefficients(): void
    {
        $data = [
            'price' => 1000,
            'cost_price' => 300,
            'sales_count' => 1,
            'volume_liters' => 1,
            'fulfillment_type' => 'FBO',
            'commission_percent' => 15,
            'redemption_rate' => 80,
            'avg_delivery_time_hours' => 38,
            // Коэффициенты из API Ozon (должны использоваться вместо расчёта по таблице)
            'localization_index' => 1.55,           // Коэффициент из API
            'localization_additional_percent' => 2.75, // Доп.% из API
        ];

        $result = $this->service->calculate('ozon', $data);

        // Проверяем, что используются коэффициенты из API, а не из таблицы
        $this->assertEquals(1.55, $result['logistics_coefficient']);
        $this->assertEquals(2.75, $result['additional_commission_percent']);
        
        // Доп. комиссия = 1000 * 2.75% = 27.5
        $this->assertEquals(27.5, $result['additional_commission_amount']);
    }

    /**
     * Тест fallback на таблицу коэффициентов когда API данные отсутствуют
     */
    public function test_calculate_ozon_fallback_to_table_coefficients(): void
    {
        $data = [
            'price' => 1000,
            'cost_price' => 300,
            'sales_count' => 1,
            'volume_liters' => 1,
            'fulfillment_type' => 'FBO',
            'commission_percent' => 15,
            'redemption_rate' => 80,
            'avg_delivery_time_hours' => 38,
            // НЕ передаём localization_index — должен использоваться расчёт по таблице
        ];

        $result = $this->service->calculate('ozon', $data);

        // Для 38 часов по таблице: коэффициент 1.44, доп.% 2.2
        $this->assertEquals(1.44, $result['logistics_coefficient']);
        $this->assertEquals(2.2, $result['additional_commission_percent']);
    }
}
