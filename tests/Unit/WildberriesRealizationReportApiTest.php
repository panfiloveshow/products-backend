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
        $this->assertSame('2026-07-15T10:00:00+00:00', $result['observed_at']);
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

    public function test_storage_and_acquiring_are_built_from_one_finance_request(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        Http::fake([
            'finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed' => Http::response([
                [
                    'rrdId' => 1,
                    'reportId' => 10,
                    'dateFrom' => '2026-06-15',
                    'dateTo' => '2026-06-21',
                    'sku' => 'sku-1',
                    'nmId' => 42,
                    'vendorCode' => 'v-1',
                    'paidStorage' => '6.50',
                    'retailAmount' => '1000',
                    'acquiringFee' => '35',
                ],
            ]),
        ]);

        $result = (new RealizationReportApi(new WildberriesClient('test-token')))
            ->getStorageAndAcquiringBySku(4);

        $this->assertSame('success', $result['status']);
        $this->assertSame(6.5, $result['storage_by_sku']['sku-1']['storage_fee_total']);
        $this->assertSame(3.5, $result['acquiring']['by_sku']['sku-1']);
        $this->assertSame(3.5, $result['acquiring']['avg']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $fields = $request->data()['fields'] ?? [];

            return in_array('paidStorage', $fields, true)
                && in_array('acquiringFee', $fields, true)
                && in_array('retailAmount', $fields, true);
        });
    }

    public function test_rate_limited_finance_report_is_deferred_without_short_retries(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        Http::fake([
            'finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed' => Http::response(
                ['error' => 'rate limit'],
                429,
                ['X-RateLimit-Retry' => '3600']
            ),
        ]);

        $result = (new RealizationReportApi(new WildberriesClient('test-token')))
            ->getStorageAndAcquiringBySku(4);

        $this->assertSame('rate_limited', $result['status']);
        $this->assertSame(3600, $result['retry_after']);
        $this->assertNull($result['acquiring']['observed_at']);
        Http::assertSentCount(1);
    }
}
