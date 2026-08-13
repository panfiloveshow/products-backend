<?php

namespace Tests\Unit;

use App\Domains\Ozon\UnitEconomics\RedemptionSource;
use App\Domains\Ozon\UnitEconomics\RedemptionSourceFamily;
use PHPUnit\Framework\TestCase;

class RedemptionSourceWbFunnelTest extends TestCase
{
    public function test_wb_sales_funnel_is_fresh_api_source(): void
    {
        $source = RedemptionSource::fromStringSafe('wb_sales_funnel');

        $this->assertSame(RedemptionSource::WbSalesFunnel, $source);
        $this->assertTrue($source->isFresh(), 'воронка должна перебивать застрявший existingUE default');
        $this->assertSame(RedemptionSourceFamily::Api, $source->family());
        $this->assertSame(30, $source->periodDays());
    }

    public function test_unknown_source_still_defaults(): void
    {
        $this->assertSame(RedemptionSource::Default, RedemptionSource::fromStringSafe('garbage'));
        $this->assertFalse(RedemptionSource::Default->isFresh());
    }
}
