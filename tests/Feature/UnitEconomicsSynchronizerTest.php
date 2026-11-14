<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Services\Ozon\OzonOrderUnitEconomicsService;
use App\Services\Ozon\OzonSupplyFixationService;
use App\Services\Ozon\OzonSupplySyncService;
use App\Services\PostingService;
use App\Services\UnitEconomicsService;
use App\Services\UnitEconomicsSynchronizer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class UnitEconomicsSynchronizerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_complete_postings_buyout_coverage_does_not_require_an_optional_analytics_request(): void
    {
        $a = new Product(['sku' => 'A', 'ozon_data' => ['sku' => 123]]);
        $b = new Product(['sku' => 'B', 'ozon_data' => ['offer_id' => 'B-OFFER']]);
        $method = new \ReflectionMethod(UnitEconomicsSynchronizer::class, 'needsOzonAnalyticsBuyout');
        $service = app(UnitEconomicsSynchronizer::class);
        $covered = ['123' => ['has_full_data' => true], 'B-OFFER' => ['has_full_data' => true]];
        $this->assertFalse($method->invoke($service, collect([$a, $b]), $covered));
        unset($covered['B-OFFER']);
        $this->assertTrue($method->invoke($service, collect([$a, $b]), $covered));
    }

    public function test_real_result_counts_committed_schemes_and_retains_failed_sku_across_all_schemes(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 200)]);
        $integration = Integration::factory()->wildberries()->create(['id' => 62007, 'credentials' => []]);
        $schemes = ['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'];
        foreach (['BROKEN', 'HEALTHY'] as $sku) {
            Product::factory()->wildberries()->create(['integration_id' => $integration->id, 'sku' => $sku, 'price' => 1200]);
            foreach ($schemes as $scheme) {
                UnitEconomics::create(['integration_id' => $integration->id, 'sku' => $sku,
                    'marketplace' => 'wildberries', 'fulfillment_type' => $scheme, 'price' => 1000]);
            }
        }
        $calculator = Mockery::mock(UnitEconomicsService::class);
        $calculator->shouldReceive('calculate')->andReturnUsing(function (string $marketplace, array $input): array {
            if ($input['sku'] === 'BROKEN' && $input['fulfillment_type'] === 'FBS') {
                throw new \RuntimeException('One SKU cannot be calculated');
            }

            return ['revenue' => 1200, 'total_costs' => 100, 'gross_profit' => 1100,
                'net_profit' => 1100, 'margin_percent' => 91.67, 'roi_percent' => 1100];
        });

        $result = app(UnitEconomicsSynchronizer::class)->syncProducts(
            $calculator,
            Mockery::mock(OzonSupplyFixationService::class),
            Mockery::mock(OzonOrderUnitEconomicsService::class),
            Mockery::mock(OzonSupplySyncService::class),
            Mockery::mock(PostingService::class),
            $integration->id, 'wildberries', skipCacheDispatch: true,
        );

        $this->assertSame(2, $result['total']);
        $this->assertSame(5, $result['synced']);
        $this->assertSame(1, $result['succeeded']);
        $this->assertSame(1, $result['errors']);
        $this->assertSame('partial', $result['status']);
        $this->assertSame(['BROKEN'], $result['failed_skus']);
        $this->assertSame(['1000.00'], UnitEconomics::where('sku', 'BROKEN')->pluck('price')->unique()->values()->all());
        $this->assertSame(['1200.00'], UnitEconomics::where('sku', 'HEALTHY')->pluck('price')->unique()->values()->all());
    }
}
