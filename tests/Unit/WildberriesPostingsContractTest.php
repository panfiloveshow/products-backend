<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Models\Posting;
use App\Services\PostingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WB FBS: GET /api/v3/orders — окно ≤ 30 дней, только задания моложе 3 месяцев
 * (с 21.07.2026), пагинация limit + next; стикеры — POST /api/v3/orders/stickers
 * (GET /api/v3/orders/{id}/stickers не существует), 409 — нет номера ДТ.
 */
class WildberriesPostingsContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function integration(): Integration
    {
        return Integration::factory()->wildberries()->create(['id' => random_int(600000, 699999), 'work_space_id' => 91]);
    }

    private function order(int $id): array
    {
        return ['id' => $id, 'rid' => "r{$id}", 'nmId' => 111, 'article' => 'ART-1', 'price' => 150000, 'warehouseId' => 55];
    }

    public function test_orders_sync_follows_next_within_30_day_windows(): void
    {
        Carbon::setTestNow('2026-09-22 12:00:00');
        $integration = $this->integration();

        $firstPage = array_map(fn (int $id) => $this->order($id), range(1, 1000));
        Http::fake(function (Request $request) use ($firstPage) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) $query['next'] === 0
                ? Http::response(['next' => 555, 'orders' => $firstPage])
                : Http::response(['next' => 0, 'orders' => [$this->order(1001)]]);
        });

        $result = app(PostingService::class)->sync((string) $integration->id);

        $this->assertSame(1001, $result['synced']);
        $this->assertSame(1001, Posting::where('integration_id', $integration->id)->count());

        $requests = Http::recorded()->map(function ($pair) {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return $query;
        });
        $this->assertCount(2, $requests);
        $this->assertSame(['0', '555'], $requests->pluck('next')->all());
        foreach ($requests as $query) {
            $this->assertSame('1000', $query['limit']);
            $this->assertLessThanOrEqual(30 * 86400, (int) $query['dateTo'] - (int) $query['dateFrom']);
        }
    }

    public function test_orders_sync_clamps_date_from_to_three_months(): void
    {
        Carbon::setTestNow('2026-09-22 12:00:00');
        $integration = $this->integration();
        Http::fake(['marketplace-api.wildberries.ru/api/v3/orders*' => Http::response(['next' => 0, 'orders' => []])]);

        app(PostingService::class)->sync((string) $integration->id, null, '2026-01-01');

        $from = Http::recorded()->map(function ($pair) {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return (int) $query['dateFrom'];
        });
        $this->assertGreaterThanOrEqual(Carbon::parse('2026-06-22 12:00:00')->timestamp, $from->min());
        // 3 месяца окнами по 30 дней — 4 запроса, архив не запрашиваем
        $this->assertCount(4, $from);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'orders/archive'));
    }

    public function test_orders_sync_api_error_is_not_reported_as_empty(): void
    {
        $integration = $this->integration();
        Http::fake(['marketplace-api.wildberries.ru/*' => Http::response(['code' => 'IncorrectParameter'], 400)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('/api/v3/orders');

        app(PostingService::class)->sync((string) $integration->id);
    }

    public function test_label_uses_post_stickers_with_orders_body(): void
    {
        $integration = $this->integration();
        $posting = Posting::create([
            'integration_id' => (string) $integration->id,
            'marketplace' => 'wildberries',
            'posting_number' => '5346346',
            'status' => 'awaiting_deliver',
            'delivery_type' => 'fbs',
        ]);
        Http::fake([
            'marketplace-api.wildberries.ru/api/v3/orders/stickers*' => Http::response([
                'stickers' => [['orderId' => 5346346, 'partA' => '231648', 'partB' => '9753', 'barcode' => '!uKEtQZVx', 'file' => 'iVBOR']],
            ]),
        ]);

        $label = app(PostingService::class)->getLabel($posting);

        $this->assertSame('png', $label['type']);
        $this->assertSame(5346346, $label['stickers'][0]['orderId']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://marketplace-api.wildberries.ru/api/v3/orders/stickers?type=png&width=58&height=40'
            && $r['orders'] === [5346346]);
    }

    public function test_label_409_explains_missing_customs_declaration(): void
    {
        $integration = $this->integration();
        $posting = Posting::create([
            'integration_id' => (string) $integration->id,
            'marketplace' => 'wildberries',
            'posting_number' => '5346346',
            'status' => 'awaiting_deliver',
            'delivery_type' => 'fbs',
        ]);
        Http::fake([
            'marketplace-api.wildberries.ru/*' => Http::response(['code' => 'CustomsDeclarationIsRequired', 'message' => 'Customs declaration is required'], 409),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('декларации на товары (ДТ)');

        app(PostingService::class)->getLabel($posting);
    }
}
