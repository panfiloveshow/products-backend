<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Manager\ManagerFinancialSettingsPatch;
use App\Services\UnitEconomics\CalculationFingerprint;
use Tests\TestCase;

class CalculationFingerprintTest extends TestCase
{
    public function test_same_inputs_ignore_object_key_order_but_keep_changed_rates_sources_and_list_order(): void
    {
        $product = (new Product)->forceFill(['id' => '00000000-0000-0000-0000-000000000001', 'integration_id' => 28,
            'marketplace' => 'wildberries', 'sku' => 'A', 'wb_data' => ['nmID' => 10001]]);
        $input = ['integration_id' => 28, 'sku' => 'A', 'marketplace' => 'wildberries', 'fulfillment_type' => 'DBS', 'price' => 1000,
            'vat_percent' => 3, 'our_share_percent' => 2, 'commission_rate_source' => 'observed',
            'tariff_breakdown' => ['base' => 10, 'legs' => [['a' => 1, 'b' => 2], ['a' => 3, 'b' => 4]]],
            '_extra' => ['vat_percent' => 3, 'our_share_percent' => 2]];
        $original = CalculationFingerprint::forInputs($product, $input);
        $reordered = array_reverse($input, true);
        $reordered['tariff_breakdown']['legs'][0] = ['b' => 2, 'a' => 1];
        $this->assertSame($original, CalculationFingerprint::forInputs($product, $reordered));
        foreach ([
            ['vat_percent' => 4, 'our_share_percent' => 1], // same combined cost
            ['_extra' => ['vat_percent' => 4, 'our_share_percent' => 1]], // converter-owned costs
            ['commission_rate_source' => 'model_default'],
            ['tariff_breakdown' => ['base' => 10, 'legs' => array_reverse($input['tariff_breakdown']['legs'])]],
        ] as $change) {
            $this->assertNotSame($original['input_hash'], CalculationFingerprint::forInputs($product, array_replace($input, $change))['input_hash']);
        }
        $this->assertTrue(CalculationFingerprint::matches(array_reverse($original, true), $original, $product));
        $this->assertFalse(CalculationFingerprint::matches([...$original, 'verified' => true], $original, $product));
    }

    public function test_financial_receipt_binding_keeps_the_existing_protocol_and_tracks_physical_product_changes(): void
    {
        $product = (new Product)->forceFill(['id' => '00000000-0000-0000-0000-000000000001', 'integration_id' => 28,
            'marketplace' => 'wildberries', 'sku' => 'A', 'wb_data' => ['nmID' => 10001]]);
        $oldProtocol = hash('sha256', json_encode(['00000000-0000-0000-0000-000000000001', '28', 'wildberries', 'A', '10001'], JSON_THROW_ON_ERROR));
        $this->assertSame($oldProtocol, CalculationFingerprint::productBinding($product));
        $this->assertSame($oldProtocol, (new ManagerFinancialSettingsPatch)->productBinding($product));
        $product->wb_data = ['nmID' => 10002];
        $this->assertNotSame($oldProtocol, CalculationFingerprint::productBinding($product));
    }
}
