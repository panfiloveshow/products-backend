<?php

namespace Tests\Unit\Locality;

use App\Domains\Locality\Presentation\SkuLocalityExplanationBuilder;
use PHPUnit\Framework\TestCase;

class SkuLocalityExplanationBuilderTest extends TestCase
{
    private SkuLocalityExplanationBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SkuLocalityExplanationBuilder();
    }

    public function test_normal_case_derives_effective_from_overpayment_over_revenue(): void
    {
        // 5050/darkgray-подобный кейс: локальность 71.4%, 29% нелокальных,
        // средняя ставка ~10%, переплата 2982.6 ₽ при выручке ~104 286 ₽ → эфф ≈ 2.86%.
        $result = $this->builder->build(
            localSharePercent: 71.4,
            consideredOrders: 14,
            nonLocalOrders: 4, // ~28.57%
            grossMarkupPercent: 10.0,
            overpaymentRub: 2982.6,
            revenueRub: 104286.0,
        );

        $this->assertSame(2.86, $result['figures']['effective_markup_percent']);
        $this->assertSame(28.57, $result['figures']['non_local_share_percent']);
        $this->assertSame(10.0, $result['figures']['gross_markup_percent']);
        $this->assertCount(4, $result['breakdown']);
        $this->assertStringContainsString('эффективно', $result['short']);
        $this->assertStringContainsString('юнит-экономику', $result['full']);
    }

    public function test_fully_local_sku_has_no_markup_message(): void
    {
        $result = $this->builder->build(
            localSharePercent: 100.0,
            consideredOrders: 10,
            nonLocalOrders: 0,
            grossMarkupPercent: 0.0,
            overpaymentRub: 0.0,
            revenueRub: 50000.0,
        );

        $this->assertSame(0.0, $result['figures']['effective_markup_percent']);
        $this->assertStringContainsString('Все продажи локальные', $result['short']);
        $this->assertSame([], $result['breakdown']);
    }

    public function test_non_local_into_zero_markup_clusters_has_no_overpayment(): void
    {
        $result = $this->builder->build(
            localSharePercent: 40.0,
            consideredOrders: 10,
            nonLocalOrders: 6,
            grossMarkupPercent: 0.0,
            overpaymentRub: 0.0,
            revenueRub: 30000.0,
        );

        $this->assertStringContainsString('0% наценкой', $result['short']);
        $this->assertSame([], $result['breakdown']);
    }

    public function test_no_considered_orders(): void
    {
        $result = $this->builder->build(
            localSharePercent: null,
            consideredOrders: 0,
            nonLocalOrders: 0,
            grossMarkupPercent: 0.0,
            overpaymentRub: 0.0,
            revenueRub: 0.0,
        );

        $this->assertStringContainsString('Нет заказов', $result['short']);
        $this->assertSame(0.0, $result['figures']['non_local_share_percent']);
    }
}
