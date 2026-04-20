<?php

namespace Tests\Unit\Locality;

use App\Domains\Locality\Calculation\LocalityShareCalculator;
use PHPUnit\Framework\TestCase;

class LocalityShareCalculatorTest extends TestCase
{
    public function test_share_is_null_when_no_considered_items(): void
    {
        $calc = new LocalityShareCalculator();
        $result = $calc->compute([
            ['markup_reason_code' => 'cancelled_order', 'markup_applied' => false],
            ['markup_reason_code' => 'not_redeemed', 'markup_applied' => false],
        ]);

        $this->assertSame(0, $result['local']);
        $this->assertSame(0, $result['non_local']);
        $this->assertSame(2, $result['excluded']);
        $this->assertNull($result['share_percent']);
    }

    public function test_share_uses_cluster_comparison_not_reason_code(): void
    {
        $calc = new LocalityShareCalculator();
        $items = [
            // 6 local (shipping == destination)
            ...array_fill(0, 6, [
                'markup_reason_code' => 'local_cluster',
                'markup_applied' => false,
                'shipping_cluster_name' => 'Москва',
                'destination_cluster_name' => 'Москва',
            ]),
            // 4 non-local (shipping != destination), даже если reason=fbo_lt_50_orders_7d
            ...array_fill(0, 4, [
                'markup_reason_code' => 'fbo_lt_50_orders_7d',
                'markup_applied' => false,
                'shipping_cluster_name' => 'Санкт-Петербург',
                'destination_cluster_name' => 'Москва',
            ]),
        ];

        $result = $calc->compute($items);

        $this->assertSame(6, $result['local']);
        $this->assertSame(4, $result['non_local']);
        $this->assertSame(60.0, (float) $result['share_percent']);
    }

    public function test_cancelled_and_not_redeemed_are_excluded_from_denominator(): void
    {
        $calc = new LocalityShareCalculator();
        $items = [
            ['shipping_cluster_name' => 'А', 'destination_cluster_name' => 'А', 'markup_reason_code' => 'local_cluster'],
            ['markup_reason_code' => 'cancelled_order'],
            ['markup_reason_code' => 'not_redeemed'],
            // fbo_lt_50 non-local — учитывается через сравнение кластеров
            ['shipping_cluster_name' => 'А', 'destination_cluster_name' => 'Б', 'markup_reason_code' => 'fbo_lt_50_orders_7d'],
            ['shipping_cluster_name' => 'А', 'destination_cluster_name' => 'Б', 'markup_reason_code' => 'non_local_markup_applied', 'markup_applied' => true],
        ];

        $result = $calc->compute($items);
        $this->assertSame(1, $result['local']);
        $this->assertSame(2, $result['non_local']);
        $this->assertSame(2, $result['excluded']);
        $this->assertEqualsWithDelta(33.33, (float) $result['share_percent'], 0.01);
    }

    public function test_unknown_clusters_fallback_to_reason_code(): void
    {
        $calc = new LocalityShareCalculator();
        $items = [
            ['markup_reason_code' => 'local_cluster'],
            ['markup_reason_code' => 'non_local_markup_applied', 'markup_applied' => true],
            ['markup_reason_code' => 'fbo_lt_50_orders_7d'],
        ];

        $result = $calc->compute($items);
        $this->assertSame(1, $result['local']);
        $this->assertSame(1, $result['non_local']);
    }
}
