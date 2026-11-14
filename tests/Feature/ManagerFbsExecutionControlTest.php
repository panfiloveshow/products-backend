<?php

namespace Tests\Feature;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Wildberries\Api\WildberriesClient;
use App\Models\Integration;
use App\Models\ManagerFbsOperation;
use App\Models\Posting;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerFbsExecutionPolicy;
use App\Services\Manager\ManagerFbsExecutionStopped;
use App\Services\Manager\ManagerFbsGateway;
use App\Services\Manager\ManagerStore;
use App\Services\PostingService;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ManagerFbsExecutionControlTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function markets(): array
    {
        return [['wildberries'], ['ozon']];
    }

    private function store(string $market): ManagerStore
    {
        $token = 'test.'.rtrim(strtr(base64_encode(json_encode(['sid' => 'seller-42'])), '+/', '-_'), '=').'.signature';

        return new ManagerStore(3, 28, $market, 'Test store', $market === 'ozon' ? 'test-key' : $token, $market === 'ozon' ? '555' : null, str_repeat('a', 64));
    }

    #[DataProvider('markets')]
    public function test_stop_blocks_native_dispatch_and_opening_does_not_revive_old_snapshots(string $market): void
    {
        config(['services.manager.fbs_execution_controls_enabled' => true]);
        $policy = app(ManagerFbsExecutionPolicy::class);
        $store = $this->store($market);
        $old = $policy->snapshot($store);
        $calls = 0;
        $policy->dispatch($store, $old, function () use (&$calls) {
            $calls++;
        });
        $key = $policy->accountKey($store);
        DB::table('manager_fbs_execution_controls')->where('account_key', $key)->update(['mode' => 'stopped', 'version' => 1]);
        foreach ([$old, null] as $snapshot) {
            try {
                $policy->dispatch($store, $snapshot, function () use (&$calls) {
                    $calls++;
                });
                $this->fail('Stopped dispatch reached native callback');
            } catch (ManagerFbsExecutionStopped $e) {
                $this->assertSame(409, $e->httpStatus);
            }
        }
        DB::table('manager_fbs_execution_controls')->where('account_key', $key)->update(['mode' => 'manual_only', 'version' => 2]);
        foreach ([$old, null] as $snapshot) {
            try {
                $policy->dispatch($store, $snapshot, function () use (&$calls) {
                    $calls++;
                });
                $this->fail('Old dispatch revived');
            } catch (ManagerFbsExecutionStopped $e) {
                $this->assertSame(409, $e->httpStatus);
            }
        }
        $fresh = $policy->snapshot($store);
        $policy->dispatch($store, $fresh, function () use (&$calls) {
            $calls++;
        });
        $this->assertSame(2, $calls);
    }

    private string $market = 'ozon';

    private bool $storeActive = true;

    private bool $personal = true;

    private int $user = 7;

    private array $permissions = ['auto_supply.view', 'integrations.view', 'auto_supply.create'];

    private $duringRead = null;

    private int $nativeWrites = 0;

    private bool $singleOperations = false;

    private string $vendorState = 'awaiting_packaging';

    private array $assigned = [];

    private function httpFixture(string $market): void
    {
        $this->market = $market;
        config(['services.manager.fbs_execution_controls_enabled' => true, 'services.manager.fbs_writes_enabled' => true,
            'services.sellico.base_url' => 'https://crm.test/api']);
        Integration::factory()->{$market}()->create(['id' => 28, 'work_space_id' => 3]);
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('service-test-session'));
        $this->withToken('human-test-session')->withHeader('X-Sellico-Workspace', '3');
        Http::preventStrayRequests();
        Http::fake(function ($r) {
            $url = $r->url();
            if ($url === 'https://crm.test/api/user') {
                return Http::response(['id' => $this->user, 'is_service_account' => ! $this->personal]);
            }
            if (preg_match('~/workspaces/([0-9]+)/user-permissions$~', $url, $m)) {
                return Http::response(['user_id' => $this->user, 'workspace_id' => (int) $m[1], 'permissions' => $this->permissions]);
            }
            if (str_contains($url, '/get-integrations/')) {
                return Http::response([['id' => 28, 'work_space_id' => 3,
                    'type' => $this->market, 'status' => $this->storeActive ? 'active' : 'inactive', 'name' => 'Test store', 'api_key' => $this->store($this->market)->apiKey, 'client_id' => '555']]);
            }
            if (str_ends_with($url, '/api/v3/orders/new') || str_ends_with($url, '/v4/posting/fbs/list')) {
                if ($this->duringRead) {
                    ($this->duringRead)();
                }

                return Http::response($this->market === 'ozon' ? ['postings' => [], 'has_next' => false, 'cursor' => null] : ['orders' => $this->singleOperations ? [['id' => 101, 'deliveryType' => 'fbs', 'cargoType' => 1, 'crossBorderType' => 0, 'options' => ['isB2B' => false], 'requiredMeta' => []]] : []]);
            }
            if ($this->singleOperations) {
                if (str_ends_with($url, '/v3/posting/fbs/get')) {
                    $requirements = array_fill_keys(['products_requiring_gtd', 'products_requiring_jw_uin', 'products_requiring_country', 'products_requiring_rnpt', 'products_requiring_mandatory_mark', 'products_requiring_change_country', 'products_requiring_imei', 'products_requiring_weight'], []);

                    return Http::response(['result' => ['posting_number' => '100-1-1', 'order_id' => 701, 'status' => $this->vendorState, 'is_express' => false, 'is_multibox' => false, 'integration_type_flow' => 'ozon',
                        'available_actions' => $this->vendorState === 'awaiting_packaging' ? ['ship'] : ['label_download'], 'requirements' => $requirements, 'require_blr_traceable_attrs' => false,
                        'products' => [['sku' => 40001, 'offer_id' => 'A1', 'name' => 'Test product', 'quantity' => 2, 'mandatory_mark' => [], 'jw_uin' => [],
                            'has_imei' => false, 'is_weight_needed' => false, 'is_blr_traceable' => false, 'is_marketplace_buyout' => false]]]]);
                }
                if (str_ends_with($url, '/v4/posting/fbs/ship')) {
                    $this->nativeWrites++;
                    $this->vendorState = 'awaiting_deliver';

                    return Http::response(['result' => ['100-1-1']]);
                }
                if (str_ends_with($url, '/api/v3/supplies/WB-1')) {
                    return Http::response(['id' => 'WB-1', 'done' => false, 'cargoType' => 1, 'crossBorderType' => 0, 'isB2b' => false]);
                }
                if (str_ends_with($url, '/api/marketplace/v3/supplies/WB-1/order-ids')) {
                    return Http::response(['orderIds' => $this->assigned]);
                }
                if (str_ends_with($url, '/api/marketplace/v3/supplies/WB-1/orders')) {
                    $this->nativeWrites++;
                    $this->assigned = [101];

                    return Http::response('', 204);
                }
                if (str_ends_with($url, '/api/v3/orders/status')) {
                    return Http::response(['orders' => [['id' => 101, 'supplierStatus' => $this->assigned ? 'confirm' : 'new']]]);
                }
            }
            throw new \RuntimeException('Unexpected isolated request '.$url);
        });
    }

    private function context(): array
    {
        return $this->getJson('/api/manager/fbs-execution-controls/context?integration_id=28')->assertOk()->json();
    }

    private function controlProposal(string $mode): array
    {
        $context = $this->context();

        return $this->postJson('/api/manager/fbs-execution-controls/prepare', ['integration_id' => 28, 'context_fingerprint' => $context['context_fingerprint'],
            'target_mode' => $mode, 'reason' => 'Проверяю упаковку и доступ сотрудников.'])->assertOk()->json();
    }

    private function checks(): array
    {
        return ['fbs_scope_checked' => true, 'fbs_effect_checked' => true, 'fbs_reopening_checked' => true];
    }

    private function approveControl(array $op, ?array $checks = null)
    {
        return $this->postJson('/api/manager/fbs-execution-controls/'.$op['id'].'/approve', ['fingerprint' => $op['fingerprint'], 'confirmations' => $checks ?? $this->checks()]);
    }

    #[DataProvider('markets')]
    public function test_personal_http_stop_receipt_and_separate_open_never_send_native_commands(string $market): void
    {
        $this->httpFixture($market);
        $context = $this->context();
        $this->assertSame('0', $context['control']['version']);
        $stop = $this->controlProposal('stopped');
        foreach ([[], ['fbs_scope_checked' => true], [...$this->checks(), 'extra' => true], [...$this->checks(), 'fbs_effect_checked' => 1]] as $checks) {
            $this->approveControl($stop, $checks)->assertStatus(422);
        }
        $saved = $this->approveControl($stop)->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.applied_control.mode', 'stopped')
            ->assertJsonPath('result.applied_control.version', '1')->assertJsonPath('result.native_fbs_changed', false)->json();
        $this->approveControl($stop)->assertOk()->assertJsonPath('result.applied_control.version', '1');
        $stoppedContext = $this->context();
        $open = $this->controlProposal('manual_only');
        $opened = $this->approveControl($open)->assertOk()->assertJsonPath('result.applied_control.mode', 'manual_only')->assertJsonPath('result.applied_control.version', '2')->json();
        $history = $this->getJson('/api/manager/fbs-execution-controls/'.$stop['id'])->assertOk()->assertJsonPath('result.applied_control.mode', 'stopped')->assertJsonPath('current_control.mode', 'manual_only')->json();
        $this->assertSame(0, $this->nativeWrites);
        $wire = json_encode([$context, $stop, $saved, $open, $opened, $history], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($this->store($market)->apiKey, $wire);
        $this->assertStringNotContainsString('account_key', $wire);
        if ($root = getenv('FBS_CONTROL_FIXTURE_EXPORT')) {
            file_put_contents($root.'/'.$market.'.json', json_encode(compact('context', 'stoppedContext', 'stop', 'saved', 'open', 'opened', 'history'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function test_receipt_survives_unavailable_current_store_and_history_stays_private(): void
    {
        $this->httpFixture('ozon');
        $stop = $this->controlProposal('stopped');
        $this->approveControl($stop)->assertOk();
        $this->storeActive = false;
        $this->getJson('/api/manager/fbs-execution-controls/'.$stop['id'])->assertOk()->assertJsonPath('result.applied_control.mode', 'stopped')->assertJsonPath('current_control', null)->assertJsonPath('current_control_available', false);
        $this->user = 8;
        $this->getJson('/api/manager/fbs-execution-controls/'.$stop['id'])->assertNotFound();
        $this->assertSame(0, $this->nativeWrites);
    }

    public function test_fresh_personal_permission_expiry_and_stale_context_prevent_control_changes(): void
    {
        $this->httpFixture('ozon');
        $stop = $this->controlProposal('stopped');
        $this->permissions = ['auto_supply.view', 'integrations.view'];
        $this->approveControl($stop)->assertForbidden();
        $this->permissions[] = 'auto_supply.create';
        $this->duringRead = function () {
            $this->duringRead = null;
            $this->travel(6)->minutes();
        };
        $this->approveControl($stop)->assertOk()->assertJsonPath('status', 'expired')->assertJsonPath('current_control.mode', 'legacy');
        $this->assertSame(0, $this->nativeWrites);
    }

    public function test_an_old_prepared_open_cannot_override_a_newer_decision(): void
    {
        $this->httpFixture('ozon');
        $stop = $this->controlProposal('stopped');
        $this->approveControl($stop)->assertOk();
        $first = $this->controlProposal('manual_only');
        $second = $this->controlProposal('manual_only');
        $this->approveControl($second)->assertOk()->assertJsonPath('result.applied_control.version', '2');
        $this->approveControl($first)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('current_control.version', '2');
        $this->assertSame(0, $this->nativeWrites);
    }

    #[DataProvider('markets')]
    public function test_known_legacy_native_transports_respect_stop_and_do_not_resend(string $market): void
    {
        $this->httpFixture($market);
        $stop = $this->controlProposal('stopped');
        $this->approveControl($stop)->assertOk();
        $store = $this->store($market);
        if ($market === 'ozon') {
            (new OzonClient($store->clientId, $store->apiKey))->post('/v4/posting/fbs/ship', ['posting_number' => '100-1-1']);
        } else {
            (new WildberriesClient($store->apiKey))->post('/api/v3/supplies', ['name' => 'Native must not be called']);
        }
        $this->assertSame(0, $this->nativeWrites);
        config(['services.manager.fbs_execution_controls_enabled' => false]);
        try {
            app(ManagerFbsExecutionPolicy::class)->dispatch($store, null, fn () => $this->nativeWrites++);
            $this->fail('Disabling UI removed a stop');
        } catch (ManagerFbsExecutionStopped) {
            $this->assertSame(0, $this->nativeWrites);
        }
    }

    #[DataProvider('markets')]
    public function test_original_fbs_draft_does_not_revive_and_a_new_personal_command_can_execute(string $market): void
    {
        $this->httpFixture($market);
        $this->singleOperations = true;
        $body = ['integration_id' => 28, 'external_id' => $market === 'ozon' ? '100-1-1' : '101', 'operation_type' => $market === 'ozon' ? 'fbs_pack' : 'fbs_assign'];
        if ($market === 'wildberries') {
            $body['supply_id'] = 'WB-1';
        }
        $old = $this->postJson('/api/manager/fbs/prepare', $body)->assertOk()->json();
        $plan = ManagerFbsOperation::findOrFail($old['id'])->plan;
        $this->assertSame('0', $plan['_execution_control']['version']);
        $stop = $this->controlProposal('stopped');
        $this->approveControl($stop)->assertOk();
        $blocked = app(ManagerFbsGateway::class)->submit($this->store($market), $plan);
        $this->assertTrue($blocked['stopped_before_native']);
        $this->assertSame('failed', $blocked['status']);
        $open = $this->controlProposal('manual_only');
        $this->approveControl($open)->assertOk();
        $approve = fn ($op) => $this->postJson('/api/manager/fbs-operations/'.$op['id'].'/approve', ['fingerprint' => $op['fingerprint'],
            'confirmations' => array_fill_keys(array_column($op['required_confirmations'], 'key'), true)]);
        $approve($old)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(0, $this->nativeWrites);
        $fresh = $this->postJson('/api/manager/fbs/prepare', $body)->assertOk()->json();
        $this->assertSame('2', ManagerFbsOperation::findOrFail($fresh['id'])->plan['_execution_control']['version']);
        $approve($fresh)->assertOk()->assertJsonPath('status', 'succeeded');
        $approve($fresh)->assertOk();
        $this->assertSame(1, $this->nativeWrites);
    }

    #[DataProvider('markets')]
    public function test_physical_alias_and_credential_rotation_do_not_escape_stop(string $market): void
    {
        config(['services.manager.fbs_execution_controls_enabled' => true, 'services.manager.fbs_writes_enabled' => true]);
        $policy = app(ManagerFbsExecutionPolicy::class);
        $store = $this->store($market);
        $snapshot = $policy->snapshot($store);
        DB::table(ManagerFbsExecutionPolicy::TABLE)->where('account_key', $snapshot['account_key'])->update(['mode' => 'stopped', 'version' => 1]);
        $rotated = new ManagerStore(4, 99, $market, 'Other connection', $market === 'ozon' ? 'rotated-key' : str_replace('.signature', '.rotated-signature', $store->apiKey), $store->clientId, str_repeat('b', 64));
        $this->assertSame($policy->accountKey($store), $policy->accountKey($rotated));
        $this->expectException(ManagerFbsExecutionStopped::class);
        $policy->dispatch($rotated, null, fn () => $this->fail('Alias escaped stop'));
    }

    public static function legacyMethods(): array
    {
        return [['post', '/api/v3/supplies'], ['patch', '/api/v3/supplies/WB-1/deliver'], ['put', '/api/v3/orders/101/cancel'], ['delete', '/api/v3/supplies/WB-1']];
    }

    #[DataProvider('legacyMethods')]
    public function test_wb_legacy_write_verbs_respect_the_same_account_stop(string $method, string $path): void
    {
        $this->httpFixture('wildberries');
        $op = $this->controlProposal('stopped');
        $this->approveControl($op)->assertOk();
        $client = new WildberriesClient($this->store('wildberries')->apiKey);
        $client->{$method}($path);
        $this->assertSame(0, $this->nativeWrites);
    }

    public function test_read_and_document_classification_stays_separate_from_native_mutations(): void
    {
        foreach (['/v4/posting/fbs/list', '/v3/posting/fbs/get', '/v1/posting/fbs/cancel-reason', '/v2/posting/fbs/cancel-reason/list',
            '/v3/posting/fbs/package-label/create', '/v2/posting/fbs/package-label/get', '/v2/posting/fbs/act/get-pdf', '/v1/posting/carriage-available/list'] as $path) {
            $this->assertFalse(ManagerFbsExecutionPolicy::isMutation('ozon', 'POST', 'https://api-seller.ozon.ru', $path), $path);
        }
        foreach (['/v4/posting/fbs/ship', '/v2/posting/fbs/cancel', '/v1/carriage/create', '/v1/carriage/approve', '/v4/posting/fbs/future-mutation'] as $path) {
            $this->assertTrue(ManagerFbsExecutionPolicy::isMutation('ozon', 'POST', 'https://api-seller.ozon.ru', $path), $path);
        }
        foreach (['/api/v3/orders/status', '/api/v3/orders/stickers?type=png'] as $path) {
            $this->assertFalse(ManagerFbsExecutionPolicy::isMutation('wildberries', 'POST', 'https://marketplace-api.wildberries.ru', $path));
        }
        $this->assertTrue(ManagerFbsExecutionPolicy::isMutation('wildberries', 'PATCH', 'https://marketplace-api.wildberries.ru', '/api/marketplace/v3/fbs/supplies/shipping-method'));
    }

    public function test_native_callback_is_not_retried_when_it_throws_after_start(): void
    {
        config(['services.manager.fbs_execution_controls_enabled' => true, 'services.manager.fbs_writes_enabled' => true]);
        $p = app(ManagerFbsExecutionPolicy::class);
        $store = $this->store('ozon');
        $snapshot = $p->snapshot($store);
        $attempts = 0;
        try {
            $p->dispatch($store, $snapshot, function () use (&$attempts) {
                $attempts++;
                throw new \RuntimeException('uncertain native response');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, $attempts);
    }

    public function test_history_and_active_stops_cannot_be_removed_by_rollback(): void
    {
        $this->httpFixture('ozon');
        $op = $this->controlProposal('stopped');
        $this->approveControl($op)->assertOk();
        $migration = require database_path('migrations/2026_11_09_150000_create_manager_fbs_execution_controls.php');
        try {
            $migration->down();
            $this->fail('Rollback erased a restriction');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('manager_fbs_execution_operations', 1);
            $this->assertDatabaseHas(ManagerFbsExecutionPolicy::TABLE, ['mode' => 'stopped', 'version' => 1]);
        }
    }

    public function test_administrative_stop_prevents_local_legacy_ship_even_without_personal_control(): void
    {
        $this->httpFixture('ozon');
        config(['services.manager.fbs_execution_controls_enabled' => false, 'services.manager.fbs_writes_enabled' => false]);
        $posting = Posting::create(['integration_id' => 28, 'marketplace' => 'ozon',
            'posting_number' => 'administrative-stop', 'status' => Posting::STATUS_AWAITING_DELIVER]);
        try {
            app(PostingService::class)->ship($posting);
            $this->fail('Administrative stop did not prevent a local FBS mutation');
        } catch (ManagerDataException $e) {
            $this->assertSame(503, $e->httpStatus);
        }
        $this->assertSame(Posting::STATUS_AWAITING_DELIVER, $posting->fresh()->status);
        $this->assertNull($posting->fresh()->shipped_at);
        $this->assertSame(0, $this->nativeWrites);
    }

    public function test_legacy_oauth_ship_does_not_change_local_status_for_an_unverified_seller(): void
    {
        $this->httpFixture('ozon');
        $stop = $this->controlProposal('stopped');
        $this->approveControl($stop)->assertOk();
        foreach (['oauth_access_token', 'access_token', 'configured'] as $source) {
            config(['services.ozon.access_token' => $source === 'configured' ? 'isolated-oauth-token' : '']);
            $credentials = ['client_id' => '777', 'api_key' => 'unused-seller-key'];
            if ($source !== 'configured') {
                $credentials[$source] = 'isolated-oauth-token';
            }
            Integration::findOrFail(28)->update(['credentials' => $credentials]);
            $posting = Posting::create(['integration_id' => 28, 'marketplace' => 'ozon',
                'posting_number' => 'oauth-'.$source, 'status' => Posting::STATUS_AWAITING_DELIVER]);
            try {
                app(PostingService::class)->ship($posting);
                $this->fail('OAuth identity did not prevent a local FBS mutation');
            } catch (ManagerDataException $e) {
                $this->assertSame(503, $e->httpStatus);
            }
            $this->assertSame(Posting::STATUS_AWAITING_DELIVER, $posting->fresh()->status);
            $this->assertNull($posting->fresh()->shipped_at);
        }
        $this->assertSame(0, $this->nativeWrites);
    }

    public function test_oauth_transport_cannot_claim_an_unverified_seller_client_id_to_escape_control(): void
    {
        $this->httpFixture('ozon');
        $stop = $this->controlProposal('stopped');
        $this->approveControl($stop)->assertOk();
        $native = 0;
        Http::fake(function () use (&$native) {
            $native++;

            return Http::response(['result' => ['100-1-1']]);
        });
        // In OAuth mode these Seller headers are not sent, so "777" cannot prove the token's account.
        (new OzonClient('777', 'unused-seller-key', 'isolated-oauth-token'))->post('/v4/posting/fbs/ship', ['posting_number' => '100-1-1']);
        $this->assertSame(0, $native);
    }
}
