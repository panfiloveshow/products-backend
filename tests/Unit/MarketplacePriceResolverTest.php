<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\UnitEconomics;
use App\Services\UnitEconomics\MarketplacePriceResolver;
use PHPUnit\Framework\TestCase;

class MarketplacePriceResolverTest extends TestCase
{
    public function test_newer_ozon_product_observation_wins_and_reports_its_source(): void
    {
        $resolved = (new MarketplacePriceResolver)->resolveWithMetadata(
            new Product(['marketplace' => 'ozon', 'price' => 2000]),
            [
                'actual_price' => 1700,
                'price_observed_at' => '2026-07-21T06:30:00+00:00',
            ],
            [],
            new UnitEconomics([
                'price' => 2000,
                'marketplace_data' => [
                    'actual_price' => 2000,
                    'price_observed_at' => '2026-07-21T02:30:00+00:00',
                ],
            ])
        );

        $this->assertSame(1700.0, $resolved['price']);
        $this->assertSame('product_actual_price', $resolved['source']);
        $this->assertSame('2026-07-21T06:30:00+00:00', $resolved['observed_at']);
    }

    public function test_current_marketing_price_is_applied_when_lower_than_base_price(): void
    {
        $resolved = (new MarketplacePriceResolver)->resolveWithMetadata(
            new Product(['marketplace' => 'ozon', 'price' => 2000]),
            [
                'actual_price' => 2000,
                'marketing_seller_price' => 1700,
                'price_observed_at' => '2026-07-21T06:30:00+00:00',
            ],
            [],
            null
        );

        $this->assertSame(1700.0, $resolved['price']);
        $this->assertSame('product_marketing_seller_price', $resolved['source']);
    }
}
