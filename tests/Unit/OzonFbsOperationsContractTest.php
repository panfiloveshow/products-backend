<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\FbsPostingsApi;
use App\Domains\Ozon\Api\FbsReturnsApi;
use App\Domains\Ozon\Api\OzonClient;
use App\Models\Integration;
use App\Models\Posting;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * FBS-операции Ozon после отключений 2025–2026:
 * - акт: /v2/posting/fbs/act/create (07.09.2026) → /v1/carriage/create + /v1/carriage/approve;
 * - этикетки: /v2/posting/fbs/package-label (02.11.2026) → /v3/.../create + /v2/.../get;
 * - причины отмены: /v1/posting/fbs/cancel-reason/list → /v2;
 * - возвраты: /v3/returns/company/fbs (18.02.2025) → /v1/returns/list.
 */
class OzonFbsOperationsContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function integration(): Integration
    {
        return Integration::factory()->ozon()->create(['id' => random_int(700000, 799999), 'work_space_id' => 91]);
    }

    private function posting(Integration $integration, string $number): Posting
    {
        return Posting::create([
            'integration_id' => (string) $integration->id,
            'marketplace' => 'ozon',
            'posting_number' => $number,
            'status' => 'awaiting_deliver',
            'delivery_type' => 'fbs',
        ]);
    }

    public function test_create_act_creates_and_approves_carriage(): void
    {
        $integration = $this->integration();
        Http::fake([
            '*/v1/carriage/create' => Http::response(['carriage_id' => 555]),
            '*/v1/carriage/approve' => Http::response([]),
        ]);

        $result = app(PostingService::class)->createAct((string) $integration->id, '2026-09-23');

        $this->assertSame(['act_id' => 555, 'carriage_id' => 555], $result);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/carriage/create')
            && $r['departure_date'] === '2026-09-23T00:00:00Z');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/carriage/approve')
            && $r['carriage_id'] === 555);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v2/posting/fbs/act/create'));
    }

    public function test_create_act_reports_unapproved_carriage(): void
    {
        $integration = $this->integration();
        Http::fake([
            '*/v1/carriage/create' => Http::response(['carriage_id' => 556]),
            '*/v1/carriage/approve' => Http::response(['code' => 9, 'message' => 'carriage is not in status new'], 409),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Отгрузка 556 создана, но не подтверждена: carriage is not in status new');

        app(PostingService::class)->createAct((string) $integration->id, '2026-09-23');
    }

    public function test_label_is_created_as_task_and_polled_until_file_ready(): void
    {
        Sleep::fake();
        $integration = $this->integration();
        $posting = $this->posting($integration, '12345-0001-1');

        $polls = 0;
        Http::fake(function (Request $r) use (&$polls) {
            if (str_ends_with($r->url(), '/v3/posting/fbs/package-label/create')) {
                return Http::response(['tasks' => [
                    ['task_id' => 71, 'task_type' => 'small_label'],
                    ['task_id' => 70, 'task_type' => 'big_label'],
                ]]);
            }
            if (str_ends_with($r->url(), '/v2/posting/fbs/package-label/get')) {
                $polls++;

                return Http::response($polls === 1
                    ? ['status' => ['code' => 'in_progress']]
                    : ['status' => ['code' => 'completed', 'postings_count' => 1, 'printed_postings_count' => 1], 'file_url' => 'https://cdn.ozon.test/labels/70.pdf']);
            }

            return Http::response(['message' => 'unexpected '.$r->url()], 404);
        });

        $label = app(PostingService::class)->getLabel($posting);

        $this->assertSame('https://cdn.ozon.test/labels/70.pdf', $label['url']);
        $this->assertSame(70, $label['task_id']);
        $this->assertSame(2, $polls);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v3/posting/fbs/package-label/create')
            && $r['posting_numbers'] === ['12345-0001-1']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/posting/fbs/package-label/get')
            && $r['task_id'] === 70);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/v2/posting/fbs/package-label'));
    }

    public function test_bulk_labels_use_one_task_and_surface_label_errors(): void
    {
        Sleep::fake();
        $integration = $this->integration();
        $ids = [
            $this->posting($integration, 'A-1')->id,
            $this->posting($integration, 'A-2')->id,
        ];
        Http::fake([
            '*/v3/posting/fbs/package-label/create' => Http::response(['tasks' => [['task_id' => 80, 'task_type' => 'big_label']]]),
            '*/v2/posting/fbs/package-label/get' => Http::response([
                'status' => ['code' => 'error', 'unprinted_postings' => [['posting_number' => 'A-2', 'message' => 'not ready']]],
            ]),
        ]);

        try {
            app(PostingService::class)->getBulkLabels((string) $integration->id, $ids);
            $this->fail('Ожидалась ошибка формирования этикеток');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('A-2: not ready', $e->getMessage());
        }

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v3/posting/fbs/package-label/create')
            && collect($r['posting_numbers'])->sort()->values()->all() === ['A-1', 'A-2']);
    }

    public function test_cancel_reasons_use_v2_list(): void
    {
        Http::fake([
            '*/v2/posting/fbs/cancel-reason/list' => Http::response(['result' => [
                ['id' => 352, 'title' => 'Товар закончился', 'type_id' => 'seller', 'is_available_for_cancellation' => true],
            ]]),
        ]);

        $reasons = (new FbsPostingsApi(new OzonClient('client', 'key')))->getCancelReasons();

        $this->assertSame(352, $reasons[0]['id']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/posting/fbs/cancel-reason/list')
            && $r->body() === '{}');
    }

    public function test_returns_list_filters_fbs_and_emulates_offset_with_last_id(): void
    {
        Http::fake(function (Request $r) {
            if (! str_ends_with($r->url(), '/v1/returns/list')) {
                return Http::response(['message' => 'unexpected'], 404);
            }

            // Ozon отдаёт по 3 записи: страница 1 — id 1..3, страница 2 (last_id=3) — id 4..6.
            $from = ($r['last_id'] ?? 0) === 3 ? 4 : 1;
            $rows = array_map(fn (int $id) => ['id' => $id, 'schema' => 'FBS', 'posting_number' => "P-{$id}"], range($from, $from + min(3, $r['limit']) - 1));

            return Http::response(['returns' => $rows, 'has_next' => true]);
        });

        $api = new FbsReturnsApi(new OzonClient('client', 'key'));
        // page=2, per_page=3 из контроллера → offset 3, limit 3.
        $result = $api->list(['status' => 'MovingToSeller'], 3, 3);

        $this->assertSame([4, 5, 6], array_column($result['returns'], 'id'));
        $this->assertTrue($result['has_next']);
        Http::assertSent(fn (Request $r) => $r['filter'] === ['return_schema' => 'FBS', 'visual_status_name' => 'MovingToSeller']
            && $r['limit'] === 6 && ! isset($r['last_id']) && ! isset($r['offset']));
        Http::assertSent(fn (Request $r) => ($r['last_id'] ?? null) === 3 && $r['limit'] === 3);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/returns/company/fbs'));
    }

    public function test_returns_list_error_is_not_silenced(): void
    {
        Http::fake(['*/v1/returns/list' => Http::response(['code' => 7, 'message' => 'permission denied'], 403)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('permission denied');

        (new FbsReturnsApi(new OzonClient('client', 'key')))->list();
    }
}
