<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Models\Posting;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * /v3/posting/fbs/list и /v2/posting/fbo/list отключены 31.08.2026:
 * синк идёт через /v4 FBS и /v3 FBO — cursor, limit ≤ 100, postings в корне,
 * цена товара — объект {amount, currency}.
 */
class OzonPostingsListContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sync_uses_v4_fbs_and_v3_fbo_with_cursor_pagination(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => random_int(800000, 899999), 'work_space_id' => 91]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            $page2 = ($request['cursor'] ?? '') === 'c1';

            if (str_ends_with($url, '/v4/posting/fbs/list')) {
                return Http::response($page2
                    ? ['postings' => [$this->posting('FBS-2', 'delivered')], 'cursor' => '', 'has_next' => false]
                    : ['postings' => [$this->posting('FBS-1', 'delivering')], 'cursor' => 'c1', 'has_next' => true]);
            }
            if (str_ends_with($url, '/v3/posting/fbo/list')) {
                return Http::response(['postings' => [$this->posting('FBO-1', 'delivered')], 'cursor' => 'x', 'has_next' => false]);
            }

            return Http::response(['message' => 'unexpected ' . $url], 404);
        });

        $result = app(PostingService::class)->sync((string) $integration->id);

        $this->assertSame(3, $result['synced']);
        $this->assertSame(['FBO-1', 'FBS-1', 'FBS-2'], Posting::orderBy('posting_number')->pluck('posting_number')->all());

        $item = Posting::where('posting_number', 'FBS-1')->first()->items()->first();
        $this->assertEquals(1250.5, (float) $item->price);
        $this->assertNotNull($integration->fresh()->ozon_postings_synced_until);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v4/posting/fbs/list')
            && $r['limit'] === 100 && ! isset($r['offset']) && $r['sort_dir'] === 'ASC');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v3/posting/fbs/list')
            || str_contains($r->url(), '/v2/posting/fbo/list'));
    }

    public function test_api_error_does_not_advance_watermark(): void
    {
        $integration = Integration::factory()->ozon()->create(['id' => random_int(800000, 899999), 'work_space_id' => 91]);

        Http::fake([
            '*/v4/posting/fbs/list' => Http::response(['code' => 5, 'message' => 'obsolete method'], 404),
            '*' => Http::response(['postings' => [], 'has_next' => false]),
        ]);

        try {
            app(PostingService::class)->sync((string) $integration->id);
            $this->fail('Ожидалось исключение на ошибке API');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('/v4/posting/fbs/list', $e->getMessage());
        }

        $this->assertNull($integration->fresh()->ozon_postings_synced_until);
    }

    private function posting(string $number, string $status): array
    {
        return [
            'posting_number' => $number,
            'order_id' => 1,
            'order_number' => 'O-' . $number,
            'status' => $status,
            'in_process_at' => '2026-09-20T10:00:00Z',
            'analytics_data' => ['warehouse_id' => 42, 'warehouse' => 'Хоругвино'],
            'financial_data' => ['cluster_to' => 'Москва', 'products' => []],
            'products' => [[
                'sku' => 1001,
                'offer_id' => 'ART-1',
                'name' => 'Товар',
                'quantity' => 1,
                'price' => ['amount' => '1250.50', 'currency' => 'RUB'],
            ]],
        ];
    }
}
