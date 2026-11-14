<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\ManagerFbsLabelJob;
use App\Services\SellicoApiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagerOzonLabelsTest extends TestCase
{
    use LazilyRefreshDatabase;
    private int $creates = 0;
    private int $polls = 0;
    private int $files = 0;
    private string $code = 'completed';
    private string $createMode = 'ok';
    private string $key = 'test-vendor-key';
    private array $permissions = ['auto_supply.view', 'auto_supply.export', 'integrations.view'];
    private string $state = 'awaiting_deliver';
    private string $fileUrl = 'https://cdn1.ozone.ru/test/label.pdf';
    private array $tasks = [['task_id' => 70001, 'task_type' => 'small_label']];
    private int $printed = 1;
    private bool $omitEmptyUnprinted = false;
    private int $fileStatus = 200;
    private string $mime = 'application/pdf';
    private string $bytes = "%PDF-1.7\nTest only\n%%EOF";
    private $duringCreate = null;
    private $duringPoll = null;
    private $duringPosting = null;

    protected function setUp(): void
    {
        parent::setUp(); Carbon::setTestNow(Carbon::now('UTC')->startOfSecond());
        config(['services.sellico.base_url' => 'https://crm.test/api']); Http::preventStrayRequests();
        Integration::factory()->ozon()->create(['id' => 62006, 'work_space_id' => 8]);
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('test-service-session'));
        $this->withToken('owner-session')->withHeader('X-Sellico-Workspace', '8')->withHeader('X-User-Id', 'spoofed');
        Http::fake(function ($r) {
            $url = $r->url();
            if ($url === 'https://crm.test/api/user') return Http::response(['id' => 42, 'is_service_account' => false]);
            if ($url === 'https://crm.test/api/workspaces/8/user-permissions') return Http::response(['user_id' => 42, 'workspace_id' => 8, 'permissions' => $this->permissions]);
            if ($url === 'https://crm.test/api/get-integrations/8') return Http::response([['id' => 62006, 'work_space_id' => 8, 'status' => 'active', 'type' => 'ozon', 'name' => 'Native shop', 'api_key' => $this->key, 'client_id' => '555']]);
            if ($url === 'https://api-seller.ozon.ru/v3/posting/fbs/get') {
                if ($this->duringPosting) ($this->duringPosting)();
                return Http::response(['result' => ['posting_number' => '100-1-1', 'status' => $this->state, 'products' => [['sku' => 101, 'offer_id' => 'SKU', 'quantity' => 2]], 'customer' => ['phone' => 'private-buyer']]]);
            }
            if ($url === 'https://api-seller.ozon.ru/v3/posting/fbs/package-label/create') {
                $this->creates++; $this->assertSame(['posting_numbers' => ['100-1-1']], $r->data());
                $this->assertSame('test-vendor-key', $r->header('Api-Key')[0]);
                $row = ManagerFbsLabelJob::where('status', 'creating')->firstOrFail(); $this->assertNotNull($row->native_started_at);
                if ($this->duringCreate) ($this->duringCreate)($row);
                if ($this->createMode === 'lost') throw new ConnectionException('private-receipt');
                if ($this->createMode === 'throttle') return Http::response([], 429);
                if ($this->createMode === 'reject') return Http::response([], 400);
                return Http::response(['tasks' => $this->tasks]);
            }
            if ($url === 'https://api-seller.ozon.ru/v2/posting/fbs/package-label/get') {
                $this->polls++; $this->assertSame(['task_id' => 70001], $r->data());
                if ($this->duringPoll) ($this->duringPoll)();
                return Http::response(['status' => ['code' => $this->code, 'postings_count' => 1, 'printed_postings_count' => $this->printed,
                    ...($this->omitEmptyUnprinted ? [] : ['unprinted_postings' => $this->printed === 1 ? [] : [['posting_number' => '100-1-1', 'message' => 'private-vendor-error']]])],
                    'file_url' => $this->fileUrl, 'error' => $this->code === 'error' ? ['code' => 'FAIL', 'message' => 'private-vendor-error'] : []]);
            }
            if ($url === 'https://cdn1.ozone.ru/test/label.pdf') {
                $this->files++; $this->assertFalse($r->hasHeader('Api-Key')); $this->assertFalse($r->hasHeader('Client-Id')); $this->assertFalse($r->hasHeader('Authorization')); $this->assertFalse($r->hasHeader('Cookie'));
                return Http::response($this->bytes, $this->fileStatus, ['Content-Type' => $this->mime, 'Location' => 'https://untrusted.test/steal']);
            }
            throw new \RuntimeException('Unexpected test endpoint');
        });
    }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    private function prepare(): array { return $this->getJson('/api/manager/fbs/label/context?integration_id=62006&external_id=100-1-1')->assertOk()->json(); }
    private function download(array $d) { return $this->postJson('/api/manager/fbs/label/download', ['download_ticket' => $d['download_ticket']]); }
    private function capture(string $name, array $value): void
    {
        $dir = getenv('SELYA_OZON_LABEL_CONTRACT_DIR');
        if (! $dir) return;
        if (! is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($dir.'/'.$name.'.json', json_encode($value, JSON_THROW_ON_ERROR)); chmod($dir.'/'.$name.'.json', 0600);
    }

    public function test_current_contract_creates_once_downloads_private_pdf_and_keeps_native_ids_out_of_metadata(): void
    {
        $d = $this->prepare(); $this->assertSame(0, $this->creates); $this->assertStringNotContainsString('private-buyer', json_encode($d)); $this->assertArrayNotHasKey('task_id', $d);
        $response = $this->download($d)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control')); $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->travel(2)->seconds(); $this->download($d)->assertOk(); $this->assertSame(1, $this->creates); $this->assertSame(2, $this->polls); $this->assertSame(2, $this->files);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v2/posting/fbs/package-label') || str_contains($r->url(), '/ship') || str_contains($r->url(), '/act/create'));
    }
    public function test_pending_and_in_progress_are_observations_of_the_same_durable_task(): void
    {
        $this->code = 'pending'; $d = $this->prepare(); $this->capture('context', $d);
        $response = $this->download($d)->assertStatus(202)->assertJsonPath('document_status', 'pending')->assertJsonPath('can_retry', true)->assertHeader('Retry-After', '2')->assertDontSee('70001');
        $this->capture('pending', $response->json());
        $this->download($d)->assertStatus(202); $this->assertSame(1, $this->polls);
        $this->travel(2)->seconds(); $this->code = 'in_progress'; $this->download($d)->assertStatus(202);
        $this->travel(2)->seconds(); $this->code = 'completed'; $this->download($d)->assertOk(); $this->assertSame(1, $this->creates);
    }
    public function test_lost_creation_or_throttle_or_malformed_tasks_never_repeat_creation(): void
    {
        foreach (['lost', 'throttle', 'bad-task'] as $mode) {
            $this->createMode = $mode; $this->tasks = $mode === 'bad-task' ? [['task_id' => 0, 'task_type' => 'small_label']] : [['task_id' => 70001, 'task_type' => 'small_label']];
            $d = $this->prepare(); $before = $this->creates;
            $response = $this->download($d)->assertStatus(409)->assertJsonPath('document_status', 'unknown')->assertJsonPath('can_retry', false)->assertDontSee('private-receipt');
            if ($mode === 'lost') $this->capture('unknown', $response->json());
            $this->travel(2)->seconds(); $this->download($d)->assertStatus(409); $this->assertSame($before + 1, $this->creates);
        }
        $this->assertSame(0, $this->polls);
    }
    public function test_native_failure_and_partial_generation_are_not_saved_as_complete_pdfs(): void
    {
        foreach (['reject', 'partial', 'error'] as $mode) {
            $this->createMode = $mode; $this->printed = $mode === 'partial' ? 0 : 1; $this->code = $mode === 'error' ? 'error' : 'completed'; $d = $this->prepare();
            $response = $this->download($d)->assertStatus(409)->assertJsonPath('document_status', 'failed')->assertDontSee('private-vendor-error');
            if ($mode === 'partial') $this->capture('failed', $response->json());
        }
        $this->assertSame(0, $this->files);
    }
    public function test_untrusted_urls_never_receive_credentials_or_a_request(): void
    {
        $d = $this->prepare();
        foreach (['http://cdn1.ozone.ru/label.pdf', 'https://cdn1.ozone.ru.evil.test/label.pdf', 'https://cdn1.ozone.ru@127.0.0.1/label.pdf', 'https://127.0.0.1/label.pdf', 'https://cdn1.ozone.ru:8443/label.pdf', 'https://cdn1.ozone.ru/label.pdf#secret'] as $url) {
            $this->fileUrl = $url; $this->download($d)->assertStatus(202); $this->travel(2)->seconds();
        }
        $this->assertSame(0, $this->files); $this->assertSame(1, $this->creates);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.test') || str_contains($r->url(), '127.0.0.1'));
    }
    public function test_cdn_redirects_wrong_types_and_bad_signatures_do_not_return_a_file_or_recreate(): void
    {
        $d = $this->prepare();
        foreach (['redirect', 'type', 'signature'] as $mode) {
            $this->fileStatus = $mode === 'redirect' ? 302 : 200; $this->mime = $mode === 'type' ? 'application/json' : 'application/pdf'; $this->bytes = $mode === 'signature' ? '<html>private-buyer</html>' : "%PDF-1.7\nTest\n%%EOF";
            $this->download($d)->assertStatus(503)->assertDontSee('private-buyer'); $this->travel(2)->seconds();
        }
        $this->assertSame(1, $this->creates); Http::assertNotSent(fn ($r) => str_contains($r->url(), 'untrusted.test'));
    }
    public function test_revoked_access_during_last_posting_check_stops_creation(): void
    {
        $d = $this->prepare(); $this->duringPosting = function () { if (ManagerFbsLabelJob::where('status', 'creating')->exists()) $this->permissions = ['auto_supply.view', 'integrations.view']; };
        $this->download($d)->assertForbidden(); $this->assertSame(0, $this->creates); $this->assertNull(ManagerFbsLabelJob::firstOrFail()->native_started_at);
    }
    public function test_key_rotation_during_poll_stops_file_export_and_keeps_known_task(): void
    {
        $d = $this->prepare(); $this->duringPoll = function () { $this->key = 'rotated-key'; };
        $this->download($d)->assertGone(); $this->assertSame(0, $this->files); $this->assertSame('70001', ManagerFbsLabelJob::firstOrFail()->task_id);
    }
    public function test_reentrant_downloads_during_creation_do_not_dispatch_more_tasks(): void
    {
        $d = $this->prepare(); $this->duringCreate = function () use ($d) { for ($i = 0; $i < 6; $i++) $this->download($d)->assertStatus(202); };
        $this->download($d)->assertOk(); $this->assertSame(1, $this->creates); $this->assertSame(1, $this->files);
    }
    public function test_expired_native_creation_claim_never_sends_and_history_survives(): void
    {
        $d = $this->prepare(); $this->duringPosting = function () { if (ManagerFbsLabelJob::where('status', 'creating')->exists()) Carbon::setTestNow(now()->addMinutes(6)); };
        $this->download($d)->assertGone(); $this->assertSame(0, $this->creates);
        $migration = require database_path('migrations/2026_11_06_180000_create_manager_fbs_label_jobs.php');
        $this->expectExceptionMessage('history prevents downgrade'); $migration->down();
    }

    public function test_lost_worker_does_not_recreate_an_unacknowledged_native_task(): void
    {
        $d = $this->prepare(); ManagerFbsLabelJob::whereKey($d['id'])->update(['status' => 'creating', 'native_started_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3)]);
        $this->download($d)->assertStatus(409)->assertJsonPath('document_status', 'unknown'); $this->assertSame(0, $this->creates); $this->assertSame(0, $this->polls);
    }

    public function test_regular_label_is_selected_without_losing_the_other_known_task_receipt(): void
    {
        $this->tasks = [['task_id' => 70002, 'task_type' => 'small_label'], ['task_id' => 70001, 'task_type' => 'big_label']]; $d = $this->prepare();
        $this->download($d)->assertOk(); $row = ManagerFbsLabelJob::firstOrFail(); $this->assertSame('big_label', $row->task_type); $this->assertCount(2, $row->tasks);
    }

    public function test_oversized_pdf_is_not_exported_and_does_not_create_another_task(): void
    {
        $d = $this->prepare(); $this->bytes = '%PDF-'.str_repeat('x', \App\Services\Manager\ManagerFbsDocumentService::MAX_BYTES);
        $this->download($d)->assertStatus(503); $this->travel(2)->seconds(); $this->download($d)->assertStatus(503); $this->assertSame(1, $this->creates);
    }

    public function test_explicit_full_counts_support_an_omitted_optional_empty_error_list(): void
    {
        $this->omitEmptyUnprinted = true; $d = $this->prepare(); $this->download($d)->assertOk(); $this->assertSame(1, $this->files);
    }
}
