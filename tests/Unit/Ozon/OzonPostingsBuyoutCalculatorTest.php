<?php

namespace Tests\Unit\Ozon;

use App\Services\Ozon\OzonPostingsBuyoutCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OzonPostingsBuyoutCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_accepted_orders_are_counted_as_not_redeemed_in_rate(): void
    {
        $integrationId = 59;
        $sku = '2082/brown';

        $this->createPostingItems($integrationId, $sku, 'delivered', 13);
        $this->createPostingItems($integrationId, $sku, 'cancelled', 12);
        $this->createPostingItems($integrationId, $sku, 'not_accepted', 1);

        $result = (new OzonPostingsBuyoutCalculator())->calculateForSku($integrationId, $sku, 28);

        $this->assertNotNull($result);
        $this->assertSame(50.0, $result['redemption_rate']);
        $this->assertSame(26, $result['orders_count']);
        $this->assertSame(13, $result['delivered_count']);
        $this->assertSame(12, $result['cancelled_count']);
        $this->assertSame(1, $result['not_redeemed_count']);
        $this->assertSame(0, $result['returns_count']);
    }

    public function test_in_flight_orders_are_counted_optimistically_as_delivered(): void
    {
        // Виджет Ozon считает delivering-заказы уже выкупленными,
        // поэтому и мы делаем так же: (delivered + in_flight) / total.
        $integrationId = 59;
        $sku = '2082/brown';

        $this->createPostingItems($integrationId, $sku, 'delivered', 1);
        $this->createPostingItems($integrationId, $sku, 'cancelled', 1);
        $this->createPostingItems($integrationId, $sku, 'delivering', 3);

        $result = (new OzonPostingsBuyoutCalculator())->calculateForSku($integrationId, $sku, 28);

        $this->assertNotNull($result);
        $this->assertSame(80.0, $result['redemption_rate']); // (1+3) / 5
        $this->assertSame(5, $result['orders_count']);
        $this->assertSame(4, $result['delivered_count']);
        $this->assertSame(1, $result['delivered_confirmed_count']);
        $this->assertSame(3, $result['in_flight_count']);
    }

    private function createPostingItems(int $integrationId, string $sku, string $status, int $quantity): void
    {
        for ($index = 0; $index < $quantity; $index++) {
            $postingId = (string) Str::uuid();
            $postingItemId = (string) Str::uuid();
            $postingNumber = (string) Str::uuid();

            DB::table('postings')->insert([
                'id' => $postingId,
                'integration_id' => (string) $integrationId,
                'marketplace' => 'ozon',
                'posting_number' => $postingNumber,
                'status' => $status,
                'in_process_at' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('posting_items')->insert([
                'id' => $postingItemId,
                'posting_id' => $postingId,
                'sku' => $sku,
                'name' => 'Test item',
                'quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ozon_order_unit_economics')->insert([
                'integration_id' => $integrationId,
                'posting_id' => $postingId,
                'posting_item_id' => $postingItemId,
                'posting_number' => $postingNumber,
                'sku' => $sku,
                'offer_id' => $sku,
                'order_date' => now()->subDay(),
                'sale_price' => 1000,
                'base_logistics_tariff' => 100,
                'non_local_markup_percent' => 0,
                'non_local_markup_amount' => 0,
                'markup_applied' => false,
                'calculation_mode' => 'factual',
                'calculation_confidence' => 'high',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
