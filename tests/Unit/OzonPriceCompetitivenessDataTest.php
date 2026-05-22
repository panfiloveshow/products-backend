<?php

namespace Tests\Unit;

use App\Console\Commands\SyncUnitEconomicsCommand;
use ReflectionClass;
use Tests\TestCase;

class OzonPriceCompetitivenessDataTest extends TestCase
{
    public function test_disabled_pricing_strategy_gets_explicit_label(): void
    {
        $result = $this->buildOzonPriceCompetitivenessData(1476, [
            'product_id' => 1033191105,
            'is_enabled' => false,
            'competitor_price' => 0,
            'strategy_product_price' => 0,
            'status' => 'disabled',
        ]);

        $this->assertNull($result['current_price_index']);
        $this->assertNull($result['current_price_is_favorable']);
        $this->assertSame('Не в стратегии', $result['current_price_index_label']);
    }

    public function test_available_competitor_price_gets_index(): void
    {
        $result = $this->buildOzonPriceCompetitivenessData(900, [
            'product_id' => 1033191105,
            'is_enabled' => true,
            'competitor_price' => 1000,
            'status' => 'available',
        ]);

        $this->assertSame(0.9, $result['current_price_index']);
        $this->assertTrue($result['current_price_is_favorable']);
        $this->assertSame('Выгодно', $result['current_price_index_label']);
    }

    private function buildOzonPriceCompetitivenessData(float $currentPrice, array $pricingStrategyData): array
    {
        $command = new SyncUnitEconomicsCommand;
        $method = (new ReflectionClass($command))->getMethod('buildOzonPriceCompetitivenessData');
        $method->setAccessible(true);

        return $method->invoke($command, $currentPrice, $pricingStrategyData);
    }
}
