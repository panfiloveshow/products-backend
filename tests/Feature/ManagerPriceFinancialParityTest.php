<?php

namespace Tests\Feature;

use App\Domains\UnitEconomics\DTO\CalculationInput;
use App\Domains\UnitEconomics\UnitEconomicsOrchestrator;
use App\Models\Integration;
use App\Models\Product;
use App\Models\UnitEconomics;
use App\Models\UnitEconomicsCache;
use App\Models\UnitEconomicsSettings;
use App\Services\Ozon\OzonLocalityService;
use App\Services\UnitEconomicsCacheService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManagerPriceFinancialParityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function schemes(): array
    {
        $cases = [];
        foreach (['wildberries' => ['FBO', 'FBS', 'DBS', 'EDBS', 'DBW'], 'ozon' => ['FBO', 'FBS', 'RFBS', 'EXPRESS']] as $marketplace => $schemes) {
            foreach ($schemes as $scheme) $cases[$marketplace.' '.$scheme] = [$marketplace, $scheme];
        }

        return $cases;
    }

    #[DataProvider('schemes')]
    public function test_preview_and_main_cache_include_the_same_seller_expenses_without_writing(string $marketplace, string $scheme): void
    {
        [$product, $settings] = $this->fixture($marketplace, $scheme);
        $engine = app(UnitEconomicsCacheService::class);
        $base = $engine->calculateAndCache($product, $scheme);
        $baseProfit = (float) $base->net_profit;
        $baseSettlement = (float) $base->to_settlement_account;
        $settings->update(['drr_percent' => 5, 'tax_percent' => 6, 'vat_percent' => 3, 'our_share_percent' => 2]);
        $engine->onSettingsChanged(62400, $product->sku);
        $cached = UnitEconomicsCache::where('integration_id', 62400)->where('sku', $product->sku)->where('fulfillment_type', $scheme)->firstOrFail();
        // Five percent DRR + six tax + three VAT + two share = 160 RUB at 1000.
        $this->assertEqualsWithDelta(160, $baseProfit - (float) $cached->net_profit, 0.01);
        // The marketplace withholds DRR once. Seller tax/VAT/share are not
        // additionally withheld from the settlement-account receipt.
        $this->assertEqualsWithDelta(50, $baseSettlement - (float) $cached->to_settlement_account, 0.01);
        $cacheCount = UnitEconomicsCache::count();
        $mutations = [];
        DB::listen(function ($q) use (&$mutations) { if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q->sql)) $mutations[] = $q->sql; });

        $preview = $engine->previewPrice($product->fresh(), $scheme, 1200);

        $this->assertEqualsWithDelta((float) $cached->net_profit, $preview['before']['net_profit'], 0.01);
        $this->assertEqualsWithDelta((float) $cached->total_costs, $preview['before']['total_costs'], 0.01);
        $this->assertEqualsWithDelta((float) $cached->to_settlement_account, $preview['before']['to_settlement_account'], 0.01);
        foreach (['drr_amount' => 60, 'tax_amount' => 72, 'vat_amount' => 36, 'our_share_amount' => 24] as $field => $expected) {
            $this->assertEquals($expected, $preview['after'][$field], $field);
        }
        foreach (['before', 'after'] as $side) {
            $row = $preview[$side];
            $this->assertEqualsWithDelta($row['price'] - $row['total_costs'], $row['net_profit'], 0.01);
            $this->assertEqualsWithDelta($row['net_profit'], $row['margin_absolute'], 0.01);
            $this->assertEqualsWithDelta(100 * $row['net_profit'] / $row['price'], $row['margin_percent'], 0.01);
            $this->assertSame($row['net_profit'] > 0, $row['is_profitable']);
            $this->assertLessThanOrEqual($row['net_profit'], $row['profit_min']);
            $this->assertGreaterThanOrEqual($row['net_profit'], $row['profit_max']);
        }
        $evidence = app(\App\Services\Manager\ManagerPriceFinancialEvidence::class)->describe($product, $scheme, $preview, $settings);
        $this->assertSame('price_input_usage_v1', $evidence['input_usage']['version']);
        $this->assertSame($scheme === 'FBO' ? 'applied' : 'not_applied', $evidence['input_usage']['items']['storage_cost']);
        $own = $marketplace === 'wildberries' ? in_array($scheme, ['DBS', 'EDBS'], true) : in_array($scheme, ['RFBS', 'EXPRESS'], true);
        $this->assertSame($own ? 'applied' : 'not_applied', $evidence['input_usage']['items']['own_delivery_cost']);
        $this->assertSame($marketplace === 'ozon' && $scheme === 'EXPRESS' ? 'applied' : 'not_applied', $evidence['input_usage']['items']['marketplace_compensation']);
        $this->assertSame($marketplace === 'wildberries' && ! in_array($scheme, ['FBO', 'FBS'], true) ? 'not_applied' : 'applied', $evidence['input_usage']['items']['acceptance_cost']);
        foreach (['tax_percent', 'vat_percent', 'drr_percent', 'our_share_percent'] as $field) $this->assertSame('applied', $evidence['input_usage']['items'][$field]);
        if ($scheme !== 'FBO') $this->assertNotContains('storage_cost', $evidence['unverified_applied_inputs']);
        $this->assertFalse($evidence['execution_allowed']);
        $this->assertSame([], $mutations);
        $this->assertSame($cacheCount, UnitEconomicsCache::count());
        $this->assertEquals(1000, $product->fresh()->price);
        Http::assertNothingSent();
    }

    public function test_seller_expenses_can_turn_a_positive_wb_base_result_into_a_loss(): void
    {
        [$product, $settings] = $this->fixture('wildberries', 'DBS');
        $settings->update(['cost_price' => 500, 'drr_percent' => 20, 'tax_percent' => 6, 'vat_percent' => 20, 'our_share_percent' => 10]);
        $preview = app(UnitEconomicsCacheService::class)->previewPrice($product, 'DBS', 1000);
        // 1000 - 500 purchase - 50 delivery - 100 commission - 20 acquiring
        // - 200 DRR - 60 tax - 200 VAT - 100 share = -230 RUB.
        $this->assertEquals(-230, $preview['after']['net_profit']);
        $this->assertEquals(1230, $preview['after']['total_costs']);
        $this->assertFalse($preview['after']['is_profitable']);
        $this->assertEquals(-23, $preview['after']['margin_percent']);
        $this->assertEquals(-46, $preview['after']['roi']);
    }

    #[DataProvider('schemes')]
    public function test_fields_reported_as_unused_cannot_change_the_calculator_totals(string $marketplace, string $scheme): void
    {
        [$product] = $this->fixture($marketplace, $scheme);
        $preview = app(UnitEconomicsCacheService::class)->previewPrice($product, $scheme, 1200);
        $input = $preview['inputs'];
        $orchestrator = app(UnitEconomicsOrchestrator::class);
        $before = $orchestrator->calculate(CalculationInput::fromArray($input));
        foreach ($preview['after']['financial_input_usage']['items'] as $field => $usage) {
            if ($usage !== 'not_applied') continue;
            $candidate = $input;
            $candidate[$field] = 100000;
            $after = $orchestrator->calculate(CalculationInput::fromArray($candidate));
            $this->assertEqualsWithDelta($before->netProfit, $after->netProfit, 0.000001, $field);
            $this->assertEqualsWithDelta($before->totalCosts, $after->totalCosts, 0.000001, $field);
            $this->assertSame($before->costs->toArray(), $after->costs->toArray(), $field);
        }
        $this->assertSame(0, UnitEconomicsCache::count());
        Http::assertNothingSent();
    }

    public function test_wb_packaging_and_additional_costs_reduce_profit_and_reconcile_the_domain_total(): void
    {
        Http::preventStrayRequests();
        $result = app(UnitEconomicsOrchestrator::class)->calculate(CalculationInput::fromArray([
            'sku' => 'PACKAGING-PARITY', 'integration_id' => 62400, 'marketplace' => 'wildberries', 'fulfillment_type' => 'DBS',
            'price' => 1000, 'cost_price' => 500, 'commission_rate' => 10, 'acquiring_percent' => 2, 'own_delivery_cost' => 50,
            'redemption_rate' => 100, 'drr_percent' => 5, 'tax_percent' => 6, 'packaging_cost' => 12.34, 'additional_costs' => 7.66,
        ]));
        $this->assertEquals(200, $result->netProfit);
        $this->assertEquals(800, $result->totalCosts);
        $this->assertEquals($result->revenue - $result->totalCosts, $result->netProfit);
        Http::assertNothingSent();
    }

    private function fixture(string $marketplace, string $scheme): array
    {
        Http::preventStrayRequests();
        config(['ozon_rfbs_tariffs' => ['enabled' => true, 'version' => 'test-only',
            'price_bands' => [['label' => 'test', 'max' => null]], 'weight_bands' => [['label' => 'test', 'max' => null]], 'rates' => [[100]]]]);
        Integration::factory()->{$marketplace}()->create(['id' => 62400, 'settings' => ['own_delivery_cost' => 50, 'own_return_cost' => 20,
            'rfbs_logistics_tariffs' => ['enabled' => true, 'rates' => [[100]]]]]);
        $product = Product::factory()->{$marketplace}()->create(['integration_id' => 62400, 'sku' => 'FINANCIAL-PARITY',
            'price' => 1000, 'old_price' => 1000, 'fulfillment_type' => $scheme,
            'wb_data' => ['nmID' => 91001, 'commissions_by_scheme' => [strtolower($scheme) => ['percent' => 10]]],
            'ozon_data' => ['actual_price' => 1000, 'dimensions' => ['depth' => 100, 'width' => 100, 'height' => 100, 'weight' => 1000],
                'commissions_by_scheme' => [strtolower($scheme) => ['percent' => 10]]]]);
        $settings = UnitEconomicsSettings::create(['integration_id' => 62400, 'sku' => $product->sku, 'cost_price' => 200,
            'drr_percent' => 0, 'tax_percent' => 0, 'vat_percent' => 0, 'our_share_percent' => 0, 'redemption_rate_override' => 100]);
        UnitEconomics::create(['integration_id' => 62400, 'sku' => $product->sku, 'marketplace' => $marketplace,
            'fulfillment_type' => $scheme, 'is_actual_scheme' => true, 'price' => 1000, 'cost_price' => 200,
            'commission_percent' => 10, 'acquiring_percent' => 2, 'redemption_rate' => 100]);
        if ($marketplace === 'ozon') {
            // Only PostgreSQL locality reads are replaced in the SQLite fixture.
            $this->mock(OzonLocalityService::class, function ($m) {
                $m->shouldReceive('resolveSkuLocality')->andReturn(['total_orders' => 0, 'locality_rate' => null, 'clusters_summary' => []]);
                $m->shouldReceive('buildShippingRoutesForIntegration')->andReturn([]);
                $m->shouldReceive('countSellerFboOrders7Days')->andReturn(0);
            });
        }

        return [$product, $settings];
    }
}
