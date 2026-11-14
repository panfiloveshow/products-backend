<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Jobs\RunManagerOzonPackingGroup;
use App\Services\Manager\ManagerOzonPackingService;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManagerOzonPackingOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const API = '/api/manager/ozon-packing';
    private const WIDE = '9007199254740993';
    private array $permissions = ['auto_supply.view', 'auto_supply.create', 'integrations.view'];
    private int $user = 42;
    private mixed $personal = false;
    private string $key = 'native-private-key';
    private array $postings = [];
    private int $writes = 0;
    private array $requests = [];
    private array $boundaryErrors = [];
    private array $loseFor = [];
    private array $statusFor = [];
    private string $malformed = '';
    private bool $readUnavailable = false;
    private $afterGet = null;
    private $afterWrite = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true,
            'services.manager.fbs_writes_enabled' => true, 'services.manager.ozon_packing_enabled' => true, 'services.manager.ozon_packing_outbox_enabled' => true]);
        Cache::flush(); Queue::fake(); Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('service-session'));
        $this->withToken('human-session')->withHeader('X-Sellico-Workspace', '8');
        foreach ([62001, 62002] as $id) Integration::factory()->ozon()->create(['id' => $id, 'work_space_id' => 8]);
        $this->postings = ['100-1-1' => $this->posting('100-1-1', 701, [[self::WIDE, 2], ['302', 1]]), '200-1-1' => $this->posting('200-1-1', 702, [['501', 2]])];
        Http::fake(function ($r) {
            try { return $this->respond($r); }
            catch (\PHPUnit\Framework\AssertionFailedError $e) { $this->boundaryErrors[] = $e->getMessage(); return Http::response([], 599); }
        });
    }

    protected function tearDown(): void
    {
        try { $this->assertSame([], $this->boundaryErrors, 'Native-boundary assertion must not be swallowed by a network failure handler.'); }
        finally { parent::tearDown(); }
    }

    private function respond($r)
    {
            $url = $r->url();
            if ($url === 'https://crm.test/api/user') return Http::response(['id' => $this->user, ...($this->personal === 'missing' ? [] : ['is_service_account' => $this->personal])]);
            if ($url === 'https://crm.test/api/workspaces/8/user-permissions') return Http::response(['user_id' => $this->user, 'workspace_id' => 8, 'permissions' => $this->permissions]);
            if ($url === 'https://crm.test/api/get-integrations/8') return Http::response(array_map(fn ($id) => ['id' => $id, 'work_space_id' => 8, 'status' => 'active', 'type' => 'ozon', 'name' => 'Native Ozon', 'api_key' => $this->key, 'client_id' => '555'], [62001, 62002]));
            $this->assertSame('POST', $r->method()); $this->assertTrue($r->hasHeader('Api-Key', [$this->key])); $this->assertTrue($r->hasHeader('Client-Id', ['555']));
            if ($url === 'https://api-seller.ozon.ru/v3/posting/fbs/get') {
                if ($this->readUnavailable && $this->writes && ($this->postings[$r['posting_number']]['status'] ?? '') !== 'awaiting_packaging') return Http::response([], 503);
                $number = $r['posting_number']; $row = $this->postings[$number] ?? null;
                if ($this->afterGet) ($this->afterGet)($number);
                return Http::response($row ? ['result' => $row] : [], $row ? 200 : 404);
            }
            if ($url === 'https://api-seller.ozon.ru/v2/carriage/delivery/list') {
                $ready = array_values(array_filter($this->postings, fn ($p) => $p['status'] === 'awaiting_deliver' && $p['delivery_method']['id'] === 301));
                return Http::response(['has_next' => false, 'cursor' => '', 'methods' => [['delivery_method_id' => 301, 'warehouse_id' => 101, 'delivery_method_name' => 'Ozon FBS',
                    'departure_date' => $r['filter']['departure_date'], 'integration_type' => 'ozon', 'is_rfbs' => false, 'has_entrusted_acceptance' => false, 'errors' => [],
                    'dropoff_address' => 'Пункт Ozon', 'dropoff_point_id' => 401, 'carriages' => [['id' => 0, 'all_blr_traceable' => false, 'available_actions' => ['create'], 'postings_count' => count($ready), 'status' => 'new', 'pickup_fee' => null]]]]]);
            }
            if ($url === 'https://api-seller.ozon.ru/v4/posting/fbs/list') return Http::response(['has_next' => false, 'cursor' => '', 'postings' => array_values(array_filter($this->postings, fn ($p) => $p['status'] === 'awaiting_deliver' && $p['delivery_method']['id'] === 301))]);
            if ($url === 'https://api-seller.ozon.ru/v4/posting/fbs/ship') {
                $this->writes++; $number = $r['posting_number']; $this->requests[] = $r->data(); $this->assertSame(['packages', 'posting_number', 'with'], (function ($v) { sort($v); return $v; })(array_keys($r->data())));
                $this->assertSame(['additional_data' => false], $r['with']); $original = $this->postings[$number]; $ids = [];
                if ($this->statusFor[$number] ?? false) return Http::response(['message' => 'native-private-refusal'], $this->statusFor[$number]);
                foreach ($r['packages'] as $i => $package) {
                    $id = preg_replace('/-[0-9]+$/', '-'.($i + 1), $number); $copy = $original; $copy['posting_number'] = $id; $copy['parent_posting_number'] = $i ? $number : '';
                    $copy['status'] = 'awaiting_deliver'; $copy['substatus'] = 'posting_not_in_carriage'; $copy['available_actions'] = ['cancel', 'label_download'];
                    $copy['products'] = array_map(function ($p) use ($original) {
                        $this->assertIsInt($p['product_id']); $this->assertSame(['product_id', 'quantity'], array_keys($p));
                        $item = collect($original['products'])->first(fn ($r) => $r['sku'] === $p['product_id']); $this->assertNotNull($item); $item['quantity'] = $p['quantity']; return $item;
                    }, $package['products']);
                    $this->postings[$id] = $copy; $ids[] = $id;
                }
                if ($this->malformed === 'wrong_order') $this->postings[$ids[0]]['order_id'] = 999;
                if ($this->malformed === 'wrong_warehouse') $this->postings[$ids[0]]['delivery_method']['warehouse_id'] = 999;
                if ($this->malformed === 'wrong_quantity') $this->postings[$ids[0]]['products'][0]['quantity']++;
                if ($this->malformed === 'wrong_parent') $this->postings[$ids[1]]['parent_posting_number'] = '100-9-1';
                if ($this->malformed === 'ship_failed') { $this->postings[$ids[0]]['substatus'] = 'ship_failed'; $this->postings[$ids[0]]['status'] = 'awaiting_packaging'; }
                if ($this->malformed === 'not_ready') $this->postings[$ids[0]]['status'] = 'awaiting_packaging';
                if ($this->afterWrite) ($this->afterWrite)($number);
                if ($this->loseFor[$number] ?? false) throw new \RuntimeException('Lost native receipt');
                if ($this->malformed === 'duplicate_ids') $ids = array_fill(0, count($ids), $ids[0]);
                if ($this->malformed === 'wrong_count') array_pop($ids);
                if ($this->malformed === 'non_json') return Http::response('not JSON', 200);
                return Http::response(['result' => array_reverse($ids)]);
            }
            $this->fail('Unexpected native endpoint: '.$url);
    }

    private function posting(string $number, int $order, array $items): array
    {
        return ['posting_number' => $number, 'order_id' => $order, 'parent_posting_number' => '', 'status' => 'awaiting_packaging', 'substatus' => 'posting_created',
            'in_process_at' => now()->subHour()->utc()->toRfc3339String(), 'shipment_date' => now()->addDay()->utc()->toRfc3339String(),
            'delivery_method' => ['id' => 201, 'warehouse_id' => 101, 'warehouse' => 'Склад продавца'], 'is_express' => false, 'is_multibox' => false,
            'integration_type_flow' => 'ozon', 'require_blr_traceable_attrs' => false, 'available_actions' => ['ship', 'cancel', 'ship_async'],
            'requirements' => array_fill_keys(['products_requiring_gtd', 'products_requiring_jw_uin', 'products_requiring_country', 'products_requiring_rnpt', 'products_requiring_mandatory_mark', 'products_requiring_change_country', 'products_requiring_imei', 'products_requiring_weight'], []),
            'products' => array_map(fn ($r) => ['sku' => (int) $r[0], 'quantity' => $r[1], 'offer_id' => 'SKU-'.$r[0], 'name' => 'Товар '.$r[0], 'has_imei' => false, 'is_weight_needed' => false, 'is_blr_traceable' => false, 'is_marketplace_buyout' => false], $items),
            'customer' => ['phone' => 'buyer-private-phone'], 'financial_data' => ['private' => 'private-finance']];
    }

    private function selection(): array
    {
        return [['posting_number' => '100-1-1', 'packages' => [
            ['products' => [['sku' => self::WIDE, 'quantity' => 1]]], ['products' => [['sku' => self::WIDE, 'quantity' => 1]]], ['products' => [['sku' => '302', 'quantity' => 1]]]]],
            ['posting_number' => '200-1-1', 'packages' => [['products' => [['sku' => '501', 'quantity' => 2]]]]]];
    }

    private function contract(string $name, array $data): void
    {
        if (! $dir = getenv('SELYA_OZON_PACKING_CONTRACT_DIR')) return;
        if (! is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($dir.'/'.$name.'.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($dir.'/'.$name.'.json', 0600);
    }

    public function test_exports_real_php_group_dtos_and_partial_results_without_retiming(): void
    {
        $initial = $this->postings;
        $this->contract('context-custom', $this->postJson(self::API.'/context', ['integration_id' => 62001, 'postings' => $this->selection()])->assertOk()->json());
        $a = $this->prepare(); $this->contract('proposal-custom', $a); $this->contract('queued', $this->approve($a)); $this->runGroup($a);
        $this->contract('done-custom', $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'succeeded')->json());

        $this->postings = $initial;
        $this->contract('context-one', $this->postJson(self::API.'/one-package/context', ['integration_id' => 62001, 'posting_numbers' => array_keys($initial)])->assertOk()->json());
        $b = $this->prepareOne(array_keys($initial)); $this->contract('proposal-one', $b); $this->approve($b); $this->runGroup($b);
        $this->contract('done-one', $this->getJson(self::API.'/'.$b['id'])->assertOk()->assertJsonPath('status', 'succeeded')->json());

        $this->postings = $initial; $c = $this->prepare(); $this->approve($c); $this->readUnavailable = true; $this->runGroup($c);
        $this->contract('verifying', $this->getJson(self::API.'/'.$c['id'])->assertOk()->assertJsonPath('status', 'verifying')->json());
        $this->readUnavailable = false; $this->postJson(self::API.'/'.$c['id'].'/reconcile', [])->assertOk()->assertJsonPath('status', 'succeeded');

        $this->postings = $initial; $d = $this->prepare(); $this->contract('proposal-stopped', $d); $this->approve($d);
        $this->contract('stopped', $this->postJson(self::API.'/'.$d['id'].'/cancel', ['fingerprint' => $d['fingerprint']])->assertOk()->assertJsonPath('status', 'cancelled')->json());

        $this->postings = $initial; $e = $this->prepare(); $this->contract('proposal-partial', $e); $this->approve($e); $this->malformed = 'ship_failed'; $this->runGroup($e);
        $this->contract('partial', $this->getJson(self::API.'/'.$e['id'])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.verified_package_count', 2)->json()); $this->malformed = '';

        $this->postings = []; $numbers = [];
        for ($i = 1; $i <= 100; $i++) { $n = '300-'.$i.'-1'; $numbers[] = $n; $this->postings[$n] = $this->posting($n, 1000 + $i, [[(string) (2000 + $i), 2]]); }
        $many = $this->prepareOne($numbers); $this->contract('proposal-100', $many); $this->approve($many); $this->runGroup($many);
        $this->contract('done-100', $this->getJson(self::API.'/'.$many['id'])->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.succeeded_count', 100)->json());

        $this->postings = ['400-1-1' => $this->posting('400-1-1', 9001, [['99', 100]])];
        $large = $this->prepare([['posting_number' => '400-1-1', 'packages' => array_fill(0, 100, ['products' => [['sku' => '99', 'quantity' => 1]]])]]);
        $this->contract('proposal-100-packages', $large); $this->approve($large); $this->runGroup($large);
        $this->contract('done-100-packages', $this->getJson(self::API.'/'.$large['id'])->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.verified_package_count', 100)->json());

        $this->postings = $initial; $f = $this->prepare(); $this->contract('proposal-refused', $f); $this->approve($f); $this->statusFor['100-1-1'] = 400; $this->runGroup($f);
        $this->contract('refused', $this->getJson(self::API.'/'.$f['id'])->assertOk()->assertJsonPath('status', 'failed')->json()); $this->statusFor = [];

        $this->postings = $initial; $g = $this->prepare(); $this->contract('proposal-unknown', $g); $this->approve($g); $this->loseFor['100-1-1'] = true; $this->runGroup($g);
        $this->contract('unknown', $this->getJson(self::API.'/'.$g['id'])->assertOk()->assertJsonPath('status', 'unknown')->json());
        $this->assertSame(110, $this->writes);
    }

    public function test_context_checks_complete_group_and_package_conservation_without_a_native_write_or_job(): void
    {
        $response = $this->postJson(self::API.'/context', ['integration_id' => 62001, 'postings' => $this->selection()]);
        $this->assertSame(200, $response->status(), $response->content());
        $context = $response->assertOk()
            ->assertJsonPath('group.source_count', 2)->assertJsonPath('group.package_count', 4)->assertJsonPath('group.total_units', 5)
            ->assertJsonPath('completeness', 'complete_target')->json();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $context['context_fingerprint']);
        $this->assertContains(self::WIDE, array_column($context['group']['sources'][0]['posting']['items'], 'marketplace_product_id'));
        $this->assertStringNotContainsString('private', json_encode($context));
        $this->assertSame(0, $this->writes); Queue::assertNothingPushed();
    }

    private function prepare(?array $selection = null): array
    {
        $selection ??= $this->selection(); $context = $this->postJson(self::API.'/context', ['integration_id' => 62001, 'postings' => $selection])->assertOk()->json();
        return $this->postJson(self::API.'/prepare', ['integration_id' => 62001, 'postings' => $selection, 'context_fingerprint' => $context['context_fingerprint']])->assertOk()->json();
    }

    public function test_personal_approval_queues_once_and_duplicate_workers_never_replay_two_native_source_commands(): void
    {
        $a = $this->prepare(); $this->assertSame('awaiting_confirmation', $a['status']); $this->assertCount(4, $a['required_confirmations']);
        $checks = array_fill_keys(array_column($a['required_confirmations'], 'key'), true);
        $this->assertSame(0, $this->writes); Queue::assertNothingPushed();
        $this->postJson(self::API.'/'.$a['id'].'/approve', ['fingerprint' => $a['fingerprint'], 'confirmations' => []])->assertUnprocessable();
        $this->postJson(self::API.'/'.$a['id'].'/approve', ['fingerprint' => $a['fingerprint'], 'confirmations' => $checks])->assertOk()->assertJsonPath('status', 'executing');
        $this->assertSame(0, $this->writes);
        $outbox = DB::table('manager_ozon_packing_outbox')->where('operation_id', $a['id'])->first();
        $this->assertNotNull($outbox); $this->assertStringNotContainsString('human-session', $outbox->payload_cipher);
        // Exercise the dispatcher too: a recent afterCommit push is deduplicated.
        app(ManagerOzonPackingService::class)->dispatchApproved($a['id']);
        Queue::assertPushed(RunManagerOzonPackingGroup::class, 1);
        $job = Queue::pushed(RunManagerOzonPackingGroup::class)->first(); $this->assertStringNotContainsString('human-session', serialize($job));
        $job->handle(app(ManagerOzonPackingService::class));
        $done = $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.succeeded_count', 2)
            ->assertJsonPath('result.fields_verified', true)->assertJsonPath('result.control_hold_active', false)->json();
        $this->assertSame(2, $this->writes); $this->assertSame(0, DB::table('manager_fbs_resource_holds')->count());
        $this->assertCount(3, $done['result']['items'][0]['receipt_posting_numbers']); $this->assertCount(3, $done['result']['items'][0]['observed_postings']);
        $this->assertTrue($done['result']['items'][0]['composition_verified']);
        $this->assertNull($done['physical_status']); $this->assertFalse($done['result']['actual_cost_verified']);
        $job->handle(app(ManagerOzonPackingService::class));
        $this->postJson(self::API.'/'.$a['id'].'/approve', ['fingerprint' => $a['fingerprint'], 'confirmations' => $checks])->assertOk();
        $this->postJson(self::API.'/'.$a['id'].'/reconcile', [])->assertOk();
        $this->assertSame(2, $this->writes); Queue::assertPushed(RunManagerOzonPackingGroup::class, 1);
        $this->assertStringNotContainsString('private', json_encode([$a, $done]));
    }

    private function approve(array $a): array
    {
        return $this->postJson(self::API.'/'.$a['id'].'/approve', ['fingerprint' => $a['fingerprint'], 'confirmations' => array_fill_keys(array_column($a['required_confirmations'], 'key'), true)])->assertOk()->json();
    }
    private function runGroup(array $a): RunManagerOzonPackingGroup
    {
        app(ManagerOzonPackingService::class)->dispatchApproved($a['id']);
        $job = Queue::pushed(RunManagerOzonPackingGroup::class)->first(fn ($j) => $j->operationId === $a['id']); $this->assertNotNull($job);
        $job->handle(app(ManagerOzonPackingService::class)); return $job;
    }

    public function test_package_allocations_reject_missing_extra_duplicate_fractional_and_changed_source_quantities(): void
    {
        $base = $this->selection(); $cases = [];
        $v = $base; $v[0]['packages'][0]['products'][0]['quantity'] = 2; $cases[] = $v;
        $v = $base; unset($v[0]['packages'][2]); $cases[] = $v;
        $v = $base; $v[0]['packages'][0]['products'][0]['sku'] = '123'; $cases[] = $v;
        $v = $base; $v[0]['packages'][0]['products'][] = $v[0]['packages'][0]['products'][0]; $cases[] = $v;
        $v = $base; $v[0]['packages'][0]['products'][0]['quantity'] = 1.5; $cases[] = $v;
        $v = $base; $v[0]['packages'][0]['products'][0]['quantity'] = 0; $cases[] = $v;
        $v = $base; $v[0]['packages'][0]['products'][0]['native_url'] = 'https://untrusted.test'; $cases[] = $v;
        $v = $base; $v[] = $v[0]; $cases[] = $v;
        foreach ($cases as $selection) $this->postJson(self::API.'/context', ['integration_id' => 62001, 'postings' => $selection])->assertUnprocessable();
        $this->postings['100-1-1']['products'][0]['quantity']++;
        $this->postJson(self::API.'/context', ['integration_id' => 62001, 'postings' => $base])->assertUnprocessable();
        $this->assertSame(0, $this->writes); Queue::assertNothingPushed();
    }

    public function test_unknown_metadata_flags_missing_order_and_non_ready_sources_cannot_be_prepared(): void
    {
        $original = $this->postings['100-1-1'];
        foreach (['is_multibox', 'is_express', 'require_blr_traceable_attrs'] as $key) {
            $this->postings['100-1-1'] = $original; unset($this->postings['100-1-1'][$key]);
            $this->postJson(self::API.'/context', ['integration_id' => 62001, 'postings' => $this->selection()])->assertUnprocessable();
        }
        $this->postings['100-1-1'] = $original; $this->postings['100-1-1']['requirements']['products_requiring_imei'] = [self::WIDE];
        $this->postJson(self::API.'/context', ['integration_id' => 62001, 'postings' => $this->selection()])->assertUnprocessable();
        $this->postings['100-1-1'] = $original; unset($this->postings['100-1-1']['order_id']);
        $this->postJson(self::API.'/context', ['integration_id' => 62001, 'postings' => $this->selection()])->assertUnprocessable();
        $this->postings['100-1-1'] = $original; $this->postings['100-1-1']['status'] = 'awaiting_deliver';
        $this->postJson(self::API.'/context', ['integration_id' => 62001, 'postings' => $this->selection()])->assertUnprocessable();
        $this->assertSame(0, $this->writes);
    }

    public function test_owner_body_checks_fingerprint_expiry_and_personal_scope_protect_approval(): void
    {
        $a = $this->prepare(); $body = ['fingerprint' => $a['fingerprint'], 'confirmations' => array_fill_keys(array_column($a['required_confirmations'], 'key'), true)];
        $this->user = 43; $this->getJson(self::API.'/'.$a['id'])->assertNotFound();
        $this->postJson(self::API.'/'.$a['id'].'/approve', $body)->assertNotFound(); $this->user = 42;
        $this->postJson(self::API.'/'.$a['id'].'/approve', $body + ['user_id' => 42])->assertStatus(400);
        foreach ([[], [ManagerOzonPackingService::CHECKS[0] => true], array_fill_keys(ManagerOzonPackingService::CHECKS, false), array_fill_keys([...ManagerOzonPackingService::CHECKS, 'extra'], true)] as $checks)
            $this->postJson(self::API.'/'.$a['id'].'/approve', ['fingerprint' => $a['fingerprint'], 'confirmations' => $checks])->assertUnprocessable();
        $this->postJson(self::API.'/'.$a['id'].'/approve', array_replace($body, ['fingerprint' => str_repeat('f', 64)]))->assertStatus(409);
        $this->personal = 'missing'; $this->postJson(self::API.'/'.$a['id'].'/approve', $body)->assertStatus(503); $this->personal = false;
        $this->travel(301)->seconds(); $this->postJson(self::API.'/'.$a['id'].'/approve', $body)->assertOk()->assertJsonPath('status', 'expired'); $this->travelBack();
        $this->assertSame(0, $this->writes); Queue::assertNothingPushed();
    }

    public function test_changed_group_source_blocks_every_native_command_even_when_other_sources_are_still_ready(): void
    {
        $a = $this->prepare(); $this->approve($a); $this->postings['200-1-1']['products'][0]['quantity']++;
        $this->runGroup($a);
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.not_attempted_count', 2);
        $this->assertSame(0, $this->writes); $this->assertSame(0, DB::table('manager_fbs_resource_holds')->count());
        $this->assertNull(DB::table('manager_ozon_packing_outbox')->where('operation_id', $a['id'])->value('payload_cipher'));
    }

    public function test_a_lost_receipt_stops_later_sources_and_matching_state_never_authorizes_a_repeat(): void
    {
        $a = $this->prepare(); $this->approve($a); $this->loseFor['100-1-1'] = true; $job = $this->runGroup($a);
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result.unknown_count', 1)->assertJsonPath('result.not_attempted_count', 1);
        $this->assertSame(1, $this->writes); $this->assertSame(2, DB::table('manager_fbs_resource_holds')->where('operation_id', $a['id'])->count());
        $job->handle(app(ManagerOzonPackingService::class));
        $this->postJson(self::API.'/'.$a['id'].'/reconcile', [])->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result.fields_verified', false);
        $this->approve($a); $this->assertSame(1, $this->writes);
        $remaining = $this->prepare([$this->selection()[1]]); $this->assertSame('awaiting_confirmation', $remaining['status']);
    }

    public function test_result_reports_actual_resource_holds_after_a_control_failure_without_replay(): void
    {
        $a = $this->prepare(); $this->approve($a); $this->loseFor['100-1-1'] = true; $job = $this->runGroup($a);
        DB::table('manager_fbs_resource_holds')->where('operation_id', $a['id'])->delete();
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'unknown')
            ->assertJsonPath('result.control_hold_active', false)->assertJsonPath('result.items.0.control_hold_active', false);
        $job->handle(app(ManagerOzonPackingService::class)); $this->postJson(self::API.'/'.$a['id'].'/reconcile', [])->assertOk()->assertJsonPath('status', 'unknown');
        $this->assertSame(1, $this->writes);
    }

    public function test_shared_order_hold_remains_for_the_pending_source_without_marking_a_completed_source_as_active(): void
    {
        $this->postings['200-1-1']['order_id'] = 701; $a = $this->prepare(); $this->approve($a); $observed = null;
        $this->afterGet = function ($number) use ($a, &$observed) {
            if ($this->writes === 1 && $number === '200-1-1') $observed = app(ManagerOzonPackingService::class)->get(
                new \App\Services\Manager\ManagerIdentity(8, 42, $this->permissions, true), $a['id']);
        };
        $this->runGroup($a); $this->assertNotNull($observed);
        $this->assertSame('succeeded', $observed['result']['items'][0]['status']); $this->assertFalse($observed['result']['items'][0]['control_hold_active']);
        $this->assertSame('pending', $observed['result']['items'][1]['status']); $this->assertTrue($observed['result']['items'][1]['control_hold_active']);
        $this->assertTrue($observed['result']['control_hold_active']); $this->assertSame(2, $this->writes);
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'succeeded');
    }

    public function test_downgrade_refuses_to_delete_immutable_packing_history(): void
    {
        $a = $this->prepare(); $migration = require database_path('migrations/2026_11_06_220000_create_manager_ozon_packing_operations.php');
        try { $migration->down(); $this->fail('History must prevent downgrade.'); }
        catch (\RuntimeException $e) { $this->assertSame('Ozon packing history prevents downgrade', $e->getMessage()); }
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'awaiting_confirmation');
        $this->assertSame(0, $this->writes); Queue::assertNothingPushed();
    }

    public function test_known_receipts_recover_all_packages_by_reading_while_writes_are_stopped(): void
    {
        $a = $this->prepare(); $this->approve($a); $this->readUnavailable = true; $this->runGroup($a);
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'verifying')->assertJsonPath('result.verifying_count', 2);
        $this->readUnavailable = false; config(['services.manager.ozon_packing_enabled' => false, 'services.manager.fbs_writes_enabled' => false]);
        $this->postJson(self::API.'/'.$a['id'].'/reconcile', [])->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.verified_package_count', 4);
        $this->assertSame(2, $this->writes); $this->assertSame(0, DB::table('manager_fbs_resource_holds')->count());
    }

    public static function nativeErrors(): array
    {
        return array_map(fn ($v) => [$v, in_array($v, [400, 401, 403, 404, 422], true) ? 'failed' : 'unknown'], [400, 401, 403, 404, 422, 409, 429, 500, 503]);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('nativeErrors')]
    public function test_native_refusals_and_ambiguous_errors_have_per_source_results_without_replay(int $status, string $expected): void
    {
        $a = $this->prepare(); $this->approve($a); $this->statusFor['100-1-1'] = $status; $job = $this->runGroup($a);
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', $expected)->assertJsonPath('result.not_attempted_count', 1)->assertJsonPath('result.items.0.status', $expected);
        $this->assertSame(1, $this->writes);
        $this->assertSame($expected === 'unknown' ? 2 : 0, DB::table('manager_fbs_resource_holds')->where('operation_id', $a['id'])->count());
        $job->handle(app(ManagerOzonPackingService::class)); $this->postJson(self::API.'/'.$a['id'].'/reconcile', [])->assertOk(); $this->assertSame(1, $this->writes);
    }

    public static function malformedReceipts(): array { return array_map(fn ($s) => [$s], ['duplicate_ids', 'wrong_count', 'non_json', 'wrong_order', 'wrong_warehouse', 'wrong_quantity', 'wrong_parent']); }
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedReceipts')]
    public function test_inconsistent_receipts_and_readback_do_not_claim_the_approved_package_result(string $mode): void
    {
        $a = $this->prepare(); $this->approve($a); $this->malformed = $mode; $job = $this->runGroup($a);
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result.not_attempted_count', 1)->assertJsonPath('result.fields_verified', false);
        $job->handle(app(ManagerOzonPackingService::class)); $this->assertSame(1, $this->writes);
    }

    public function test_ship_failed_can_have_verified_child_packages_and_is_reported_as_partial(): void
    {
        $a = $this->prepare(); $this->approve($a); $this->malformed = 'ship_failed'; $this->runGroup($a);
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.group_outcome', 'partial')
            ->assertJsonPath('result.verified_package_count', 2)->assertJsonPath('result.failed_count', 1)->assertJsonPath('result.not_attempted_count', 1);
        $this->assertSame(1, $this->writes); $this->assertSame(0, DB::table('manager_fbs_resource_holds')->count());
    }

    public function test_queued_contexts_expire_and_are_wiped_even_when_all_execution_flags_are_off(): void
    {
        $a = $this->prepare(); $this->approve($a); $this->travel(241)->seconds();
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'executing');
        config(['services.manager.fbs_writes_enabled' => false, 'services.manager.ozon_packing_enabled' => false, 'services.manager.ozon_packing_outbox_enabled' => false]);
        $this->travel(61)->seconds(); $this->assertSame(0, app(ManagerOzonPackingService::class)->dispatchPending()); $this->travelBack();
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.not_attempted_count', 2);
        $this->assertNull(DB::table('manager_ozon_packing_outbox')->where('operation_id', $a['id'])->value('payload_cipher')); $this->assertSame(0, $this->writes);
    }

    public function test_two_outbox_deliveries_have_distinct_claims_but_only_one_can_execute_the_approved_group(): void
    {
        $a = $this->prepare(); $this->approve($a); app(ManagerOzonPackingService::class)->dispatchApproved($a['id']);
        $this->travel(31)->seconds(); $this->assertTrue(app(ManagerOzonPackingService::class)->dispatchApproved($a['id']));
        $jobs = Queue::pushed(RunManagerOzonPackingGroup::class)->values(); $this->assertCount(2, $jobs); $this->assertNotSame($jobs[0]->claimId, $jobs[1]->claimId);
        $jobs[1]->handle(app(ManagerOzonPackingService::class)); $jobs[0]->handle(app(ManagerOzonPackingService::class)); $jobs[0]->failed(null); $this->travelBack();
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'succeeded'); $this->assertSame(2, $this->writes);
    }

    public function test_a_recording_failure_after_native_acceptance_remains_unknown_and_never_replays(): void
    {
        $a = $this->prepare(); $this->approve($a); $interrupt = true;
        DB::listen(function ($query) use (&$interrupt) {
            if ($interrupt && str_starts_with($query->sql, 'insert into "manager_ozon_packing_events"') && in_array('receipt_verifying', $query->bindings, true)) { $interrupt = false; throw new \RuntimeException('Interrupted receipt transaction'); }
        });
        $job = $this->runGroup($a); $this->assertFalse($interrupt);
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result.not_attempted_count', 1);
        $job->handle(app(ManagerOzonPackingService::class)); $this->assertSame(1, $this->writes);
    }

    public function test_missing_child_or_tampered_child_plan_cannot_silently_change_the_selected_group(): void
    {
        $a = $this->prepare(); $this->approve($a); DB::table('manager_ozon_packing_items')->where('operation_id', $a['id'])->where('position', 1)->delete(); $this->runGroup($a); $this->assertSame(0, $this->writes);
        $b = $this->prepare(); $this->approve($b); $item = \App\Models\ManagerOzonPackingItem::where('operation_id', $b['id'])->where('position', 0)->firstOrFail();
        $p = $item->plan; $original = $p['packages'][0]['products'][0]['quantity']; $p['packages'][0]['products'][0]['quantity'] = $original + 1;
        $this->assertNotSame($item->plan, $p, 'The frozen child plan must actually change.'); $item->update(['plan' => $p]);
        $this->runGroup($b); $this->assertSame(0, $this->writes); $this->assertSame(0, DB::table('manager_fbs_resource_holds')->count());
    }

    public function test_missing_every_child_never_becomes_an_empty_success(): void
    {
        $a = $this->prepare(); $this->approve($a); DB::table('manager_ozon_packing_items')->where('operation_id', $a['id'])->delete();
        $job = $this->runGroup($a);
        $this->assertSame('failed', DB::table('manager_ozon_packing_operations')->where('id', $a['id'])->value('status'));
        $this->getJson(self::API.'/'.$a['id'])->assertStatus(409);
        $job->handle(app(ManagerOzonPackingService::class)); $this->assertSame(0, $this->writes);
        $this->assertSame(0, DB::table('manager_fbs_resource_holds')->where('operation_id', $a['id'])->count());
    }

    private function prepareOne(array $numbers): array
    {
        $context = $this->postJson(self::API.'/one-package/context', ['integration_id' => 62001, 'posting_numbers' => $numbers])->assertOk()->json();
        return $this->postJson(self::API.'/one-package/prepare', ['integration_id' => 62001, 'posting_numbers' => $numbers, 'context_fingerprint' => $context['context_fingerprint']])->assertOk()->json();
    }

    public function test_one_package_per_source_uses_native_quantities_without_model_arithmetic(): void
    {
        $a = $this->prepareOne(['100-1-1', '200-1-1']); $this->assertSame(2, $a['group']['package_count']);
        $this->assertSame([['sku' => '302', 'quantity' => 1], ['sku' => self::WIDE, 'quantity' => 2]], $a['group']['sources'][0]['packages'][0]['products']);
        $this->assertArrayNotHasKey('_before', $a['group']['sources'][0]); $this->assertArrayNotHasKey('_order_target', $a['group']['sources'][0]);
        $this->approve($a); $this->runGroup($a);
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.verified_package_count', 2);
        $this->assertSame([['product_id' => 302, 'quantity' => 1], ['product_id' => (int) self::WIDE, 'quantity' => 2]], $this->requests[0]['packages'][0]['products']);
        $this->assertSame(2, $this->writes);
    }

    public function test_one_hundred_sources_are_preserved_reviewed_and_dispatched_once_each(): void
    {
        $this->postings = []; $numbers = [];
        for ($i = 1; $i <= 100; $i++) { $number = '300-'.$i.'-1'; $numbers[] = $number; $this->postings[$number] = $this->posting($number, 1000 + $i, [[(string) (2000 + $i), 2]]); }
        $a = $this->prepareOne($numbers); $this->assertCount(100, $a['group']['sources']); $this->assertSame(200, $a['group']['total_units']);
        $this->approve($a); $job = $this->runGroup($a);
        $done = $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.succeeded_count', 100)->assertJsonPath('result.verified_package_count', 100)->json();
        $this->assertSame('300-100-1', $done['result']['items'][99]['source_posting_number']);
        $this->assertSame(100, $this->writes); $job->handle(app(ManagerOzonPackingService::class)); $this->assertSame(100, $this->writes);
    }

    public static function postReadChanges(): array { return array_map(fn ($v) => [$v], ['rights', 'owner', 'key', 'expiry', 'stop', 'personal']); }
    #[\PHPUnit\Framework\Attributes\DataProvider('postReadChanges')]
    public function test_worker_revalidates_scope_after_native_reads_before_any_source_command(string $change): void
    {
        $a = $this->prepare(); $this->approve($a); $triggered = false;
        $this->afterGet = function () use ($change, &$triggered) {
            if ($triggered) return; $triggered = true;
            match ($change) {
                'rights' => $this->permissions = ['auto_supply.view', 'integrations.view'], 'owner' => $this->user = 43, 'key' => $this->key = 'rotated-private-key',
                'expiry' => $this->travel(301)->seconds(), 'stop' => config(['services.manager.fbs_writes_enabled' => false]), 'personal' => $this->personal = 'missing',
            };
        };
        $this->runGroup($a); $this->assertTrue($triggered); $this->travelBack(); $this->assertSame(0, $this->writes);
        $this->assertSame('failed', DB::table('manager_ozon_packing_operations')->where('id', $a['id'])->value('status'));
        $this->assertSame(0, DB::table('manager_fbs_resource_holds')->count());
    }

    public function test_read_context_does_not_release_data_when_scope_is_revoked_during_source_reads(): void
    {
        $this->afterGet = function () { $this->permissions = []; };
        $this->postJson(self::API.'/context', ['integration_id' => 62001, 'postings' => $this->selection()])->assertForbidden();
        $this->assertSame(0, $this->writes); Queue::assertNothingPushed();
    }

    public function test_rights_lost_after_first_native_receipt_stop_remaining_sources_and_readback_can_finish_the_known_part(): void
    {
        $a = $this->prepare(); $this->approve($a); $this->afterWrite = function () { $this->permissions = ['auto_supply.view', 'integrations.view']; };
        $this->runGroup($a); $this->assertSame(1, $this->writes);
        config(['services.manager.fbs_writes_enabled' => false]);
        $this->postJson(self::API.'/'.$a['id'].'/reconcile', [])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.group_outcome', 'partial')
            ->assertJsonPath('result.succeeded_count', 1)->assertJsonPath('result.not_attempted_count', 1)->assertJsonPath('result.verified_package_count', 3);
        $this->assertSame(1, $this->writes);
    }

    public function test_semantically_unordered_native_actions_do_not_change_the_approved_source(): void
    {
        $a = $this->prepare(); $this->approve($a);
        foreach ($this->postings as &$p) $p['available_actions'] = array_reverse($p['available_actions']); unset($p);
        $this->runGroup($a); $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'succeeded'); $this->assertSame(2, $this->writes);
    }

    public function test_unknown_group_blocks_old_single_packing_for_a_sibling_in_the_same_seller_order(): void
    {
        $this->postings['100-9-1'] = $this->posting('100-9-1', 701, [['901', 1]]);
        $a = $this->prepare(); $this->approve($a); $this->loseFor['100-1-1'] = true; $this->runGroup($a); Cache::flush();
        $this->postJson('/api/manager/fbs/prepare', ['integration_id' => 62002, 'external_id' => '100-9-1', 'operation_type' => 'fbs_pack'])->assertStatus(409);
        $this->assertSame(1, $this->writes);
    }

    public function test_old_unknown_packing_blocks_a_new_group_for_a_different_sibling_of_the_order(): void
    {
        $this->postings['100-9-1'] = $this->posting('100-9-1', 701, [['901', 1]]); $this->loseFor['100-9-1'] = true;
        $old = $this->postJson('/api/manager/fbs/prepare', ['integration_id' => 62002, 'external_id' => '100-9-1', 'operation_type' => 'fbs_pack'])->assertOk()->json();
        $this->postJson('/api/manager/fbs-operations/'.$old['id'].'/approve', ['fingerprint' => $old['fingerprint'], 'confirmations' => ['items_and_packing_checked' => true]])->assertOk()->assertJsonPath('status', 'unknown');
        Cache::flush(); $c = $this->postJson(self::API.'/context', ['integration_id' => 62001, 'postings' => $this->selection()])->assertOk()->json();
        $this->postJson(self::API.'/prepare', ['integration_id' => 62001, 'postings' => $this->selection(), 'context_fingerprint' => $c['context_fingerprint']])->assertStatus(409);
        $this->assertSame(1, $this->writes);
    }

    public function test_unknown_packing_order_blocks_carriage_creation_for_another_ready_sibling(): void
    {
        config(['services.manager.ozon_carriages_enabled' => true]);
        $p = $this->posting('100-9-1', 701, [['901', 1]]); $p['status'] = 'awaiting_deliver'; $p['delivery_method']['id'] = 301; $this->postings['100-9-1'] = $p;
        $a = $this->prepare(); $this->approve($a); $this->loseFor['100-1-1'] = true; $this->runGroup($a); Cache::flush();
        $selection = ['operation_type' => 'fbs_carriage_create', 'delivery_method_id' => '301', 'departure_at' => now()->addDay()->toDateString().'T15:00:00+03:00',
            'all_blr_traceable' => false, 'carriage_id' => null, 'containers_count' => null];
        $c = $this->getJson('/api/manager/ozon-carriages/context?'.http_build_query(['integration_id' => 62002, ...$selection]))->assertOk()->json();
        $proposal = $this->postJson('/api/manager/ozon-carriages/prepare', ['integration_id' => 62002, 'selection' => $c['selection'], 'context_fingerprint' => $c['context_fingerprint']])->assertOk()->json();
        $this->postJson('/api/manager/ozon-carriages/'.$proposal['id'].'/approve', ['fingerprint' => $proposal['fingerprint'],
            'confirmations' => array_fill_keys(array_column($proposal['required_confirmations'], 'key'), true)])->assertStatus(409);
        $this->assertSame(1, $this->writes);
    }

    public function test_cancelling_a_queued_group_stops_both_worker_copies_before_any_native_marker(): void
    {
        $a = $this->prepare(); $this->approve($a); app(ManagerOzonPackingService::class)->dispatchApproved($a['id']);
        $job = Queue::pushed(RunManagerOzonPackingGroup::class)->first(); $this->assertNotNull($job);
        $this->postJson(self::API.'/'.$a['id'].'/cancel', ['fingerprint' => $a['fingerprint']])->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('result.not_attempted_count', 2);
        $job->handle(app(ManagerOzonPackingService::class)); $this->assertSame(0, $this->writes); $this->assertSame(0, DB::table('manager_fbs_resource_holds')->count());
    }

    public function test_stopping_during_a_source_command_preserves_its_receipt_and_cannot_start_the_next_source(): void
    {
        $a = $this->prepare(); $this->approve($a); $this->afterWrite = function () use ($a) {
            $this->postJson(self::API.'/'.$a['id'].'/cancel', ['fingerprint' => $a['fingerprint']])->assertOk()->assertJsonPath('status', 'unknown');
        };
        $this->runGroup($a);
        $this->getJson(self::API.'/'.$a['id'])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.group_outcome', 'partial')
            ->assertJsonPath('result.succeeded_count', 1)->assertJsonPath('result.not_attempted_count', 1);
        $this->assertSame(1, $this->writes); $this->assertSame(0, DB::table('manager_fbs_resource_holds')->count());
    }
    public function test_seller_stop_after_first_group_command_prevents_the_remaining_source_and_opening_never_replays_it(): void
    {
        config(['services.manager.fbs_execution_controls_enabled' => true]);
        $a = $this->prepare(); $this->approve($a);
        $plan = \App\Models\ManagerOzonPackingOperation::findOrFail($a['id'])->plan;
        $key = $plan['sources'][0]['_execution_control']['account_key'];
        $this->afterWrite = function () use ($key) {
            DB::table('manager_fbs_execution_controls')->where('account_key', $key)->update(['mode' => 'stopped', 'version' => 1]);
        };
        $job = $this->runGroup($a);
        $this->assertSame(1, $this->writes);
        $items = DB::table('manager_ozon_packing_items')->where('operation_id', $a['id'])->orderBy('position')->get();
        $this->assertNotNull($items[0]->native_started_at);
        $this->assertNull($items[1]->native_started_at);
        $this->assertSame('not_attempted', $items[1]->status);
        DB::table('manager_fbs_execution_controls')->where('account_key', $key)->update(['mode' => 'manual_only', 'version' => 2]);
        $job->handle(app(ManagerOzonPackingService::class)); $this->assertSame(1, $this->writes);
        $this->assertNull(DB::table('manager_ozon_packing_outbox')->where('operation_id', $a['id'])->value('payload_cipher'));
    }

    public function test_old_group_queued_before_stop_and_opening_cannot_start_but_a_new_personal_group_can(): void
    {
        config(['services.manager.fbs_execution_controls_enabled' => true]);
        $a = $this->prepare(); $this->assertStringNotContainsString('account_key', json_encode($a)); $this->approve($a);
        $plan = \App\Models\ManagerOzonPackingOperation::findOrFail($a['id'])->plan;
        $key = $plan['sources'][0]['_execution_control']['account_key'];
        DB::table('manager_fbs_execution_controls')->where('account_key', $key)->update(['mode' => 'stopped', 'version' => 1]);
        DB::table('manager_fbs_execution_controls')->where('account_key', $key)->update(['mode' => 'manual_only', 'version' => 2]);
        $this->runGroup($a); $this->assertSame(0, $this->writes);
        $this->assertSame(2, DB::table('manager_ozon_packing_items')->where('operation_id', $a['id'])->where('status', 'not_attempted')->count());
        $fresh = $this->prepare(); $this->approve($fresh); $this->runGroup($fresh);
        $this->assertSame(2, $this->writes);
    }

}
