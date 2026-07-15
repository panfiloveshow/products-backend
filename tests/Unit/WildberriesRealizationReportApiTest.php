<?php

namespace Tests\Unit;

use App\Domains\Wildberries\Api\RealizationReportApi;
use App\Domains\Wildberries\Api\WildberriesClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WildberriesRealizationReportApiTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_acquiring_uses_new_finance_report_and_camel_case_fields(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        Http::fake([
            'finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed' => Http::response([
                [
                    'rrdId' => 100,
                    'sku' => '2038000000001',
                    'nmId' => 123456,
                    'vendorCode' => 'A-1',
                    'retailAmount' => '1000',
                    'acquiringFee' => '35',
                ],
            ]),
        ]);

        $result = (new RealizationReportApi(new WildberriesClient('test-token')))->getAcquiringBySku(4);

        $this->assertSame(3.5, $result['by_sku']['2038000000001']);
        $this->assertSame(3.5, $result['by_sku']['123456']);
        $this->assertSame(3.5, $result['by_sku']['A-1']);
        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->url() === 'https://finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed'
                && $payload['dateFrom'] === '2026-06-15'
                && $payload['dateTo'] === '2026-07-12'
                && $payload['limit'] === 100000
                && $payload['rrdId'] === 0
                && $payload['period'] === 'weekly'
                && in_array('acquiringFee', $payload['fields'], true);
        });
    }

    public function test_storage_fees_use_paid_storage_from_new_finance_report(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        Http::fake([
            'finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed' => Http::response([
                ['rrdId' => 1, 'reportId' => 10, 'dateFrom' => '2026-06-16', 'dateTo' => '2026-06-22', 'sku' => 'sku-1', 'nmId' => 42, 'vendorCode' => 'v-1', 'paidStorage' => '4.5'],
                ['rrdId' => 2, 'reportId' => 11, 'dateFrom' => '2026-06-23', 'dateTo' => '2026-06-29', 'sku' => 'sku-1', 'nmId' => 42, 'vendorCode' => 'v-1', 'paidStorage' => '6.5'],
            ]),
        ]);

        $result = (new RealizationReportApi(new WildberriesClient('test-token')))->getStorageFeesBySku(4);

        $this->assertSame(11.0, $result['sku-1']['storage_fee_total']);
        $this->assertSame(6.5, $result['sku-1']['storage_fee_last_week']);
    }
}
