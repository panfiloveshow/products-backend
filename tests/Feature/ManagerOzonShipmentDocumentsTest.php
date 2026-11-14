<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagerOzonShipmentDocumentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ID = '9007199254740993';
    private const PREFIX = '/api/manager/fbs/ozon/';
    private const PDF = "%PDF-1.7\nTest only\n%%EOF";
    private array $permissions = ['auto_supply.view', 'auto_supply.export', 'integrations.view'];
    private int $user = 42;
    private string $key = 'vendor-secret';
    private array $carriage;
    private ?array $listRows = null;
    private string $status = 'ready';
    private bool $minimalStatus = false;
    private string $bytes = self::PDF;
    private string $mime = 'application/pdf';
    private int $fileStatus = 200;
    private $afterStatus = null;
    private $afterFile = null;
    private $afterStore = null;
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true]);
        Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($mock) => $mock->shouldReceive('getServiceToken')->andReturn('service-session'));
        $this->withToken('human-session')->withHeader('X-Sellico-Workspace', '8');
        Integration::factory()->ozon()->create(['id' => 62001, 'work_space_id' => 8]);
        $this->carriage = ['carriage_id' => (int) self::ID, 'delivery_method_id' => 230039077005, 'integration_type' => 'ozon', 'status' => 'formed',
            'departure_date' => '2026-11-06', 'available_actions' => ['get_shipping_list', 'get_waybill', 'set_arrival_passes'],
            'is_waybill_enabled' => true, 'act_type' => 'ozon_digital', 'is_partial' => false, 'partial_num' => 0, 'has_postings_for_next_carriage' => false,
            'private_customer' => 'buyer-private', 'api_key' => 'source-secret'];
        Http::fake(function ($r) {
            $url = $r->url();
            if ($url === 'https://crm.test/api/user') return Http::response(['id' => $this->user, 'is_service_account' => false]);
            if ($url === 'https://crm.test/api/workspaces/8/user-permissions') return Http::response(['user_id' => $this->user, 'permissions' => $this->permissions]);
            if ($url === 'https://crm.test/api/get-integrations/8') {
                $rows = [['id' => 62001, 'work_space_id' => 8, 'status' => 'active', 'type' => 'ozon', 'name' => 'Test store', 'api_key' => $this->key, 'client_id' => '555']];
                if ($this->afterStore) ($this->afterStore)();
                return Http::response($rows);
            }
            $this->assertTrue(str_starts_with($url, 'https://api-seller.ozon.ru/'));
            $this->assertTrue($r->hasHeader('Api-Key', [$this->key]));
            $this->assertTrue($r->hasHeader('Client-Id', ['555']));
            $this->assertFalse($r->hasHeader('Authorization'));
            $path = parse_url($url, PHP_URL_PATH);
            $this->calls[] = $path;
            if ($path === '/v2/posting/fbs/act/list') {
                $this->assertSame(['filter' => ['date_from' => '2026-11-01', 'date_to' => '2026-11-06', 'integration_type' => 'ozon'], 'limit' => 50], $r->data());
                return Http::response(['result' => $this->listRows ?? [$this->row()]]);
            }
            if ($path === '/v1/carriage/get') {
                $this->assertSame(['carriage_id' => (int) self::ID], $r->data());
                return Http::response($this->carriage);
            }
            if ($path === '/v2/posting/fbs/act/check-status') {
                $this->assertSame(['id' => (int) self::ID], $r->data());
                $result = ['status' => $this->status, 'added_to_act' => ['private-posting'], 'removed_from_act' => ['private-other-posting']];
                if (! $this->minimalStatus) foreach (['act_type', 'is_partial', 'partial_num', 'has_postings_for_next_carriage'] as $key) $result[$key] = $this->carriage[$key];
                if ($this->afterStatus) ($this->afterStatus)();
                return Http::response(['result' => $result]);
            }
            if ($path === '/v2/posting/fbs/act/get-pdf') {
                $this->assertSame(['id' => (int) self::ID], $r->data());
                if ($this->afterFile) ($this->afterFile)();
                return Http::response($this->bytes, $this->fileStatus, ['Content-Type' => $this->mime, 'Location' => 'https://untrusted.test/private-file']);
            }
            $this->fail('Unexpected native mutation or read');
        });
    }

    private function row(): array
    {
        return ['id' => (int) self::ID, ...array_diff_key($this->carriage, ['carriage_id' => true])];
    }

    private function prepare(): array
    {
        return $this->getJson(self::PREFIX.'shipment-document/context?integration_id=62001&carriage_id='.self::ID)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    private function download(array $doc)
    {
        return $this->postJson(self::PREFIX.'shipment-document/download', ['download_ticket' => $doc['download_ticket']]);
    }

    private function exportContract(string $name, array $data): void
    {
        if (! $dir = getenv('SELYA_OZON_SHIPMENT_CONTRACT_DIR')) return;
        if (! is_dir($dir)) mkdir($dir, 0700, true);
        chmod($dir, 0700);
        file_put_contents($dir.'/'.$name.'.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($dir.'/'.$name.'.json', 0600);
    }

    public function test_bounded_list_is_a_projection_without_a_fabricated_total_or_cursor(): void
    {
        $this->permissions = ['auto_supply.view', 'integrations.view'];
        $data = $this->getJson(self::PREFIX.'shipments?integration_id=62001&date_from=2026-11-01&date_to=2026-11-06')->assertOk()->json();
        $this->assertSame(self::ID, $data['items'][0]['carriage_id']);
        $this->assertSame('not_proven', $data['completeness']);
        $this->assertFalse($data['limit_reached']);
        $this->assertSame('carriage_created_at', $data['date_basis']);
        $this->assertArrayNotHasKey('total', $data);
        $this->assertArrayNotHasKey('cursor', $data);
        $this->assertStringNotContainsString('private', json_encode($data));
        $this->assertStringNotContainsString('secret', json_encode($data));
        $this->exportContract('list', $data);
    }

    public function test_empty_and_limit_sized_lists_do_not_prove_completeness(): void
    {
        foreach ([0, 50] as $count) {
            $this->listRows = [];
            for ($i = 0; $i < $count; $i++) $this->listRows[] = ['id' => 1000 + $i, ...array_diff_key($this->row(), ['id' => true])];
            $this->getJson(self::PREFIX.'shipments?integration_id=62001&date_from=2026-11-01&date_to=2026-11-06')->assertOk()
                ->assertJsonPath('count_returned', $count)->assertJsonPath('completeness', 'not_proven')->assertJsonPath('limit_reached', $count === 50);
        }
    }

    public function test_period_unknown_fields_and_wrong_market_are_rejected_before_native_reads(): void
    {
        foreach (['date_from=2026-09-01&date_to=2026-11-06', 'date_from=2026-11-06&date_to=2026-11-01', 'date_from=2026-02-30&date_to=2026-03-01', 'date_from=2026-11-01&date_to=2026-11-06&cursor=made-up'] as $query) {
            $this->getJson(self::PREFIX.'shipments?integration_id=62001&'.$query)->assertUnprocessable();
        }
        Integration::whereKey(62001)->update(['marketplace' => 'wildberries']);
        $this->getJson(self::PREFIX.'shipment-document/context?integration_id=62001&carriage_id='.self::ID)->assertUnprocessable();
        $this->assertSame([], $this->calls);
    }

    public function test_only_a_user_download_receives_pdf_and_preparation_does_not_generate_or_confirm_a_carriage(): void
    {
        $doc = $this->prepare();
        $this->exportContract('context', $doc);
        $this->assertSame('fbs_shipment_documents', $doc['document_type']);
        $this->assertSame(['shipping_list', 'waybill'], $doc['available_documents']);
        $this->assertSame(['/v1/carriage/get'], $this->calls);
        $response = $this->download($doc)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', 'sandbox');
        $this->assertSame(self::PDF, $response->getContent());
        $this->assertStringContainsString('attachment; filename="fbs-shipment-', $response->headers->get('Content-Disposition'));
        foreach ($this->calls as $path) $this->assertContains($path, ['/v1/carriage/get', '/v2/posting/fbs/act/check-status', '/v2/posting/fbs/act/get-pdf']);
        $this->assertStringNotContainsString('buyer-private', json_encode($doc));
        $this->assertStringNotContainsString('source-secret', json_encode($doc));
    }

    public function test_act_name_is_only_exposed_when_ozon_explicitly_allows_an_acceptance_act(): void
    {
        $this->carriage['available_actions'] = ['get_act_of_acceptance'];
        $this->carriage['is_waybill_enabled'] = false;
        $doc = $this->prepare();
        $this->assertSame(['act_of_acceptance'], $doc['available_documents']);
        $this->download($doc)->assertOk();
    }

    public function test_pending_and_failed_readiness_do_not_fetch_a_file_or_start_another_job(): void
    {
        $doc = $this->prepare();
        $this->status = 'in_process';
        $response = $this->download($doc)->assertStatus(202)->assertHeader('Retry-After', '2')->assertJsonPath('document_status', 'pending')->assertJsonPath('can_retry', true);
        $this->exportContract('pending', $response->json());
        $this->download($doc)->assertStatus(202);
        $this->status = 'error';
        $response = $this->download($doc)->assertConflict()->assertJsonPath('document_status', 'failed')->assertJsonPath('can_retry', false);
        $this->exportContract('failed', $response->json());
        $this->assertNotContains('/v2/posting/fbs/act/get-pdf', $this->calls);
        $this->status = 'ready';
        $this->download($doc)->assertOk();
    }

    public function test_partial_carriages_are_explicit_and_changed_partial_documents_require_new_preparation(): void
    {
        $this->carriage['is_partial'] = true;
        $this->carriage['partial_num'] = 2;
        $this->carriage['has_postings_for_next_carriage'] = true;
        $doc = $this->prepare();
        $this->assertTrue($doc['is_partial']);
        $this->assertSame(2, $doc['partial_num']);
        $this->assertTrue($doc['has_postings_for_next_carriage']);
        $this->download($doc)->assertOk();
        $this->carriage['partial_num'] = 3;
        $this->download($doc)->assertGone();
    }

    public function test_pending_or_error_receipts_may_omit_finished_document_metadata_but_ready_may_not(): void
    {
        $doc = $this->prepare();
        $this->minimalStatus = true;
        $this->status = 'in_process'; $this->download($doc)->assertStatus(202)->assertJsonPath('document_status', 'pending');
        $this->status = 'error'; $this->download($doc)->assertConflict()->assertJsonPath('document_status', 'failed');
        $this->status = 'ready'; $this->download($doc)->assertGone();
        $this->assertNotContains('/v2/posting/fbs/act/get-pdf', $this->calls);
        $this->minimalStatus = false; $this->download($doc)->assertOk();
    }

    public function test_status_may_advance_but_changed_date_method_or_document_types_invalidate_the_ticket(): void
    {
        $doc = $this->prepare();
        $this->carriage['status'] = 'closed';
        $this->download($doc)->assertOk();
        foreach (['departure_date' => '2026-11-07', 'delivery_method_id' => 230039077006, 'available_actions' => ['get_shipping_list']] as $key => $value) {
            $original = $this->carriage[$key]; $this->carriage[$key] = $value;
            $this->download($doc)->assertGone();
            $this->carriage[$key] = $original;
        }
    }

    public function test_foreign_owner_workspace_store_rotation_and_tampered_ticket_cannot_fetch_pdf(): void
    {
        $doc = $this->prepare();
        $this->user = 43; $this->download($doc)->assertGone(); $this->user = 42;
        $this->key = 'rotated'; $this->download($doc)->assertGone(); $this->key = 'vendor-secret';
        $this->download(['download_ticket' => $doc['download_ticket'].'x'])->assertGone();
        Integration::whereKey(62001)->update(['work_space_id' => 9]);
        $this->download($doc)->assertNotFound();
        $this->assertNotContains('/v2/posting/fbs/act/get-pdf', $this->calls);
    }

    public function test_expiry_and_revoked_export_are_checked_before_file_fetch(): void
    {
        $doc = $this->prepare();
        $this->permissions = ['auto_supply.view', 'integrations.view'];
        $this->download($doc)->assertForbidden();
        $this->permissions[] = 'auto_supply.export';
        $this->travel(5)->minutes();
        $this->download($doc)->assertGone();
        $this->assertNotContains('/v2/posting/fbs/act/get-pdf', $this->calls);
    }

    public function test_last_native_read_cannot_hide_a_key_rotation_or_personal_right_revocation(): void
    {
        foreach (['key', 'permission', 'identity', 'expiry'] as $case) {
            $this->key = 'vendor-secret'; $this->user = 42; $this->permissions = ['auto_supply.view', 'auto_supply.export', 'integrations.view'];
            $doc = $this->prepare();
            $this->afterStatus = function () use ($case) {
                if ($case === 'key') $this->key = 'rotated';
                if ($case === 'permission') $this->permissions = ['auto_supply.view', 'integrations.view'];
                if ($case === 'identity') $this->user = 43;
                if ($case === 'expiry') $this->travel(5)->minutes();
            };
            $this->download($doc)->assertStatus(in_array($case, ['key', 'expiry'], true) ? 410 : 403);
            $this->assertNotContains('/v2/posting/fbs/act/get-pdf', $this->calls);
            $this->afterStatus = null;
        }
    }

    public function test_revocation_during_pdf_request_prevents_releasing_the_download(): void
    {
        $doc = $this->prepare();
        $this->afterFile = function () { $this->permissions = ['auto_supply.view', 'integrations.view']; };
        $this->download($doc)->assertForbidden()->assertDontSee('%PDF');
    }

    public function test_expiry_during_store_verification_cannot_authorize_a_late_document_request(): void
    {
        $doc = $this->prepare();
        $this->afterStore = function () { $this->travel(5)->minutes(); $this->afterStore = null; };
        $this->download($doc)->assertGone();
        $this->assertSame(['/v1/carriage/get'], $this->calls);
    }

    public function test_invalid_file_signature_type_size_and_redirect_fail_without_exposing_source_data(): void
    {
        $doc = $this->prepare();
        foreach ([[200, 'application/pdf', 'buyer-private'], [200, 'application/json', '{"api_key":"source-secret"}'],
            [302, 'application/pdf', self::PDF], [200, 'application/pdf', '%PDF-'.str_repeat('x', 8388608)]] as [$status, $mime, $bytes]) {
            $this->fileStatus = $status; $this->mime = $mime; $this->bytes = $bytes;
            $this->download($doc)->assertStatus(503)->assertDontSee('buyer-private')->assertDontSee('source-secret');
        }
    }

    public function test_malformed_foreign_or_cancelled_carriage_and_duplicate_list_are_rejected(): void
    {
        foreach (['carriage_id' => 100, 'integration_type' => 'non_integrated', 'is_partial' => 'false', 'has_postings_for_next_carriage' => null,
            'available_actions' => ['set_arrival_passes'], 'is_waybill_enabled' => false, 'departure_date' => '2026-02-30', 'status' => 'cancelled'] as $field => $value) {
            $original = $this->carriage[$field]; $this->carriage[$field] = $value;
            $this->getJson(self::PREFIX.'shipment-document/context?integration_id=62001&carriage_id='.self::ID)->assertStatus(in_array($field, ['available_actions', 'status'], true) ? 422 : 503);
            $this->carriage[$field] = $original;
        }
        $this->listRows = [$this->row(), $this->row()];
        $this->getJson(self::PREFIX.'shipments?integration_id=62001&date_from=2026-11-01&date_to=2026-11-06')->assertStatus(503);
        $this->postJson(self::PREFIX.'shipment-document/download', ['download_ticket' => 'x', 'url' => 'https://untrusted.test'])->assertUnprocessable();
    }
}
