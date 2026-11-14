<?php

namespace Tests\Feature;

use App\Domains\Ozon\Api\OzonClient;
use App\Exceptions\OzonPreconditionException;
use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\Integration;
use App\Models\ManagerOzonFboOperation;
use App\Models\Product;
use App\Models\Supply;
use App\Services\AutoSupplyPlanning\OzonPlanMaterializationService;
use App\Services\Manager\ManagerFboPublicationGuard;
use App\Services\Manager\ManagerOzonFboService;
use App\Services\SellicoApiService;
use App\Services\Supply\SupplyService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ManagerOzonFboOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const API = '/api/manager/ozon-fbo';

    private AutoSupplyPlan $plan;

    private AutoSupplyPlanLine $line;

    private array $permissions = ['auto_supply.view', 'auto_supply.create', 'integrations.view'];

    private string $key = 'isolated-private-key';

    private int $user = 42;

    private bool $factsReady = true;

    private bool $lose = false;

    private bool $readUnavailable = false;

    private string $availability = 'FULL_AVAILABLE';

    private int $acceptedQty = 5;

    private int $actualQty = 5;

    private int $actualWarehouse = 701;

    private string $actualFrom = '2026-11-20T06:00:00Z';

    private string $draftStatus = 'SUCCESS';

    private bool $orderCreated = false;

    private array $writes = [];

    private array $protocol = [];

    private $afterProductRead = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDateTime(2026, 11, 9, 12, 0, 0));
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true, 'services.manager.ozon_fbo_enabled' => true]);
        Cache::flush();
        Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('isolated-service-session'));
        // Broker checks use a controlled calculator receipt; the real materializer/freshness gates have their own integration tests.
        $this->mock(OzonPlanMaterializationService::class, fn ($m) => $m->shouldReceive('approvalCheck')->andReturnUsing(fn () => ['allowed' => $this->factsReady, 'fingerprint' => str_repeat('a', 64)]));
        $this->withToken('isolated-human-session')->withHeader('X-Sellico-Workspace', '8');
        Integration::factory()->ozon()->create(['id' => 62251, 'work_space_id' => 8]);
        $this->plan = AutoSupplyPlan::create(['integration_id' => 62251, 'mp_account_id' => 62251, 'marketplace' => 'ozon', 'status' => 'ready', 'business_status' => 'approved',
            'params' => ['supply_method' => 'direct', 'cluster_ids' => ['1']], 'approval_fingerprint' => str_repeat('a', 64),
            'validation_json' => ['facts_hash' => str_repeat('b', 64)], 'result_json' => ['plan_quality_audit' => ['status' => 'good', 'acceptance_gates' => ['can_create_ozon_draft' => true]]]]);
        Product::factory()->ozon()->create(['integration_id' => 62251, 'sku' => 'SELLER-A', 'marketplace_id' => '900001', 'ozon_data' => ['sku' => 700001]]);
        $this->line = AutoSupplyPlanLine::create(['auto_supply_plan_id' => $this->plan->id, 'sku' => 'SELLER-A', 'offer_id' => 'OFFER-A', 'cluster_id' => '1',
            'cluster_name' => 'Central', 'destination_type' => 'cluster', 'qty_rounded' => 5, 'qty_recommended' => 5, 'is_excluded' => false]);
        Http::fake(function ($r) {
            $url = $r->url();
            $path = parse_url($url, PHP_URL_PATH);
            if ($url === 'https://crm.test/api/user') {
                return Http::response(['id' => $this->user, 'is_service_account' => false]);
            }
            if ($url === 'https://crm.test/api/workspaces/8/user-permissions') {
                return Http::response(['user_id' => $this->user, 'permissions' => $this->permissions]);
            }
            if ($url === 'https://crm.test/api/get-integrations/8') {
                return Http::response([['id' => 62251, 'work_space_id' => 8, 'status' => 'active', 'type' => 'ozon', 'name' => 'Isolated Ozon', 'api_key' => $this->key, 'client_id' => '555']]);
            }
            $this->assertStringStartsWith('https://api-seller.ozon.ru/', $url);
            $this->assertSame('555', $r->header('Client-Id')[0]);
            if ($path === '/v1/cluster/list') {
                return Http::response(['clusters' => [['id' => 1, 'name' => 'Central', 'macrolocal_cluster_id' => 4039, 'logistic_clusters' => []]]]);
            }
            if ($path === '/v3/product/info/list') {
                if ($this->afterProductRead) {
                    $fn = $this->afterProductRead;
                    $this->afterProductRead = null;
                    $fn();
                }

                return Http::response(['items' => [['offer_id' => 'OFFER-A', 'id' => 900001, 'sku' => 700001, 'name' => 'Isolated product', 'is_archived' => false, 'is_discounted' => false]]]);
            }
            if ($path === '/v1/draft/direct/create' || $path === '/v2/draft/supply/create') {
                $this->writes[] = $path;
                $this->assertTrue(DB::table('manager_ozon_fbo_operations')->where('status', 'executing')->whereNotNull('native_started_at')->exists());
                $this->assertTrue(DB::table('manager_fbo_resource_holds')->exists());
                if ($path === '/v2/draft/supply/create') {
                    $this->orderCreated = true;
                }
                if ($this->lose) {
                    throw new ConnectionException('isolated-private-error');
                }

                return Http::response(['draft_id' => 77, 'errors' => [], 'error_reasons' => []]);
            }
            if ($this->readUnavailable) {
                return Http::response([], 503);
            }
            if ($path === '/v2/draft/create/info') {
                return Http::response(['status' => $this->draftStatus, 'errors' => [], 'clusters' => [[
                    'macrolocal_cluster_id' => 4039, 'cluster_name' => 'Central', 'supply_type' => 'DIRECT', 'warehouses' => [[
                        'storage_warehouse' => ['warehouse_id' => 701, 'name' => 'Test warehouse', 'address' => 'Test address'],
                        'availability_status' => ['state' => $this->availability], 'bundle_id' => 'DRAFT-BUNDLE', 'supply_tags' => [],
                    ]],
                ]]]);
            }
            if ($path === '/v1/supply-order/bundle') {
                return Http::response(['items' => [['sku' => 700001, 'quantity' => $r['bundle_ids'][0] === 'DRAFT-BUNDLE' ? $this->acceptedQty : $this->actualQty]], 'has_next' => false, 'total_count' => 1]);
            }
            if ($path === '/v2/draft/timeslot/info') {
                return Http::response(['result' => ['drop_off_warehouse_timeslots' => ['warehouse_timezone' => 'Europe/Moscow', 'days' => [[
                    'date_in_timezone' => '2026-11-20', 'timeslots' => [['from_in_timezone' => '2026-11-20T09:00:00', 'to_in_timezone' => '2026-11-20T10:00:00']],
                ]]]]]);
            }
            if ($path === '/v2/draft/supply/create/status') {
                return Http::response(['status' => $this->orderCreated ? 'SUCCESS' : 'UNSPECIFIED', 'order_id' => $this->orderCreated ? 5001 : 0, 'error_reasons' => []]);
            }
            if ($path === '/v3/supply-order/get') {
                return Http::response(['orders' => [['order_id' => 5001, 'order_number' => 'TEST-5001', 'state' => 'READY_TO_SUPPLY',
                    'dropoff_warehouse' => ['warehouse_id' => $this->actualWarehouse], 'timeslot' => ['timeslot' => ['from' => $this->actualFrom, 'to' => '2026-11-20T07:00:00Z'], 'timezone_info' => ['iana_name' => 'Europe/Moscow', 'offset' => '+03:00']],
                    'supplies' => [['supply_id' => 101, 'storage_warehouse' => ['warehouse_id' => $this->actualWarehouse], 'bundle_id' => 'ORDER-BUNDLE', 'state' => 'READY_TO_SUPPLY', 'is_crossdock' => false]],
                ]]]);
            }
            $this->fail('Unexpected fixed Native endpoint: '.$path);
        });
    }

    private function selection(array $changes = []): array
    {
        return array_replace(['operation_type' => 'fbo_ozon_draft_create', 'plan_id' => $this->plan->id, 'native_cluster_id' => '1', 'draft_operation_id' => null,
            'warehouse_id' => null, 'date_from' => null, 'date_to' => null, 'slot_key' => null], $changes);
    }

    private function prepare(array $selection): array
    {
        $context = $this->postJson(self::API.'/context', ['integration_id' => 62251, 'selection' => $selection])->assertOk()->json();

        $this->protocol['contexts'][] = $context;

        return $this->postJson(self::API.'/prepare', ['integration_id' => 62251, 'selection' => $selection, 'context_fingerprint' => $context['context_fingerprint']])->assertOk()->json();
    }

    private function decide(array $operation, string $decision = 'approve', ?array $checks = null)
    {
        $body = ['fingerprint' => $operation['fingerprint']];
        if ($decision === 'approve') {
            $body['confirmations'] = $checks ?? array_fill_keys(ManagerOzonFboService::CHECKS, true);
        }

        return $this->postJson(self::API.'/'.$operation['id'].'/'.$decision, $body);
    }

    private function draft(): array
    {
        return $this->decide($this->prepare($this->selection()))->assertOk()->assertJsonPath('status', 'succeeded')->json();
    }

    private function supplySelection(array $draft): array
    {
        $slots = $this->postJson(self::API.'/'.$draft['id'].'/slots', ['warehouse_id' => '701', 'date_from' => '2026-11-20', 'date_to' => '2026-11-20'])->assertOk()->json();

        $this->protocol['slots'] = $slots;

        return $this->selection(['operation_type' => 'fbo_ozon_supply_create', 'plan_id' => null, 'native_cluster_id' => null, 'draft_operation_id' => $draft['id'],
            'warehouse_id' => '701', 'date_from' => '2026-11-20', 'date_to' => '2026-11-20', 'slot_key' => $slots['slots'][0]['slot_key']]);
    }

    public function test_two_personal_confirmations_create_and_verify_the_exact_direct_supply(): void
    {
        $proposal = $this->prepare($this->selection());
        $this->assertSame([], $this->writes);
        $draft = $this->decide($proposal)->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.draft_created_verified', true)->json();
        $availability = $this->getJson(self::API.'/'.$draft['id'].'/availability')->assertOk()->assertJsonPath('composition_verified', false)->json();
        $supply = $this->prepare($this->supplySelection($draft));
        $this->assertCount(1, $this->writes);
        $result = $this->decide($supply)->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.fields_verified', true)
            ->assertJsonPath('result.composition_verified', true)->assertJsonPath('native_order_id', '5001')->assertJsonPath('result.physical_status', null)->json();
        $this->assertSame(['/v1/draft/direct/create', '/v2/draft/supply/create'], $this->writes);
        $this->decide($supply)->assertOk();
        $this->assertCount(2, $this->writes);
        $this->assertSame(2, DB::table('manager_fbo_resource_holds')->count()); // Permanent plan/draft no-replay receipts, account released.
        $json = json_encode([$draft, $supply, $result, $availability]);
        foreach (['isolated-private-key', 'store_binding', '_account', '_selection', '_facts'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        if ($target = getenv('OZON_FBO_PROTOCOL_FIXTURE')) {
            file_put_contents($target, json_encode([...$this->protocol, 'draft_proposal' => $proposal, 'draft' => $draft, 'supply_proposal' => $supply, 'supply' => $result, 'availability' => $availability], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_missing_false_or_extra_checks_never_send_a_native_command(): void
    {
        $op = $this->prepare($this->selection());
        foreach ([[], array_fill_keys(ManagerOzonFboService::CHECKS, false), array_fill_keys([...ManagerOzonFboService::CHECKS, 'extra'], true)] as $checks) {
            $this->decide($op, 'approve', $checks)->assertStatus(422);
        }
        $this->assertSame([], $this->writes);
    }

    public function test_fresh_permission_revocation_before_send_releases_holds_without_a_native_command(): void
    {
        $op = $this->prepare($this->selection());
        $this->afterProductRead = function () {
            $this->permissions = ['auto_supply.view', 'integrations.view'];
        };
        $this->decide($op)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.native_attempted', false);
        $this->assertSame([], $this->writes);
        $this->assertSame(0, DB::table('manager_fbo_resource_holds')->count());
    }

    public function test_changed_plan_facts_or_credentials_cannot_use_an_old_proposal(): void
    {
        foreach (['quantity', 'facts', 'key'] as $change) {
            $op = $this->prepare($this->selection());
            if ($change === 'quantity') {
                $this->line->update(['qty_rounded' => 6]);
            }
            if ($change === 'facts') {
                $this->factsReady = false;
            }
            if ($change === 'key') {
                $this->afterProductRead = function () {
                    $this->key = 'rotated-isolated-key';
                };
            }
            $this->decide($op)->assertOk()->assertJsonPath('status', 'failed');
            $this->line->update(['qty_rounded' => 5]);
            $this->factsReady = true;
        }
        $this->assertSame([], $this->writes);
    }

    public function test_expired_cancelled_and_foreign_personal_proposals_cannot_execute(): void
    {
        $cancelled = $this->prepare($this->selection());
        $this->decide($cancelled, 'cancel')->assertOk()->assertJsonPath('status', 'cancelled');
        $this->decide($cancelled)->assertOk();
        $expired = $this->prepare($this->selection());
        $this->travel(6)->minutes();
        $this->decide($expired)->assertOk()->assertJsonPath('status', 'expired');
        $this->user = 43;
        $this->getJson(self::API.'/'.$cancelled['id'])->assertNotFound();
        $this->assertSame([], $this->writes);
    }

    public function test_ambiguous_draft_is_never_retried_and_blocks_identified_legacy_native_writers(): void
    {
        $op = $this->prepare($this->selection());
        $this->lose = true;
        $unknown = $this->decide($op)->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result.control_hold_active', true)->json();
        $this->decide($op)->assertOk();
        $this->postJson(self::API.'/'.$op['id'].'/reconcile')->assertOk()->assertJsonPath('status', 'unknown');
        $other = $this->prepare($this->selection());
        $this->decide($other)->assertConflict();
        $legacy = new OzonClient('555', 'isolated-private-key');
        $this->assertNull($legacy->post('/v1/draft/direct/create', ['cluster_info' => []]));
        $this->assertCount(1, $this->writes);
        $this->assertNull($unknown['native_draft_id']);
    }

    public function test_partial_or_changed_accepted_composition_cannot_reach_supply_creation(): void
    {
        $draft = $this->draft();
        foreach (['partial', 'quantity'] as $case) {
            $this->availability = $case === 'partial' ? 'PARTIAL_AVAILABLE' : 'FULL_AVAILABLE';
            $this->acceptedQty = $case === 'quantity' ? 4 : 5;
            $this->postJson(self::API.'/'.$draft['id'].'/slots', ['warehouse_id' => '701', 'date_from' => '2026-11-20', 'date_to' => '2026-11-20'])->assertConflict();
        }
        $this->assertCount(1, $this->writes);
    }

    public function test_actual_warehouse_time_or_items_mismatch_is_not_success_and_keeps_the_account_hold(): void
    {
        $draft = $this->draft();
        $supply = $this->prepare($this->supplySelection($draft));
        $this->actualWarehouse = 702;
        $this->decide($supply)->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result.fields_verified', false)->assertJsonPath('result.control_hold_active', true);
        $this->actualWarehouse = 701;
        $this->actualQty = 4;
        $this->postJson(self::API.'/'.$supply['id'].'/reconcile')->assertOk()->assertJsonPath('status', 'unknown');
        $this->actualQty = 5;
        $this->actualFrom = '2026-11-20T05:00:00Z';
        $this->postJson(self::API.'/'.$supply['id'].'/reconcile')->assertOk()->assertJsonPath('status', 'unknown');
        $this->actualFrom = '2026-11-20T06:00:00Z';
        $this->postJson(self::API.'/'.$supply['id'].'/reconcile')->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertCount(2, $this->writes);
    }

    public function test_a_lost_supply_response_can_read_the_actual_order_but_cannot_claim_acknowledgement(): void
    {
        $draft = $this->draft();
        $supply = $this->prepare($this->supplySelection($draft));
        $this->lose = true;
        $this->decide($supply)->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result.submission_acknowledged', false)->assertJsonPath('native_order_id', '5001');
        $this->decide($supply)->assertOk();
        $this->assertCount(2, $this->writes);
    }

    public function test_known_draft_and_plan_receipts_prevent_new_native_creations(): void
    {
        $draft = $this->draft();
        $repeat = $this->prepare($this->selection());
        $this->decide($repeat)->assertConflict();
        $this->assertCount(1, $this->writes);
        $supply = $this->prepare($this->supplySelection($draft));
        $this->decide($supply)->assertOk()->assertJsonPath('status', 'succeeded');
        $legacy = new OzonClient('555', 'isolated-private-key');
        $this->assertNull($legacy->post('/v2/draft/supply/create', ['draft_id' => 77]));
        $this->assertCount(2, $this->writes);
    }

    public function test_pending_draft_only_polls_and_an_admin_stop_does_not_disable_readback(): void
    {
        $this->draftStatus = 'IN_PROGRESS';
        $op = $this->prepare($this->selection());
        $this->decide($op)->assertOk()->assertJsonPath('status', 'verifying');
        config(['services.manager.ozon_fbo_enabled' => false]);
        $this->draftStatus = 'SUCCESS';
        $this->postJson(self::API.'/'.$op['id'].'/reconcile')->assertOk()->assertJsonPath('status', 'succeeded');
        $this->postJson(self::API.'/prepare', ['integration_id' => 62251, 'selection' => $this->selection(), 'context_fingerprint' => str_repeat('a', 64)])->assertStatus(503);
        $this->assertCount(1, $this->writes);
    }

    public function test_a_crash_after_supply_send_keeps_readback_possible_without_replaying(): void
    {
        $draft = $this->draft();
        $supply = $this->prepare($this->supplySelection($draft));
        $model = ManagerOzonFboOperation::findOrFail($supply['id']);
        $account = ManagerFboPublicationGuard::key('account', '555');
        $resource = ManagerFboPublicationGuard::key('draft', '555', '77');
        foreach ([$account, $resource] as $key) {
            DB::table('manager_fbo_resource_holds')->insert(['resource_key' => $key, 'operation_id' => $model->id, 'created_at' => now()]);
        }
        $model->update(['status' => 'executing', 'native_draft_id' => '77', 'execution_started_at' => now()->subMinutes(4), 'native_started_at' => now()->subMinutes(4)]);
        $this->orderCreated = true;
        $this->postJson(self::API.'/'.$supply['id'].'/reconcile')->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('native_order_id', '5001')
            ->assertJsonPath('result.submission_acknowledged', false)->assertJsonPath('result.control_hold_active', true);
        $this->assertCount(1, $this->writes);
    }

    public function test_existing_local_materializer_and_queued_supply_cannot_create_a_second_draft_for_the_same_plan(): void
    {
        $this->draft();
        try {
            (new OzonPlanMaterializationService)->materialize($this->plan);
            $this->fail('Materializer bypassed the chat receipt');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('исполнение FBO в чате', json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
        $supply = Supply::create(['integration_id' => 62251, 'auto_supply_plan_id' => $this->plan->id,
            'supply_type' => Supply::TYPE_FBO, 'supply_method' => Supply::METHOD_DIRECT,
            'cluster_id' => '1', 'status' => Supply::STATUS_DRAFT]);
        try {
            app(SupplyService::class)->createOzonDraft($supply);
            $this->fail('Queued local supply bypassed the chat receipt');
        } catch (OzonPreconditionException $e) {
            $this->assertStringContainsString('исполнение FBO в чате', $e->getMessage());
        }
        $this->assertCount(1, $this->writes);
    }

    public function test_immutable_source_corruption_is_rejected(): void
    {
        $op = $this->prepare($this->selection());
        $model = ManagerOzonFboOperation::findOrFail($op['id']);
        $plan = $model->plan;
        $plan['items'][0]['quantity'] = 99;
        $model->update(['plan' => $plan]);
        $this->decide($op)->assertConflict();
        $this->assertSame([], $this->writes);
    }
}
