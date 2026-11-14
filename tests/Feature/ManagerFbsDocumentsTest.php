<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ManagerFbsDocumentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $market = 'ozon';

    private int $user = 42;

    private string $key = 'vendor-secret';

    private array $permissions = ['auto_supply.view', 'auto_supply.export', 'integrations.view'];

    private bool $validFile = true;

    private bool $ready = true;

    private int $downloads = 0;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+enfoAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true]);
        Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($mock) => $mock->shouldReceive('getServiceToken')->andReturn('service-session'));
        $this->withToken('human-session')->withHeader('X-Sellico-Workspace', '8');
        Http::fake(function ($r) {
            $url = $r->url();
            if ($url === 'https://crm.test/api/user') {
                return Http::response(['id' => $this->user, 'is_service_account' => false]);
            }
            if ($url === 'https://crm.test/api/workspaces/8/user-permissions') {
                return Http::response(['user_id' => $this->user, 'permissions' => $this->permissions]);
            }
            if ($url === 'https://crm.test/api/get-integrations/8') {
                return Http::response([['id' => 62001, 'work_space_id' => 8, 'status' => 'active', 'type' => $this->market, 'name' => 'Test store', 'api_key' => $this->key, 'client_id' => '555']]);
            }
            if ($url === 'https://api-seller.ozon.ru/v3/posting/fbs/get') {
                return Http::response(['result' => ['posting_number' => '100-1-1', 'status' => $this->ready ? 'awaiting_deliver' : 'awaiting_packaging',
                    'products' => [['sku' => 101, 'offer_id' => 'SKU', 'quantity' => 2]], 'customer' => ['phone' => 'private-customer']]]);
            }
            if ($url === 'https://marketplace-api.wildberries.ru/api/v3/orders/status') {
                return Http::response(['orders' => [['id' => 11, 'supplierStatus' => $this->ready ? 'confirm' : 'new']]]);
            }
            if (str_starts_with($url, 'https://marketplace-api.wildberries.ru/api/v3/orders/stickers?')) {
                $this->downloads++;
                $this->assertSame([11], $r['orders']);
                $this->assertStringContainsString('type=png&width=58&height=40', $url);

                return Http::response(['stickers' => [['orderId' => 11, 'file' => $this->validFile ? self::PNG : base64_encode('<svg><script>unsafe</script></svg>')]]]);
            }
            if ($url === 'https://api-seller.ozon.ru/v3/posting/fbs/package-label/create') {
                $this->assertSame(['100-1-1'], $r['posting_numbers']);
                return Http::response(['tasks' => [['task_id' => 301, 'task_type' => 'small_label']]]);
            }
            if ($url === 'https://api-seller.ozon.ru/v2/posting/fbs/package-label/get') {
                $this->assertSame(301, $r['task_id']);
                return Http::response(['status' => ['code' => 'completed', 'postings_count' => 1, 'printed_postings_count' => 1, 'unprinted_postings' => []], 'file_url' => 'https://cdn1.ozone.ru/label-test.pdf']);
            }
            if ($url === 'https://cdn1.ozone.ru/label-test.pdf') {
                $this->downloads++;

                return Http::response($this->validFile ? "%PDF-1.7\nTest only\n%%EOF" : '{"private-customer":"error"}', 200, ['Content-Type' => $this->validFile ? 'application/pdf' : 'application/json']);
            }
            $this->fail('Unexpected outbound request');
        });
    }

    private function prepare(string $market = 'ozon'): array
    {
        $this->market = $market;
        Integration::factory()->{$market}()->create(['id' => 62001, 'work_space_id' => 8]);

        return $this->getJson('/api/manager/fbs/label/context?integration_id=62001&external_id='.($market === 'ozon' ? '100-1-1' : '11'))->assertOk()->json();
    }

    private function download(array $doc): TestResponse
    {
        return $this->postJson('/api/manager/fbs/label/download', ['download_ticket' => $doc['download_ticket']]);
    }

    public function test_ozon_prepares_metadata_then_downloads_only_a_verified_pdf_without_status_mutations(): void
    {
        $doc = $this->prepare();
        $this->assertSame(0, $this->downloads);
        $this->assertSame('pdf', $doc['format']);
        $this->assertArrayNotHasKey('bytes', $doc);
        $this->assertStringNotContainsString('private-customer', json_encode($doc));
        $response = $this->download($doc)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame(1, $this->downloads);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/ship') || str_contains($r->url(), '/act/create'));
    }

    public function test_wb_downloads_png_for_only_the_selected_task_and_rejects_script_formats(): void
    {
        $doc = $this->prepare('wildberries');
        $response = $this->download($doc)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(base64_decode(self::PNG), $response->getContent());
        $this->validFile = false;
        $this->download($doc)->assertStatus(503)->assertDontSee('unsafe');
    }

    public function test_owner_key_rotation_expiry_and_tampering_are_checked_before_fetching_a_file(): void
    {
        $doc = $this->prepare();
        $this->user = 43;
        $this->download($doc)->assertGone();
        $this->user = 42;
        $this->key = 'rotated';
        $this->download($doc)->assertGone();
        $this->key = 'vendor-secret';
        $this->travel(5)->minutes();
        $this->download($doc)->assertGone();
        $this->download(['download_ticket' => $doc['download_ticket'].'x'])->assertGone();
        $this->assertSame(0, $this->downloads);
    }

    public function test_export_rights_are_rechecked_and_a_new_unassembled_state_blocks_the_download(): void
    {
        $doc = $this->prepare();
        $this->permissions = ['auto_supply.view', 'integrations.view'];
        $this->download($doc)->assertForbidden();
        $this->permissions[] = 'auto_supply.export';
        $this->ready = false;
        $this->download($doc)->assertUnprocessable();
        $this->assertSame(0, $this->downloads);
    }

    public function test_a_json_error_page_is_not_returned_as_a_pdf_or_model_data(): void
    {
        $doc = $this->prepare();
        $this->validFile = false;
        $this->download($doc)->assertStatus(503)->assertDontSee('private-customer');
        $this->postJson('/api/manager/fbs/label/download', ['download_ticket' => $doc['download_ticket'], 'url' => 'https://other.test'])->assertUnprocessable();
    }
}
