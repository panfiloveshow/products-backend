<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\AnalyticsApi;
use App\Domains\Ozon\Api\AnalyticsDataClient;
use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\SalesApi;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * /v1/analytics/data: не чаще 1 раза в минуту; без Premium Plus/Pro — 50 запросов
 * в сутки, 3 месяца данных, только revenue и ordered_units.
 */
class OzonAnalyticsDataLimitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
        Carbon::setTestNow('2026-09-22 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function client(): OzonClient
    {
        return new OzonClient('client', 'key');
    }

    private function row(string $sku, array $metrics): array
    {
        return ['dimensions' => [['id' => $sku, 'name' => '']], 'metrics' => $metrics];
    }

    public function test_pages_are_spaced_by_a_minute_and_metrics_parsed_by_name(): void
    {
        Http::fakeSequence('*/v1/analytics/data')
            ->push(['result' => ['data' => [$this->row('1', [5, 500]), $this->row('2', [3, 300])]]])
            ->push(['result' => ['data' => [$this->row('3', [1, 100])]]]);

        $report = (new AnalyticsDataClient($this->client()))->fetch([
            'date_from' => '2026-09-01', 'date_to' => '2026-09-21',
            'metrics' => ['ordered_units', 'revenue'], 'dimension' => ['sku'], 'limit' => 2, 'offset' => 0,
        ], 5);

        $this->assertSame('ok', $report['status']);
        $this->assertSame(['ordered_units' => 1.0, 'revenue' => 100.0], $report['rows'][2]['metrics']);
        Sleep::assertSequence([Sleep::for(61)->seconds()]);
        Http::assertSentInOrder([
            fn (Request $r) => $r['offset'] === 0,
            fn (Request $r) => $r['offset'] === 2,
        ]);
    }

    public function test_premium_metrics_are_not_requested_without_known_subscription(): void
    {
        Http::fake(['*/v1/analytics/data' => Http::response(['result' => ['data' => [$this->row('7', [10])]]])]);

        $sales = new SalesApi($this->client());
        $this->assertSame([], $sales->getReturnsStatsBySku('2026-08-23', '2026-09-22'));
        $this->assertSame([], $sales->getCancellationsStatsBySku('2026-08-23', '2026-09-22'));
        Http::assertNothingSent();

        $this->assertSame(['7' => 10], $sales->getOrdersStatsBySku('2025-01-01', '2026-09-22'));
        // Без подписки окно — последние 3 месяца.
        Http::assertSent(fn (Request $r) => $r['metrics'] === ['ordered_units'] && $r['date_from'] === '2026-06-22');
    }

    public function test_rejected_premium_metrics_fall_back_to_basic_and_are_remembered(): void
    {
        Http::fakeSequence('*/v1/analytics/data')
            ->push(['code' => 3, 'message' => 'deprecated metrics used'], 400)
            ->push(['result' => ['data' => [$this->row('1', [42, 7])]]]);

        $analytics = new AnalyticsDataClient($this->client());
        $report = $analytics->fetch([
            'date_from' => '2026-09-01', 'date_to' => '2026-09-21',
            'metrics' => ['revenue', 'returns', 'ordered_units'], 'dimension' => ['sku'], 'limit' => 1000,
            'sort' => [['key' => 'returns', 'order' => 'DESC']],
        ], 1, true);

        $this->assertSame('ok', $report['status']);
        $this->assertSame(['revenue' => 42.0, 'ordered_units' => 7.0], $report['rows'][0]['metrics']);
        $this->assertFalse($analytics->premiumKnown());
        Http::assertSentInOrder([
            fn (Request $r) => $r['metrics'] === ['revenue', 'returns', 'ordered_units'],
            fn (Request $r) => $r['metrics'] === ['revenue', 'ordered_units'] && ! isset($r['sort']),
        ]);
    }

    public function test_rate_limit_is_not_retried(): void
    {
        Http::fake(['*/v1/analytics/data' => Http::response(['message' => 'Too many requests'], 429)]);

        $report = (new AnalyticsDataClient($this->client()))->fetch([
            'date_from' => '2026-09-01', 'date_to' => '2026-09-21',
            'metrics' => ['ordered_units'], 'dimension' => ['day'], 'limit' => 31,
        ], 10);

        $this->assertSame('rate_limited', $report['status']);
        Http::assertSentCount(1);
    }

    public function test_premium_check_caches_rejection_for_a_day(): void
    {
        Http::fake(['*/v1/analytics/data' => Http::response(['message' => 'forbidden'], 403)]);
        $api = new AnalyticsApi($this->client());

        $this->assertFalse($api->checkPremiumStatus()['is_premium']);
        $this->assertFalse($api->checkPremiumStatus()['is_premium']);
        $this->assertSame([], $api->getRedemptionRateFromAnalytics());

        // Проба без отката на базовые метрики, повторных запросов нет.
        Http::assertSentCount(1);
    }

    public function test_redemption_uses_named_premium_metrics(): void
    {
        Http::fake(['*/v1/analytics/data' => Http::response(['result' => ['data' => [
            $this->row('555', [10, 8, 1, 2]),
        ]]])]);

        $result = (new AnalyticsApi($this->client()))->getRedemptionRateFromAnalytics(null, null, ['555' => 'ART-5']);

        $this->assertSame(70.0, $result['ART-5']['redemption_rate']);
        $this->assertSame(2, $result['ART-5']['cancelled_count']);
        Http::assertSentCount(1); // без отдельной пробы Premium
    }
}
