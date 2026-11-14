<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\ManagerFbsOperation;
use App\Services\Manager\ManagerFbsOperationService;
use App\Services\Manager\ManagerIdentity;
use App\Services\SellicoApiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagerFbsCancellationTest extends TestCase
{
    use LazilyRefreshDatabase;
    private string $market = 'ozon';
    private string $state = 'awaiting_packaging';
    private bool $cancellable = true;
    private bool $missingFlag = false;
    private bool $personal = true;
    private array $permissions = ['auto_supply.view', 'integrations.view', 'auto_supply.create'];
    private string $key = 'test-vendor-key';
    private int $quantity = 2;
    private int $reason = 401;
    private string $reasonTitle = 'Товар отсутствует';
    private int $writes = 0;
    private string $mode = 'ok';
    private int $reads = 0;
    private $underHold = null;
    private $duringSend = null;
    private ?array $specificOverride = null;
    private ?array $catalogOverride = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::now('UTC')->startOfSecond());
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.manager.fbs_writes_enabled' => true, 'services.manager.fbs_cancellations_enabled' => true]);
        Http::preventStrayRequests(); Cache::flush();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('test-service-session'));
        $this->withToken('human-session')->withHeader('X-Sellico-Workspace', '8')->withHeader('X-User-Id', 'spoofed');
        Http::fake(function ($r) {
            $url = $r->url();
            if ($url === 'https://crm.test/api/user') return Http::response(['id' => 42, 'is_service_account' => ! $this->personal]);
            if (str_contains($url, '/workspaces/9/user-permissions')) return Http::response([], 403);
            if (str_contains($url, '/workspaces/8/user-permissions')) return Http::response(['user_id' => 42, 'workspace_id' => 8, 'permissions' => $this->permissions]);
            if ($url === 'https://crm.test/api/get-integrations/8') return Http::response([['id' => 62005, 'work_space_id' => 8, 'status' => 'active', 'type' => $this->market, 'name' => 'Test store', 'api_key' => $this->key, 'client_id' => '555']]);
            if (str_contains($url, '/api/v3/orders/status')) {
                $this->onRead();
                return Http::response(['orders' => [['id' => 11, 'supplierStatus' => $this->state, 'wbStatus' => $this->state === 'cancel' ? 'canceled' : 'waiting', ...($this->missingFlag ? [] : ['isCancellable' => $this->cancellable && $this->state !== 'cancel'])]]]);
            }
            if (str_contains($url, '/api/v3/orders?')) return Http::response(['next' => 11, 'orders' => [['id' => 11, 'nmId' => 10001, 'article' => 'WB-SKU', 'skus' => ['012345'], 'createdAt' => now()->subHour()->utc()->toRfc3339String(), 'warehouseId' => 10, 'deliveryType' => 'fbs', 'crossBorderType' => 0, 'cargoType' => 1, 'options' => ['isB2B' => false], 'requiredMeta' => [], 'customer' => ['phone' => 'private-phone']]]]);
            if ($url === 'https://api-seller.ozon.ru/v3/posting/fbs/get') {
                $this->onRead();
                return Http::response(['result' => ['posting_number' => '100-1-1', 'order_id' => 701, 'status' => $this->state, 'available_actions' => $this->cancellable && $this->state !== 'cancelled' ? ['ship', 'cancel'] : [],
                    'products' => [['sku' => 40001, 'quantity' => $this->quantity, 'offer_id' => 'O-SKU', 'name' => 'Test product']],
                    'requirements' => (object) [], 'cancellation' => ['cancel_reason_id' => $this->state === 'cancelled' ? $this->reason : 0], 'customer' => ['phone' => 'private-phone']]]);
            }
            if ($url === 'https://api-seller.ozon.ru/v1/posting/fbs/cancel-reason') {
                $this->assertSame(['related_posting_numbers' => ['100-1-1']], $r->data());
                return Http::response($this->specificOverride ?? ['Result' => [['posting_number' => '100-1-1', 'reasons' => [['id' => 401, 'title' => $this->reasonTitle, 'type_id' => 'seller'], ['id' => 403, 'title' => 'Unavailable', 'type_id' => 'seller']]]]]);
            }
            if ($url === 'https://api-seller.ozon.ru/v2/posting/fbs/cancel-reason/list') {
                $this->assertSame('', $r->body());
                return Http::response($this->catalogOverride ?? ['result' => [['id' => 401, 'title' => $this->reasonTitle, 'type_id' => 'seller', 'is_available_for_cancellation' => true], ['id' => 402, 'title' => 'Not applicable', 'type_id' => 'seller', 'is_available_for_cancellation' => true], ['id' => 403, 'title' => 'Unavailable', 'type_id' => 'seller', 'is_available_for_cancellation' => false]]]);
            }
            if ($url === 'https://api-seller.ozon.ru/v2/posting/fbs/cancel' || $url === 'https://marketplace-api.wildberries.ru/api/v3/orders/11/cancel') {
                $this->writes++;
                if ($this->market === 'ozon') {
                    $this->assertSame(['posting_number' => '100-1-1', 'cancel_reason_id' => 401, 'cancel_reason_message' => 'Товара нет на складе.'], $r->data());
                } else {
                    $this->assertSame('PATCH', $r->method()); $this->assertSame('', $r->body());
                }
                $row = ManagerFbsOperation::where('operation_type', 'fbs_cancel')->where('status', 'executing')->firstOrFail();
                $this->assertNotNull($row->resource_key); $this->assertNotNull($row->native_started_at);
                if ($this->duringSend) ($this->duringSend)($row);
                if ($this->mode === 'reject') return Http::response([], 409);
                if (in_array($this->mode, ['redirect', 'throttle'], true)) return Http::response([], $this->mode === 'redirect' ? 302 : 429, ['Location' => 'https://untrusted.test/cancel']);
                if ($this->mode !== 'pending' && $this->mode !== 'unknown') $this->state = $this->market === 'ozon' ? 'cancelled' : 'cancel';
                if ($this->mode === 'lost' || $this->mode === 'unknown') throw new ConnectionException('test private network payload');
                return $this->market === 'ozon' ? Http::response(['result' => true]) : Http::response('', 204);
            }
            throw new \RuntimeException('Unexpected test request');
        });
        Integration::factory()->ozon()->create(['id' => 62005, 'work_space_id' => 8, 'name' => 'Local test']);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    private function onRead(): void
    {
        $this->reads++;
        $row = ManagerFbsOperation::where('operation_type', 'fbs_cancel')->where('status', 'executing')->whereNotNull('resource_key')->first();
        if ($row && $this->underHold) ($this->underHold)($row);
    }
    private function context(): array
    {
        return $this->getJson('/api/manager/fbs/cancellation-context?integration_id=62005&external_id='.($this->market === 'ozon' ? '100-1-1' : '11'))->assertOk()->json();
    }
    private function prepare(?array $context = null): array
    {
        $context ??= $this->context();
        return $this->postJson('/api/manager/fbs/prepare-cancellation', ['integration_id' => '62005', 'external_id' => $context['external_id'],
            'context_fingerprint' => $context['context_fingerprint'], 'reason_id' => $this->market === 'ozon' ? '401' : null,
            'comment' => 'Товара нет на складе.', 'lookup_window' => $context['lookup_window']])->assertOk()->json();
    }
    private function approve(array $op, ?array $checks = null): \Illuminate\Testing\TestResponse
    {
        $checks ??= ['cancel_target_checked' => true, 'cancel_impact_checked' => true, 'not_handed_over_checked' => true];
        return $this->postJson('/api/manager/fbs-operations/'.$op['id'].'/approve', ['fingerprint' => $op['fingerprint'], 'confirmations' => $checks]);
    }

    public function test_both_marketplaces_prepare_without_writing_and_confirm_exact_cancellation(): void
    {
        foreach (['ozon', 'wildberries'] as $market) {
            $this->market = $market; $this->state = $market === 'ozon' ? 'awaiting_packaging' : 'complete';
            Integration::whereKey(62005)->update(['marketplace' => $market]);
            $context = $this->context(); $this->assertTrue($context['is_cancellable']);
            if ($market === 'ozon') $this->assertSame(['401'], array_column($context['reasons'], 'id'));
            else $this->assertSame('orders_status_endpoint', $context['before']['state_basis']);
            $this->assertStringNotContainsString('private-phone', json_encode($context));
            $before = $this->writes; $op = $this->prepare($context); $this->assertSame($before, $this->writes);
            $this->assertCount(3, $op['required_confirmations']); $this->assertFalse($op['cancellation']['financial_effects_verified']);
            $this->approve($op, ['cancel_target_checked' => true])->assertStatus(422);
            $final = $this->approve($op)->assertOk()->json();
            $this->assertSame('succeeded', $final['status']); $this->assertTrue($final['result']['cancellation_verified']);
            $this->assertNull($final['result']['refund_status']); $this->assertNull(ManagerFbsOperation::findOrFail($op['id'])->resource_key);
            $this->approve($op)->assertOk(); $this->assertSame($before + 1, $this->writes);
            $dir = getenv('SELYA_FBS_CANCEL_TEST_CONTRACT_DIR');
            if ($dir) { if (! is_dir($dir)) mkdir($dir, 0700, true); foreach (['context' => $context, 'prepared' => $op, 'finished' => $final] as $name => $value) file_put_contents($dir.'/'.$market.'-'.$name.'.json', json_encode($value, JSON_THROW_ON_ERROR)); }
        }
    }

    public function test_platform_flag_and_personal_profile_cannot_be_guessed(): void
    {
        $this->market = 'wildberries'; $this->state = 'new'; Integration::whereKey(62005)->update(['marketplace' => 'wildberries']);
        $this->missingFlag = true; $this->getJson('/api/manager/fbs/cancellation-context?integration_id=62005&external_id=11')->assertStatus(503);
        $this->missingFlag = false; $this->cancellable = false; $context = $this->context(); $this->assertFalse($context['is_cancellable']);
        $this->postJson('/api/manager/fbs/prepare-cancellation', ['integration_id' => 62005, 'external_id' => '11', 'context_fingerprint' => $context['context_fingerprint'], 'reason_id' => null, 'comment' => 'Actual user reason', 'lookup_window' => $context['lookup_window']])->assertStatus(422);
        $this->personal = false; $this->getJson('/api/manager/fbs/cancellation-context?integration_id=62005&external_id=11')->assertStatus(403);
        $this->assertSame(0, $this->writes);
    }

    public function test_state_reason_and_composition_changes_require_new_consent(): void
    {
        $op = $this->prepare(); $this->reasonTitle = 'Changed native title';
        $this->approve($op)->assertOk()->assertJsonPath('status', 'cancelled'); $this->assertSame(0, $this->writes);
        $this->reasonTitle = 'Товар отсутствует'; $op = $this->prepare();
        $this->underHold = function ($row) { $this->assertNull($row->native_started_at); $this->quantity = 3; };
        $this->approve($op)->assertOk()->assertJsonPath('status', 'failed'); $this->assertSame(0, $this->writes);
    }

    public function test_permissions_are_rechecked_under_the_durable_hold(): void
    {
        $op = $this->prepare();
        $this->underHold = function ($row) { $this->permissions = ['auto_supply.view', 'integrations.view']; };
        $this->approve($op)->assertStatus(403);
        // Revocation after the final identity check but during the vendor read is
        // caught by the immediate final session check before the dispatch marker.
        $this->assertSame(0, $this->writes);
    }

    public function test_unknown_recovery_reads_but_does_not_repeat_or_claim_authorship(): void
    {
        foreach (['lost', 'unknown', 'pending', 'reject'] as $mode) {
            $this->mode = $mode; $this->state = 'awaiting_packaging'; $op = $this->prepare(); $before = $this->writes;
            $result = $this->approve($op)->assertOk()->json();
            $this->assertSame($mode === 'reject' ? 'failed' : ($mode === 'pending' ? 'verifying' : 'unknown'), $result['status']);
            if ($mode !== 'reject') {
                $this->state = 'cancelled'; $result = $this->postJson('/api/manager/fbs-operations/'.$op['id'].'/reconcile', [])->assertOk()->json();
                $this->assertSame($mode === 'pending' ? 'succeeded' : 'unknown', $result['status']);
                if ($mode !== 'pending') {
                    $this->assertNotNull(ManagerFbsOperation::findOrFail($op['id'])->resource_key);
                    $this->assertTrue(\Illuminate\Support\Facades\DB::table('manager_fbs_resource_holds')->where('operation_id', $op['id'])->exists());
                    // Isolate the next response fixture, not a production unlock.
                    ManagerFbsOperation::whereKey($op['id'])->update(['resource_key' => null]);
                    \Illuminate\Support\Facades\DB::table('manager_fbs_resource_holds')->where('operation_id', $op['id'])->delete();
                }
            }
            $this->approve($op)->assertOk(); $this->assertSame($before + 1, $this->writes);
        }
    }

    public function test_expiry_before_native_marker_never_sends_and_retains_an_audit(): void
    {
        $op = $this->prepare(); $this->underHold = fn ($row) => Carbon::setTestNow(now()->addMinutes(6));
        $this->approve($op)->assertOk()->assertJsonPath('status', 'expired');
        $row = ManagerFbsOperation::findOrFail($op['id']); $this->assertNull($row->native_started_at); $this->assertNull($row->resource_key); $this->assertSame(0, $this->writes);
    }

    public function test_repeated_confirmation_during_native_request_does_not_dispatch_again(): void
    {
        $op = $this->prepare(); $this->duringSend = function ($row) use ($op) {
            $identity = new ManagerIdentity(8, 42, $this->permissions, true);
            for ($i = 0; $i < 12; $i++) $this->assertSame('executing', app(ManagerFbsOperationService::class)->approve($identity, $op['id'], $op['fingerprint'], [], fn () => $identity)['status']);
        };
        $this->approve($op)->assertOk()->assertJsonPath('status', 'succeeded'); $this->assertSame(1, $this->writes);
    }

    public function test_cancellation_history_cannot_be_dropped_by_downgrade(): void
    {
        $this->prepare(); $migration = require database_path('migrations/2026_11_06_170000_add_manager_fbs_native_dispatch.php');
        $this->expectExceptionMessage('history prevents downgrade'); $migration->down();
    }

    public function test_changed_credentials_platform_permission_and_kill_switch_stop_before_dispatch(): void
    {
        foreach (['key', 'platform', 'stop', 'personal'] as $change) {
            $this->key = 'test-vendor-key'; $this->cancellable = true; $this->personal = true;
            config(['services.manager.fbs_cancellations_enabled' => true]); $this->underHold = null;
            $op = $this->prepare();
            $this->underHold = function ($row) use ($change) {
                $this->assertNull($row->native_started_at);
                match ($change) {
                    'key' => $this->key = 'rotated-test-key', 'platform' => $this->cancellable = false,
                    'stop' => config(['services.manager.fbs_cancellations_enabled' => false]), 'personal' => $this->personal = false,
                };
            };
            $response = $this->approve($op);
            if ($change === 'personal') $response->assertStatus(403); else $response->assertOk()->assertJsonPath('status', 'failed');
            $row = ManagerFbsOperation::findOrFail($op['id']); $this->assertNull($row->resource_key); $this->assertNull($row->native_started_at);
        }
        $this->assertSame(0, $this->writes);
    }

    public function test_only_reasons_bound_to_this_posting_and_explicitly_available_are_accepted(): void
    {
        foreach ([['result' => []], ['Result' => [['posting_number' => 'foreign-posting', 'reasons' => []]]],
            ['Result' => [['posting_number' => '100-1-1', 'reasons' => [['id' => 401, 'title' => 'Reason', 'type_id' => 'seller'], ['id' => 401, 'title' => 'Duplicate', 'type_id' => 'seller']]]]]] as $override) {
            $this->specificOverride = $override; $this->getJson('/api/manager/fbs/cancellation-context?integration_id=62005&external_id=100-1-1')->assertStatus(503);
        }
        $this->specificOverride = null; $this->catalogOverride = ['result' => [['id' => 401, 'title' => 'Reason', 'type_id' => 'seller']]];
        $this->getJson('/api/manager/fbs/cancellation-context?integration_id=62005&external_id=100-1-1')->assertStatus(503);
        $this->catalogOverride['result'][0]['is_available_for_cancellation'] = false;
        $this->assertFalse($this->context()['is_cancellable']); $this->assertSame(0, $this->writes);
    }

    public function test_native_redirect_and_throttle_never_retry_or_follow(): void
    {
        foreach (['redirect', 'throttle'] as $mode) {
            $this->mode = $mode; $op = $this->prepare(); $before = $this->writes;
            $this->approve($op)->assertOk()->assertJsonPath('status', 'unknown');
            $this->approve($op)->assertOk(); $this->assertSame($before + 1, $this->writes);
            $this->assertNotNull(ManagerFbsOperation::findOrFail($op['id'])->resource_key);
            // Isolate the next fake response case without invoking a production unlock.
            ManagerFbsOperation::whereKey($op['id'])->update(['resource_key' => null]);
            $this->assertTrue(\Illuminate\Support\Facades\DB::table('manager_fbs_resource_holds')->where('operation_id', $op['id'])->exists());
            \Illuminate\Support\Facades\DB::table('manager_fbs_resource_holds')->where('operation_id', $op['id'])->delete();
        }
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'untrusted.test'));
    }

    public function test_disabled_cancellation_is_read_only_and_rejects_new_proposals(): void
    {
        config(['services.manager.fbs_cancellations_enabled' => false]); $context = $this->context();
        $this->assertTrue($context['is_cancellable']); $this->assertFalse($context['approval_available']);
        $this->postJson('/api/manager/fbs/prepare-cancellation', ['integration_id' => '62005', 'external_id' => '100-1-1', 'context_fingerprint' => $context['context_fingerprint'],
            'reason_id' => '401', 'comment' => 'Товара нет на складе.', 'lookup_window' => null])->assertStatus(503);
        $this->assertSame(0, ManagerFbsOperation::count()); $this->assertSame(0, $this->writes);
    }

    public function test_persisted_consent_tampering_and_foreign_identity_are_rejected(): void
    {
        $op = $this->prepare(); $row = ManagerFbsOperation::findOrFail($op['id']); $plan = $row->plan; $plan['cancellation_details']['comment'] = 'Changed after consent'; $row->update(['plan' => $plan]);
        $this->approve($op)->assertStatus(409); $this->assertSame(0, $this->writes);
        $this->withHeader('X-Sellico-Workspace', '9')->getJson('/api/manager/fbs-operations/'.$op['id'])->assertStatus(403);
    }

    public function test_json_storage_object_key_order_does_not_change_consent(): void
    {
        $op = $this->prepare(); $row = ManagerFbsOperation::findOrFail($op['id']);
        $reverse = function ($value) use (&$reverse) {
            if (! is_array($value)) return $value;
            $value = array_map($reverse, $value);
            return array_is_list($value) ? $value : array_reverse($value, true);
        };
        // JSON columns may reorder object keys, and the array cast normalizes {}.
        $row->update(['plan' => $reverse($row->plan)]);
        $this->approve($op)->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertSame(1, $this->writes);
    }
}
