<?php

namespace Tests\Unit\Locality;

use App\Domains\Locality\Calculation\OverpaymentCalculator;
use App\Domains\Ozon\Tariffs\OzonPricingMatrix;
use PHPUnit\Framework\TestCase;

class OverpaymentCalculatorTest extends TestCase
{
    private OverpaymentCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new OverpaymentCalculator(new OzonPricingMatrix());
    }

    public function test_empty_returns_zeros(): void
    {
        $result = $this->calc->compute([]);
        $this->assertSame(0.0, $result['potential']);
        $this->assertSame(0.0, $result['actual']);
        $this->assertSame(0, $result['non_local_orders']);
    }

    public function test_cancelled_orders_are_excluded(): void
    {
        $result = $this->calc->compute([
            ['markup_reason_code' => 'cancelled_order', 'sale_price' => 1000, 'shipping_cluster_name' => 'А', 'destination_cluster_name' => 'Б'],
            ['markup_reason_code' => 'not_redeemed', 'sale_price' => 500, 'shipping_cluster_name' => 'А', 'destination_cluster_name' => 'Б'],
        ]);
        $this->assertSame(0.0, $result['potential']);
        $this->assertSame(0, $result['non_local_orders']);
    }

    public function test_local_sales_produce_no_overpayment(): void
    {
        $result = $this->calc->compute([
            ['sale_price' => 1000, 'shipping_cluster_name' => 'Москва', 'destination_cluster_name' => 'Москва'],
            ['sale_price' => 2000, 'shipping_cluster_name' => 'Санкт-Петербург', 'destination_cluster_name' => 'Санкт-Петербург'],
        ]);
        $this->assertSame(0.0, $result['potential']);
        $this->assertSame(0, $result['non_local_orders']);
    }

    public function test_non_local_sales_use_markup_matrix(): void
    {
        $result = $this->calc->compute([
            // Москва доставка — по таблице Ozon 8%
            ['sale_price' => 1000, 'shipping_cluster_name' => 'Санкт-Петербург', 'destination_cluster_name' => 'Москва'],
            // Москва доставка — 8% от 2000 = 160
            ['sale_price' => 2000, 'shipping_cluster_name' => 'Санкт-Петербург', 'destination_cluster_name' => 'Москва'],
        ]);
        // 8% × (1000 + 2000) = 240 (точное значение зависит от matrix config;
        // тест валидирует, что non_local_orders=2 и потенциал > 0)
        $this->assertSame(2, $result['non_local_orders']);
        $this->assertGreaterThan(0.0, $result['potential']);
    }

    public function test_actual_overpayment_still_counted_when_markup_applied(): void
    {
        $result = $this->calc->compute([
            [
                'sale_price' => 1000,
                'shipping_cluster_name' => 'Санкт-Петербург',
                'destination_cluster_name' => 'Москва',
                'markup_applied' => true,
                'non_local_markup_amount' => 80,
            ],
        ]);
        $this->assertSame(80.0, $result['actual']);
        $this->assertGreaterThan(0.0, $result['potential']);
    }
}
