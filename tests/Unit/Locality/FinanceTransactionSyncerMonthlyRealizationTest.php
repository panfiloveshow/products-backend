<?php

namespace Tests\Unit\Locality;

use App\Domains\Locality\Ingestion\FinanceTransactionSyncer;
use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\UnitEconomics\OzonRatePolicy;
use App\Models\OzonFinanceTransaction;
use App\Services\Ozon\OzonActualRatesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceTransactionSyncerMonthlyRealizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_rows_for_the_same_sku_are_summed_and_resync_is_idempotent(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 8));
        Http::preventStrayRequests();
        Http::fake([
            'api-seller.ozon.ru/v2/finance/realization' => Http::response(['result' => ['rows' => [
                ['item' => ['sku' => 1275702683, 'offer_id' => 'P002'], 'delivery_commission' => ['quantity' => 2, 'amount' => 310]],
                ['item' => ['sku' => 42, 'offer_id' => 'P020'], 'delivery_commission' => ['quantity' => 1, 'amount' => 1000]],
                ['item' => ['sku' => 1275702683, 'offer_id' => 'P002'], 'delivery_commission' => ['quantity' => 3, 'amount' => 291.81]],
                ['item' => ['sku' => 1275702683], 'delivery_commission' => ['quantity' => 0, 'amount' => 50]],
                ['item' => ['sku' => 1275702683], 'delivery_commission' => ['quantity' => 1, 'amount' => 0]],
            ]]]),
        ]);

        $syncer = new FinanceTransactionSyncer();
        $method = new \ReflectionMethod($syncer, 'syncMonthlyRealizationSales');
        $client = new OzonClient('client', 'key');

        $this->assertSame([2, 0], $method->invoke($syncer, $client, 15));
        $sale = OzonFinanceTransaction::where('operation_id', 'real:2026-09:1275702683')->sole();
        $this->assertSame(5, $sale->raw['quantity']);
        $this->assertSame('601.81', $sale->amount);
        $this->assertSame('601.81', $sale->accruals_for_sale);
        $this->assertSame('2026-09-30', $sale->operation_date->toDateString());
        $this->assertSame([0, 0], $method->invoke($syncer, $client, 15));
        $this->assertSame(2, OzonFinanceTransaction::count());

        OzonFinanceTransaction::create([
            'integration_id' => 15,
            'operation_id' => 'mile:1275702683',
            'operation_type' => 'LastMileCourier',
            'operation_date' => '2026-09-30',
            'sku' => '1275702683',
            'amount' => -20,
        ]);
        $policy = new OzonRatePolicy(new OzonActualRatesService());
        $this->assertSame(4.0, $policy->lastMileCost([], 15, [1275702683]));
    }
}
