<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\ManagerFbsOperation;
use App\Models\Posting;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerFbsPublicationGuard;
use App\Services\PostingService;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ManagerFbsOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $permissions = ['auto_supply.view', 'integrations.view', 'auto_supply.create'];

    private int $user = 42;

    private string $key = 'vendor-secret';

    private string $market = 'ozon';

    private string $state = 'awaiting_packaging';

    private int $quantity = 2;

    private int $writes = 0;

    private bool $lostResponse = false;

    private bool $readUnavailable = false;

    private bool $apply = true;

    private bool $requiresImei = false;

    private bool $additionalSteps = false;

    private array $orderIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true, 'services.manager.fbs_writes_enabled' => true]);
        Http::preventStrayRequests();
        Cache::flush();
        $this->mock(SellicoApiService::class, fn ($mock) => $mock->shouldReceive('getServiceToken')->andReturn('service-session'));
        $this->withToken('human-session')->withHeader('X-Sellico-Workspace', '8');
        Http::fake(function ($r) {
            $url = $r->url();
            if ($url === 'https://crm.test/api/user') {
                return Http::response(['id' => $this->user, 'is_service_account' => false]);
            }
            if (preg_match('~/workspaces/([0-9]+)/user-permissions$~', $url, $parts)) {
                return Http::response(['user_id' => $this->user, 'workspace_id' => (int) $parts[1], 'permissions' => $this->permissions]);
            }
            if ($url === 'https://crm.test/api/get-integrations/8') {
                return Http::response([['id' => 62001, 'work_space_id' => 8, 'status' => 'active', 'type' => $this->market, 'name' => 'Test store', 'api_key' => $this->key, 'client_id' => '555']]);
            }
            if ($url === 'https://api-seller.ozon.ru/v3/posting/fbs/get') {
                if ($this->readUnavailable) {
                    return Http::response([], 503);
                }
                $requirements = array_fill_keys(['products_requiring_gtd', 'products_requiring_jw_uin', 'products_requiring_country', 'products_requiring_rnpt', 'products_requiring_mandatory_mark', 'products_requiring_change_country', 'products_requiring_imei', 'products_requiring_weight'], []);
                if ($this->requiresImei) {
                    $requirements['products_requiring_imei'] = [40001];
                }

                return Http::response(['result' => ['posting_number' => '100-1-1', 'order_id' => 701, 'status' => $this->state, 'is_express' => false, 'is_multibox' => false, 'integration_type_flow' => 'ozon',
                    'available_actions' => $this->state === 'awaiting_packaging' ? ($this->additionalSteps ? ['ship', 'ship_with_additional_info'] : ['ship', 'ship_async']) : ['label_download'], 'requirements' => $requirements, 'require_blr_traceable_attrs' => false,
                    'products' => [['sku' => 40001, 'offer_id' => 'O-SKU', 'name' => 'Test product', 'quantity' => $this->quantity, 'mandatory_mark' => [], 'jw_uin' => [],
                        'has_imei' => false, 'is_weight_needed' => false, 'is_blr_traceable' => false, 'is_marketplace_buyout' => false]],
                    'customer' => ['phone' => 'private-customer-phone'], 'addressee' => ['name' => 'private-customer-name']]]);
            }
            if ($url === 'https://api-seller.ozon.ru/v4/posting/fbs/ship') {
                $this->writes++;
                $this->assertSame('100-1-1', $r['posting_number']);
                $this->assertSame([['products' => [['product_id' => 40001, 'quantity' => 2]]]], $r['packages']);
                $this->assertTrue($r->hasHeader('Api-Key', 'vendor-secret'));
                if ($this->apply) {
                    $this->state = 'awaiting_deliver';
                }
                if ($this->lostResponse) {
                    throw new ConnectionException('private-network-payload');
                }

                return Http::response(['result' => ['100-1-1']]);
            }
            if ($url === 'https://marketplace-api.wildberries.ru/api/v3/orders/new') {
                return Http::response(['orders' => [['id' => 11, 'nmId' => 10001, 'article' => 'WB-SKU', 'skus' => ['012345'],
                    'createdAt' => '2026-11-05T08:00:00Z', 'warehouseId' => 10, 'deliveryType' => 'fbs', 'crossBorderType' => 0, 'cargoType' => 1, 'options' => ['isB2B' => false], 'requiredMeta' => []]]]);
            }
            if ($url === 'https://marketplace-api.wildberries.ru/api/v3/supplies/WB-GI-123') {
                return Http::response(['id' => 'WB-GI-123', 'done' => false, 'isB2b' => false, 'cargoType' => 1, 'crossBorderType' => 0]);
            }
            if ($url === 'https://marketplace-api.wildberries.ru/api/marketplace/v3/supplies/WB-GI-123/order-ids') {
                return Http::response(['orderIds' => $this->orderIds]);
            }
            if ($url === 'https://marketplace-api.wildberries.ru/api/marketplace/v3/supplies/WB-GI-123/orders') {
                $this->writes++;
                $this->assertSame('PATCH', $r->method());
                $this->assertSame([11], $r['orders']);
                if ($this->apply) {
                    $this->orderIds = [11];
                    $this->state = 'confirm';
                }
                if ($this->lostResponse) {
                    throw new ConnectionException;
                }

                return Http::response(null, 204);
            }
            if ($url === 'https://marketplace-api.wildberries.ru/api/v3/orders/status') {
                return Http::response(['orders' => [['id' => 11, 'supplierStatus' => $this->state]]]);
            }
            $this->fail('Unexpected outbound request');
        });
    }

    private function prepare(string $market = 'ozon'): array
    {
        $this->market = $market;
        Integration::factory()->{$market}()->create(['id' => 62001, 'work_space_id' => 8]);

        return $this->postJson('/api/manager/fbs/prepare', ['integration_id' => 62001, 'external_id' => $market === 'ozon' ? '100-1-1' : '11',
            'operation_type' => $market === 'ozon' ? 'fbs_pack' : 'fbs_assign', ...($market === 'wildberries' ? ['supply_id' => 'WB-GI-123'] : [])])->assertOk()->json();
    }

    private function approve(array $op, array $extra = []): TestResponse
    {
        return $this->postJson('/api/manager/fbs-operations/'.$op['id'].'/approve', ['fingerprint' => $op['fingerprint'], 'confirmations' => ['items_and_packing_checked' => true], ...$extra]);
    }

    public function test_preparation_only_records_an_immutable_private_proposal_and_not_a_seller_write(): void
    {
        $op = $this->prepare();
        $this->assertSame('awaiting_confirmation', $op['status']);
        $this->assertSame(0, $this->writes);
        $this->assertSame([['field' => 'vendor_state', 'label' => 'Состояние в Ozon', 'before' => 'awaiting_packaging', 'after' => 'awaiting_deliver']], $op['diff']);
        $this->assertSame(2, $op['items'][0]['quantity']);
        $this->assertNull(ManagerFbsOperation::findOrFail($op['id'])->resource_key);
        $this->assertStringNotContainsString('private-customer', json_encode($op));
        $this->assertStringNotContainsString('vendor-secret', json_encode($op));
        $this->assertStringNotContainsString('private-customer', json_encode(ManagerFbsOperation::findOrFail($op['id'])->plan));
    }

    public function test_single_pack_rechecks_every_durable_target_immediately_before_native_start(): void
    {
        $op = $this->prepare();
        $originalDispatcher = ManagerFbsOperation::getEventDispatcher();
        ManagerFbsOperation::setEventDispatcher(clone $originalDispatcher);
        try {
            ManagerFbsOperation::updated(function ($row) {
                if ($row->status === 'executing' && $row->native_started_at === null) {
                    DB::table('manager_fbs_resource_holds')->where('operation_id', $row->id)
                        ->where('resource_key', ManagerFbsPublicationGuard::resource('ozon', 'ozon-order:555:701'))->delete();
                }
            });
            $this->approve($op)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.native_attempted', false);
            $this->assertSame(0, $this->writes);
            $this->assertSame(0, DB::table('manager_fbs_resource_holds')->where('operation_id', $op['id'])->count());
        } finally {
            ManagerFbsOperation::setEventDispatcher($originalDispatcher);
        }
    }

    public function test_single_pack_rechecks_current_permissions_after_claiming_the_group_resources(): void
    {
        $op = $this->prepare();
        $originalDispatcher = ManagerFbsOperation::getEventDispatcher();
        ManagerFbsOperation::setEventDispatcher(clone $originalDispatcher);
        try {
            ManagerFbsOperation::updated(function ($row) {
                if ($row->status === 'executing') $this->permissions = ['auto_supply.view', 'integrations.view'];
            });
            $this->approve($op)->assertForbidden();
            $this->assertSame(0, $this->writes);
            $this->assertSame('failed', ManagerFbsOperation::findOrFail($op['id'])->status);
            $this->assertSame(0, DB::table('manager_fbs_resource_holds')->where('operation_id', $op['id'])->count());
        } finally {
            ManagerFbsOperation::setEventDispatcher($originalDispatcher);
        }
    }

    public function test_exact_approval_verifies_api_state_once_without_claiming_physical_handoff(): void
    {
        $op = $this->prepare();
        $this->approve($op)->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.vendor_state', 'awaiting_deliver')
            ->assertJsonPath('result.submission_acknowledged', true)->assertJsonPath('result.fields_verified', true)->assertJsonPath('physical_status', null);
        $this->approve($op)->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertSame(1, $this->writes);
        $this->assertNull(ManagerFbsOperation::findOrFail($op['id'])->resource_key);
        $this->assertSame(42, ManagerFbsOperation::findOrFail($op['id'])->confirmations['user_id']);
    }

    public function test_physical_check_exact_fingerprint_and_immutable_request_fields_are_required(): void
    {
        $op = $this->prepare();
        $this->approve($op, ['confirmations' => []])->assertUnprocessable();
        $this->approve($op, ['fingerprint' => str_repeat('a', 64)])->assertStatus(409);
        $this->approve($op, ['products' => [['product_id' => 40001, 'quantity' => 100]]])->assertUnprocessable();
        $this->assertSame(0, $this->writes);
    }

    public function test_changed_quantities_cancel_old_proposal_before_the_seller_write(): void
    {
        $op = $this->prepare();
        $this->quantity = 3;
        $this->approve($op)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(0, $this->writes);
    }

    public function test_new_marking_requirement_cancels_old_proposal_before_dispatch(): void
    {
        $op = $this->prepare();
        $this->requiresImei = true;
        $this->approve($op)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(0, $this->writes);
    }

    public function test_additional_action_requirements_block_even_when_ship_is_in_available_actions(): void
    {
        $op = $this->prepare();
        $this->additionalSteps = true;
        $this->approve($op)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(0, $this->writes);
    }

    public function test_rotated_store_key_cancels_unexecuted_proposal(): void
    {
        $op = $this->prepare();
        $this->key = 'rotated';
        $this->approve($op)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(0, $this->writes);
    }

    public function test_expired_and_cancelled_proposals_cannot_be_executed(): void
    {
        $op = $this->prepare();
        $this->postJson('/api/manager/fbs-operations/'.$op['id'].'/cancel', ['fingerprint' => $op['fingerprint']])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->approve($op)->assertOk()->assertJsonPath('status', 'cancelled');
        ManagerFbsOperation::findOrFail($op['id'])->update(['status' => 'awaiting_confirmation']);
        $this->travel(6)->minutes();
        $this->approve($op)->assertOk()->assertJsonPath('status', 'expired');
        $this->assertSame(0, $this->writes);
    }

    public function test_owner_workspace_and_fresh_create_permission_are_required(): void
    {
        $op = $this->prepare();
        $this->user = 43;
        $this->approve($op)->assertNotFound();
        $this->user = 42;
        $this->withHeader('X-Sellico-Workspace', '9')->getJson('/api/manager/fbs-operations/'.$op['id'])->assertNotFound();
        $this->withHeader('X-Sellico-Workspace', '8');
        $this->permissions = ['auto_supply.view', 'integrations.view'];
        $this->approve($op)->assertForbidden();
        $this->assertSame(0, $this->writes);
    }

    public function test_lost_response_stays_unknown_even_when_readback_matches_and_blocks_other_publishers(): void
    {
        $op = $this->prepare();
        $this->lostResponse = true;
        $this->approve($op)->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result.fields_verified', true)->assertJsonPath('result.submission_acknowledged', false);
        $this->approve($op)->assertOk()->assertJsonPath('status', 'unknown');
        $this->postJson('/api/manager/fbs-operations/'.$op['id'].'/reconcile', [])->assertOk()->assertJsonPath('status', 'unknown');
        $this->key = 'rotated';
        $this->postJson('/api/manager/fbs/prepare', ['integration_id' => 62001, 'external_id' => '100-1-1', 'operation_type' => 'fbs_pack'])->assertStatus(409);
        $called = false;
        try {
            app(ManagerFbsPublicationGuard::class)->run('ozon', '100-1-1', function () use (&$called) {
                $called = true;
            });
            $this->fail('must block');
        } catch (ManagerDataException $error) {
            $this->assertSame(409, $error->httpStatus);
        }
        $this->assertFalse($called);
        $legacy = new Posting(['integration_id' => 62001, 'posting_number' => '100-1-1']);
        foreach (['pack', 'ship', 'cancel'] as $method) {
            try {
                match ($method) {
                    'pack' => app(PostingService::class)->pack($legacy, []),
                    'ship' => app(PostingService::class)->ship($legacy),
                    'cancel' => app(PostingService::class)->cancel($legacy, 1),
                };
                $this->fail('Legacy path must respect unresolved operation');
            } catch (ManagerDataException $error) {
                $this->assertSame(409, $error->httpStatus);
            }
        }
        $this->assertSame(1, $this->writes);
        $this->assertNotNull(ManagerFbsOperation::findOrFail($op['id'])->resource_key);
    }

    public function test_accepted_response_without_matching_readback_remains_verifying_then_can_be_read_without_resending(): void
    {
        $op = $this->prepare();
        $this->apply = false;
        $this->approve($op)->assertOk()->assertJsonPath('status', 'verifying')->assertJsonPath('result.fields_verified', false);
        $this->state = 'awaiting_deliver';
        $this->postJson('/api/manager/fbs-operations/'.$op['id'].'/reconcile', [])->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertSame(1, $this->writes);
    }

    public function test_delayed_readback_can_resolve_an_acknowledged_request_after_the_verification_deadline(): void
    {
        $op = $this->prepare();
        $this->apply = false;
        $this->approve($op)->assertOk()->assertJsonPath('status', 'verifying');
        $this->travel(16)->minutes();
        $this->postJson('/api/manager/fbs-operations/'.$op['id'].'/reconcile', [])->assertOk()->assertJsonPath('status', 'unknown');
        $this->state = 'awaiting_deliver';
        $this->postJson('/api/manager/fbs-operations/'.$op['id'].'/reconcile', [])->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertSame(1, $this->writes);
    }

    public function test_interrupted_claim_recovers_to_unknown_and_keeps_its_resource_hold(): void
    {
        $op = $this->prepare();
        ManagerFbsOperation::findOrFail($op['id'])->update(['status' => 'executing', 'execution_started_at' => now()->subMinutes(4), 'resource_key' => ManagerFbsPublicationGuard::resource('ozon', '100-1-1')]);
        $this->getJson('/api/manager/fbs-operations/'.$op['id'])->assertOk()->assertJsonPath('status', 'unknown');
        $this->approve($op)->assertOk()->assertJsonPath('status', 'unknown');
        $this->assertSame(0, $this->writes);
    }

    public function test_wb_adds_only_the_reviewed_task_to_the_reviewed_supply_then_checks_both_status_and_membership(): void
    {
        $op = $this->prepare('wildberries');
        $this->approve($op, ['confirmations' => []])->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.vendor_state', 'confirm')->assertJsonPath('physical_status', null);
        $this->assertSame(1, $this->writes);
        $this->assertSame([11], $this->orderIds);
    }

    public function test_foreign_pending_order_is_not_disclosed_before_vendor_ownership_is_verified(): void
    {
        $op = $this->prepare();
        ManagerFbsOperation::findOrFail($op['id'])->update(['workspace_id' => 9, 'integration_id' => 62002, 'external_id' => '200-1-1', 'status' => 'unknown',
            'resource_key' => ManagerFbsPublicationGuard::resource('ozon', '200-1-1')]);
        $this->postJson('/api/manager/fbs/prepare', ['integration_id' => 62001, 'external_id' => '200-1-1', 'operation_type' => 'fbs_pack'])
            ->assertStatus(503)->assertDontSee('незавершённая')->assertDontSee('200-1-1');
        $this->assertSame(0, $this->writes);
    }

    public function test_global_stop_and_an_active_lock_block_dispatch_before_it_starts(): void
    {
        $op = $this->prepare();
        config(['services.manager.fbs_writes_enabled' => false]);
        $this->approve($op)->assertStatus(503);
        config(['services.manager.fbs_writes_enabled' => true]);
        $lock = Cache::lock('manager-fbs-target:'.ManagerFbsPublicationGuard::resource('ozon', '100-1-1'), 180);
        $this->assertTrue($lock->get());
        try {
            $this->approve($op)->assertStatus(409);
        } finally {
            $lock->release();
        }
        $this->assertSame(0, $this->writes);
        $this->assertSame(1, DB::table('manager_fbs_operation_events')->where('operation_id', $op['id'])->count());
    }
}
