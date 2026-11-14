<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\ManagerWbSupplyOperation;
use App\Services\Manager\ManagerWbSupplyService;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagerWbSupplyOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;
    private const API = '/api/manager/wb-supplies';
    private const SUPPLY = 'WB-GI-100';
    private const WIDE = '9007199254740993';
    private array $permissions = ['auto_supply.view', 'auto_supply.create', 'integrations.view', 'auto_supply.export'];
    private mixed $serviceAccount = false;
    private int $user = 42;
    private string $key = 'native-private-key';
    private array $supplies = [];
    private array $members = [];
    private array $boxes = [];
    private array $orders = [];
    private array $metadata = [];
    private array $writes = [];
    private bool $lose = false;
    private bool $readUnavailable = false;
    private bool $badCursor = false;
    private bool $pointAvailable = true;
    private string $pointType = 'sc';
    private int $writeStatus = 0;
    private $afterRead = null;
    private $afterDocument = null;
    private int $documentReads = 0;
    private bool $wrongBarcode = false;
    private bool $invalidPng = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true,
            'services.manager.fbs_writes_enabled' => true, 'services.manager.wb_supplies_enabled' => true]);
        Cache::flush(); Http::preventStrayRequests();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('service-session'));
        $this->withToken('human-session')->withHeader('X-Sellico-Workspace', '8');
        foreach ([62001, 62002] as $id) Integration::factory()->wildberries()->create(['id' => $id, 'work_space_id' => 8]);
        $this->orders = [self::WIDE => $this->order(self::WIDE), '102' => $this->order('102')];
        Http::fake(function ($r) {
            $url = $r->url(); $path = parse_url($url, PHP_URL_PATH); $method = $r->method();
            if ($url === 'https://crm.test/api/user') return Http::response(['id' => $this->user, ...($this->serviceAccount === 'missing' ? [] : ['is_service_account' => $this->serviceAccount])]);
            if ($url === 'https://crm.test/api/workspaces/8/user-permissions') return Http::response(['user_id' => $this->user, 'permissions' => $this->permissions]);
            if ($url === 'https://crm.test/api/get-integrations/8') return Http::response(array_map(fn ($id) => ['id' => $id, 'work_space_id' => 8, 'status' => 'active', 'type' => 'wildberries', 'name' => 'Native WB', 'api_key' => $this->key], [62001, 62002]));
            $this->assertTrue(str_starts_with($url, 'https://marketplace-api.wildberries.ru/'));
            $this->assertTrue($r->hasHeader('Authorization', [$this->key]));
            if ($path === '/api/v3/supplies' && $method === 'GET') return Http::response(['supplies' => array_values($this->supplies), 'next' => 0]);
            if ($path === '/api/v3/orders/new') return Http::response(['orders' => array_values(array_map(fn ($r) => $r + ['requiredMeta' => [], 'optionalMeta' => []], array_filter($this->orders, fn ($r) => ! $r['supplyId'])))]);
            if ($path === '/api/v3/orders' && $method === 'GET') {
                $this->assertSame(1000, (int) $r['limit']);
                return Http::response(['orders' => array_values($this->orders), 'next' => $this->badCursor ? -1 : 0]);
            }
            if ($path === '/api/v3/orders/status') {
                $this->assertLessThanOrEqual(1000, count($r['orders']));
                return Http::response(['orders' => array_map(function ($id) {
                    $p = $this->orders[(string) $id]; $s = $p['supplyId'];
                    return ['id' => $id, 'supplierStatus' => $s ? (($this->supplies[$s]['done'] ?? false) ? 'complete' : 'confirm') : 'new', 'wbStatus' => 'waiting'];
                }, $r['orders'])]);
            }
            if ($path === '/api/marketplace/v3/orders/meta') {
                $this->assertLessThanOrEqual(100, count($r['orders']));
                if ($this->afterRead) ($this->afterRead)();
                return Http::response(['orders' => array_map(fn ($id) => ['id' => $id, 'metaDetails' => $this->metadata[(string) $id] ?? []], $r['orders'])]);
            }
            if (preg_match('~^/api/v3/supplies/([^/]+)$~', $path, $m) && $method === 'GET') {
                if ($this->readUnavailable) return Http::response([], 503);
                return Http::response($this->supplies[$m[1]] ?? [], isset($this->supplies[$m[1]]) ? 200 : 404);
            }
            if (preg_match('~^/api/marketplace/v3/supplies/([^/]+)/order-ids$~', $path, $m)) {
                return Http::response(['orderIds' => array_map('intval', $this->members[$m[1]] ?? [])]);
            }
            if (preg_match('~^/api/v3/supplies/([^/]+)/trbx$~', $path, $m) && $method === 'GET') {
                return Http::response(['trbxes' => array_map(fn ($id) => ['id' => $id], $this->boxes[$m[1]] ?? [])]);
            }
            if (preg_match('~^/api/marketplace/v3/fbs/supplies/([^/]+)/shipping-points$~', $path, $m)) {
                $this->assertSame('Москва', $r['city']); $this->assertSame($this->pointType, $r['officeType']);
                return Http::response(['shippingPoints' => [['id' => (int) self::WIDE, 'name' => 'СЦ', 'address' => 'Москва, СЦ', 'city' => 'Москва',
                    'officeType' => $this->pointType, 'fulfillment' => false, 'available' => $this->pointAvailable, 'latitude' => 55.1, 'longitude' => 37.1]]]);
            }
            if (preg_match('~^/api/v3/supplies/([^/]+)/(barcode|trbx/stickers)$~', $path, $m)) {
                $this->documentReads++; parse_str((string) parse_url($url, PHP_URL_QUERY), $query); $this->assertSame('png', $query['type'] ?? null);
                if ($m[2] === 'trbx/stickers') { $this->assertSame('POST', $method); $this->assertCount(1, $r['trbxIds']); $this->assertContains($r['trbxIds'][0], $this->boxes[$m[1]]); }
                else $this->assertSame('GET', $method);
                $one = ['barcode' => $this->wrongBarcode ? 'WB-GI-foreign' : ($m[2] === 'barcode' ? $m[1] : '$WBMP:1:100:1'),
                    'file' => base64_encode($this->invalidPng ? '^XA Not a PNG' : $this->png())];
                if ($this->afterDocument) ($this->afterDocument)();
                return Http::response($m[2] === 'barcode' ? $one : ['stickers' => [$one]]);
            }
            $type = match (true) {
                $path === '/api/v3/supplies' && $method === 'POST' => 'create',
                (bool) preg_match('~^/api/marketplace/v3/supplies/([^/]+)/orders$~', $path) && $method === 'PATCH' => 'assign',
                $path === '/api/marketplace/v3/fbs/supplies/shipping-method' && $method === 'PATCH' => 'shipping',
                (bool) preg_match('~^/api/v3/supplies/([^/]+)/trbx$~', $path) && $method === 'POST' => 'boxes',
                (bool) preg_match('~^/api/v3/supplies/([^/]+)/deliver$~', $path) && $method === 'PATCH' => 'deliver',
                default => null,
            };
            $this->assertNotNull($type, 'Unexpected WB endpoint/method: '.$method.' '.$path);
            $this->writes[$type] = ($this->writes[$type] ?? 0) + 1;
            if ($this->writeStatus) return Http::response(['private' => 'private-error'], $this->writeStatus);
            if ($type === 'create') { $this->existing([], $r['name']); $body = ['id' => self::SUPPLY]; }
            if ($type === 'assign') {
                preg_match('~/supplies/([^/]+)/orders$~', $path, $m); $target = $m[1]; $ids = array_map('strval', $r['orders']); $this->assertLessThanOrEqual(100, count($ids));
                foreach ($ids as $id) {
                    $source = $this->orders[$id]['supplyId'];
                    if ($source) $this->members[$source] = array_values(array_diff($this->members[$source], [$id]));
                    $this->orders[$id]['supplyId'] = $target; $this->members[$target][] = $id;
                }
                $this->supplies[$target]['cargoType'] = 1; $this->supplies[$target]['crossBorderType'] = 0; $this->supplies[$target]['isB2b'] = false; $body = '';
            }
            if ($type === 'shipping') {
                $this->assertCount(1, $r['data']); $x = $r['data'][0]; $this->assertSame((int) self::WIDE, $x['shippingPointId']);
                foreach (['shippingDt', 'shippingPointId', 'shippingType'] as $k) $this->supplies[$x['supplyId']][$k] = $x[$k];
                $body = ['results' => [['supplyId' => $x['supplyId'], 'success' => true]]];
            }
            if ($type === 'boxes') {
                preg_match('~/supplies/([^/]+)/trbx$~', $path, $m); $ids = array_map(fn ($n) => 'WB-TRBX-'.$n, range(count($this->boxes[$m[1]] ?? []) + 1, count($this->boxes[$m[1]] ?? []) + $r['amount']));
                $this->boxes[$m[1]] = [...($this->boxes[$m[1]] ?? []), ...$ids]; $body = ['trbxIds' => $ids];
            }
            if ($type === 'deliver') { preg_match('~/supplies/([^/]+)/deliver$~', $path, $m); $this->supplies[$m[1]]['done'] = true; $this->supplies[$m[1]]['closedAt'] = now()->utc()->toRfc3339String(); $body = ''; }
            if ($this->lose) throw new \Illuminate\Http\Client\ConnectionException('private-network-error');
            return Http::response($body, in_array($type, ['create', 'boxes'], true) ? 201 : ($type === 'shipping' ? 200 : 204));
        });
    }
    private function order(string $id): array
    {
        return ['id' => (int) $id, 'createdAt' => now()->utc()->subDay()->toRfc3339String(), 'warehouseId' => 201, 'deliveryType' => 'fbs',
            'cargoType' => 1, 'crossBorderType' => 0, 'options' => ['isB2B' => false], 'article' => 'SKU-'.$id, 'nmId' => 101, 'skus' => ['barcode-101'], 'supplyId' => '',
            'address' => ['fullAddress' => 'private-buyer'], 'price' => 'private-finance'];
    }
    private function existing(array $ids = [], string $name = 'Поставка', string $supply = self::SUPPLY): void
    {
        $this->supplies[$supply] = ['id' => $supply, 'name' => $name, 'done' => false, 'createdAt' => now()->utc()->toRfc3339String(), 'closedAt' => null, 'scanDt' => null,
            'cargoType' => $ids ? 1 : 0, 'crossBorderType' => $ids ? 0 : null, 'isB2b' => $ids ? false : null, 'destinationOfficeId' => null,
            'shippingDt' => null, 'shippingPointId' => null, 'shippingType' => null, 'spotAvailable' => false];
        $this->members[$supply] = $ids;
        foreach ($ids as $id) $this->orders[$id]['supplyId'] = $supply;
    }
    private function selection(string $type = 'create', array $changes = []): array
    {
        return array_replace(['operation_type' => 'fbs_wb_supply_'.$type, 'supply_id' => $type === 'create' ? null : self::SUPPLY,
            'name' => $type === 'create' ? 'Поставка' : null, 'order_ids' => $type === 'assign' ? [self::WIDE, '102'] : null,
            'date_from' => $type === 'create' ? null : now()->utc()->subDays(29)->toDateString(), 'date_to' => $type === 'create' ? null : now()->utc()->toDateString(),
            'city' => in_array($type, ['shipping', 'deliver']) ? 'Москва' : null, 'office_types' => in_array($type, ['shipping', 'deliver']) ? ['sc'] : null,
            'shipping_date' => $type === 'shipping' ? now()->addDay()->toDateString() : null, 'shipping_point_id' => $type === 'shipping' ? self::WIDE : null,
            'shipping_type' => $type === 'shipping' ? 'selfShipping' : null, 'box_count' => $type === 'boxes' ? 2 : null], $changes);
    }
    private function context(array $selection): array
    {
        return $this->postJson(self::API.'/context', ['integration_id' => 62001, 'selection' => $selection])->assertOk()->json();
    }
    private function prepare(array $selection): array
    {
        $context = $this->context($selection);
        return $this->postJson(self::API.'/prepare', ['integration_id' => 62001, 'selection' => $selection, 'context_fingerprint' => $context['context_fingerprint']])->assertOk()->json();
    }
    private function decide(array $o, string $decision = 'approve', ?array $checks = null)
    {
        return $this->postJson(self::API.'/'.$o['id'].'/'.$decision, $decision === 'reconcile' ? [] : ['fingerprint' => $o['fingerprint'],
            ...($decision === 'approve' ? ['confirmations' => $checks ?? array_fill_keys(ManagerWbSupplyService::CHECKS, true)] : [])]);
    }
    private function contract(string $name, array $data): void
    {
        if (! $dir = getenv('SELYA_WB_SUPPLY_CONTRACT_DIR')) return;
        if (! is_dir($dir)) mkdir($dir, 0700, true); chmod($dir, 0700);
        file_put_contents($dir.'/'.$name.'.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)); chmod($dir.'/'.$name.'.json', 0600);
    }
    public function test_five_separate_personal_steps_create_assign_configure_boxes_and_dispatch_the_exact_group(): void
    {
        $this->contract('list', $this->getJson(self::API.'/list?integration_id=62001')->assertOk()->json());
        foreach (['create', 'assign', 'shipping', 'boxes', 'deliver'] as $type) {
            $this->contract('context-'.$type, $this->context($this->selection($type)));
            $before = array_sum($this->writes); $a = $this->prepare($this->selection($type));
            $this->contract('proposal-'.$type, $a);
            if ($type === 'shipping') $this->contract('points', $this->postJson(self::API.'/points', ['integration_id' => 62001, 'supply_id' => self::SUPPLY, 'city' => 'Москва', 'office_types' => ['sc'], 'shipping_date' => now()->addDay()->toDateString()])->assertOk()->json());
            $this->assertSame($before, array_sum($this->writes));
            $this->decide($a, 'approve', [])->assertUnprocessable();
            $done = $this->decide($a)->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.submission_acknowledged', true)->assertJsonPath('result.fields_verified', true)->assertJsonPath('physical_status', null)->json();
            $this->contract('done-'.$type, $done);
            $this->assertSame($before + 1, array_sum($this->writes)); $this->assertSame(0, DB::table('manager_fbs_resource_holds')->count());
            $this->decide($a)->assertOk(); $this->decide($a, 'reconcile')->assertOk(); $this->assertSame($before + 1, array_sum($this->writes));
            $this->assertStringNotContainsString('private-', json_encode([$a, $done]));
        }
        $this->assertTrue($this->supplies[self::SUPPLY]['done']); $this->assertCount(2, $this->members[self::SUPPLY]);
    }
    private function readySupply(string $kind): void
    {
        if ($kind === 'create') return;
        $this->existing($kind === 'assign' ? [] : [self::WIDE, '102']);
        if ($kind === 'deliver') {
            $this->supplies[self::SUPPLY]['shippingDt'] = now()->addDay()->toDateString();
            $this->supplies[self::SUPPLY]['shippingPointId'] = (int) self::WIDE; $this->supplies[self::SUPPLY]['shippingType'] = 'selfShipping';
        }
    }
    public static function kinds(): array { return array_map(fn ($k) => [$k], ['create', 'assign', 'shipping', 'boxes', 'deliver']); }
    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function test_lost_native_acknowledgement_never_replays_even_if_current_state_matches(string $kind): void
    {
        $this->readySupply($kind); $a = $this->prepare($this->selection($kind)); $this->lose = true;
        $this->contract('unknown-'.$kind, $this->decide($a)->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result.submission_acknowledged', false)->json());
        $this->assertGreaterThan(0, DB::table('manager_fbs_resource_holds')->count()); $this->lose = false;
        $this->decide($a)->assertOk()->assertJsonPath('status', 'unknown'); $this->decide($a, 'reconcile')->assertOk()->assertJsonPath('status', 'unknown');
        $this->assertSame(1, array_sum($this->writes));
        if ($kind === 'create') $this->postJson(self::API.'/context', ['integration_id' => 62001, 'selection' => $this->selection()])->assertConflict();
    }
    public function test_four_explicit_checks_owner_workspace_expiry_and_cancellation_gate_native_writes(): void
    {
        $a = $this->prepare($this->selection());
        foreach ([[], array_fill_keys(ManagerWbSupplyService::CHECKS, 1), array_fill_keys(ManagerWbSupplyService::CHECKS, true) + ['extra' => true]] as $c) $this->decide($a, 'approve', $c)->assertUnprocessable();
        $this->postJson(self::API.'/'.$a['id'].'/approve', ['fingerprint' => str_repeat('0', 64), 'confirmations' => array_fill_keys(ManagerWbSupplyService::CHECKS, true)])->assertConflict();
        $this->user = 43; $this->getJson(self::API.'/'.$a['id'])->assertNotFound(); $this->user = 42;
        $this->withHeader('X-Sellico-Workspace', '9'); $this->getJson(self::API.'/'.$a['id'])->assertStatus(503); $this->withHeader('X-Sellico-Workspace', '8');
        $this->decide($a, 'cancel')->assertOk()->assertJsonPath('status', 'cancelled'); $this->decide($a)->assertOk()->assertJsonPath('status', 'cancelled');
        $b = $this->prepare($this->selection()); $this->travel(301)->seconds();
        $this->decide($b)->assertOk()->assertJsonPath('status', 'expired'); $this->travelBack(); $this->assertSame([], $this->writes);
    }
    public function test_personal_profile_and_current_write_permissions_are_required(): void
    {
        $context = $this->context($this->selection());
        foreach ([true, 'missing'] as $flag) { $this->serviceAccount = $flag; $this->postJson(self::API.'/prepare', ['integration_id' => 62001, 'selection' => $this->selection(), 'context_fingerprint' => $context['context_fingerprint']])->assertStatus($flag === true ? 403 : 503); }
        $this->serviceAccount = false; $a = $this->prepare($this->selection()); $this->permissions = ['auto_supply.view', 'integrations.view'];
        $this->decide($a)->assertForbidden(); $this->assertSame([], $this->writes);
    }
    public function test_credentials_are_not_exposed_and_changed_metadata_value_invalidates_the_private_snapshot(): void
    {
        $this->existing(); $this->metadata[self::WIDE] = [['key' => 'imei', 'value' => 'private-code-original', 'decision' => 'filled']];
        $a = $this->prepare($this->selection('assign')); $public = json_encode($a);
        foreach (['private-', '_metadata', '_targets', '_selection', 'store_binding', 'buyer', 'price'] as $secret) $this->assertStringNotContainsString($secret, $public);
        $this->assertTrue($a['supply']['metadata_ready']); $this->metadata[self::WIDE][0]['value'] = 'private-code-changed';
        $this->decide($a)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.native_attempted', false);
        $this->assertSame([], $this->writes); $this->assertSame(0, DB::table('manager_fbs_resource_holds')->count());
    }
    public function test_pending_invalid_and_unknown_metadata_block_dispatch_but_deadline_exceeded_is_explicitly_incomplete(): void
    {
        $this->readySupply('deliver');
        foreach (['pending', 'required', 'imeiInvalidFormat', 'futureStatus'] as $status) {
            $this->metadata[self::WIDE] = [['key' => 'imei', 'value' => 'private-code', 'decision' => $status]];
            $this->postJson(self::API.'/context', ['integration_id' => 62001, 'selection' => $this->selection('deliver')])->assertUnprocessable();
        }
        $this->metadata[self::WIDE][0]['decision'] = 'deadlineExceeded'; $a = $this->prepare($this->selection('deliver'));
        $this->contract('proposal-deadline', $a);
        $this->assertTrue($a['supply']['metadata_ready']); $this->assertFalse($a['supply']['validation_complete']); $this->assertSame([], $this->writes);
    }
    public function test_unavailable_point_missing_shipping_and_required_boxes_never_prepare_dispatch(): void
    {
        $this->existing([self::WIDE, '102']);
        $this->postJson(self::API.'/context', ['integration_id' => 62001, 'selection' => $this->selection('deliver')])->assertUnprocessable();
        $this->readySupply('deliver'); $this->pointAvailable = false;
        $this->postJson(self::API.'/context', ['integration_id' => 62001, 'selection' => $this->selection('deliver')])->assertUnprocessable();
        $this->pointAvailable = true; $a = $this->prepare($this->selection('shipping')); $this->pointAvailable = false;
        $this->decide($a)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.native_attempted', false); $this->assertSame([], $this->writes);
        $this->pointAvailable = true; $this->pointType = 'pp';
        $this->postJson(self::API.'/context', ['integration_id' => 62001, 'selection' => $this->selection('deliver', ['office_types' => ['pp']])])->assertUnprocessable();
        $this->boxes[self::SUPPLY] = ['WB-TRBX-1'];
        $this->context($this->selection('deliver', ['office_types' => ['pp']])); $this->assertSame([], $this->writes);
    }
    public function test_mixed_family_and_missing_members_fail_closed_without_preparing_a_partial_group(): void
    {
        $this->existing();
        foreach (['warehouseId', 'cargoType', 'crossBorderType', 'b2b'] as $field) {
            $original = $this->orders['102']; if ($field === 'b2b') $this->orders['102']['options']['isB2B'] = true; else $this->orders['102'][$field] = $field === 'crossBorderType' ? 1 : 2;
            $this->postJson(self::API.'/context', ['integration_id' => 62001, 'selection' => $this->selection('assign')])->assertUnprocessable(); $this->orders['102'] = $original;
        }
        unset($this->orders['102']); $this->postJson(self::API.'/context', ['integration_id' => 62001, 'selection' => $this->selection('assign')])->assertUnprocessable();
        $this->assertSame(0, ManagerWbSupplyOperation::count()); $this->assertSame([], $this->writes);
    }
    public function test_known_creation_receipt_recovers_by_read_only_when_writes_are_stopped(): void
    {
        $a = $this->prepare($this->selection()); $this->readUnavailable = true;
        $this->contract('verifying-create', $this->decide($a)->assertOk()->assertJsonPath('status', 'verifying')->assertJsonPath('result.supply_id', self::SUPPLY)->json()); $this->decide($a)->assertOk(); $this->assertSame(1, $this->writes['create']);
        $this->readUnavailable = false; config(['services.manager.wb_supplies_enabled' => false]);
        $this->decide($a, 'reconcile')->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.control_hold_active', false); $this->assertSame(1, $this->writes['create']);
    }
    public static function nativeFailures(): array { return [[400, 'failed', 0], [409, 'unknown', 1], [429, 'unknown', 1], [503, 'unknown', 1]]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('nativeFailures')]
    public function test_only_definite_rejection_releases_creation_hold(int $status, string $expected, int $holds): void
    {
        $a = $this->prepare($this->selection()); $this->writeStatus = $status;
        $this->decide($a)->assertOk()->assertJsonPath('status', $expected); $this->assertSame($holds, DB::table('manager_fbs_resource_holds')->count());
        $this->decide($a)->assertOk(); $this->assertSame(1, $this->writes['create']);
    }
    public function test_unknown_fields_duplicates_client_quantities_and_oversized_native_batch_are_rejected(): void
    {
        $this->existing();
        foreach ([$this->selection('assign', ['order_ids' => ['102', '102']]), $this->selection('assign', ['order_ids' => array_map('strval', range(1, 101))]),
            $this->selection('assign') + ['postings' => []], $this->selection('boxes', ['box_count' => 0]), $this->selection('shipping', ['shipping_date' => '2026-02-30'])] as $selection)
            $this->postJson(self::API.'/context', ['integration_id' => 62001, 'selection' => $selection])->assertUnprocessable();
        $this->postJson(self::API.'/context', ['integration_id' => 62001, 'selection' => $this->selection(), 'user_id' => 42])->assertUnprocessable();
        $this->assertSame([], $this->writes);
    }
    public function test_json_reordering_is_canonical_but_native_scope_tampering_is_rejected(): void
    {
        $a = $this->prepare($this->selection()); $r = ManagerWbSupplyOperation::findOrFail($a['id']); $p = $r->plan; ksort($p);
        $r->update(['plan' => $p]); $this->getJson(self::API.'/'.$a['id'])->assertOk();
        $p['name'] = 'Другой состав'; $r->update(['plan' => $p]); $this->decide($a)->assertConflict(); $this->assertSame([], $this->writes);
    }
    public function test_success_event_and_hold_release_roll_back_together_after_a_crash(): void
    {
        $a = $this->prepare($this->selection()); $crash = true;
        DB::listen(function ($query) use (&$crash) { if ($crash && str_contains(strtolower($query->sql), 'delete from "manager_fbs_resource_holds"')) throw new \RuntimeException('test crash on finalization'); });
        $this->decide($a)->assertStatus(503); $this->assertSame('verifying', ManagerWbSupplyOperation::findOrFail($a['id'])->status); $this->assertSame(1, DB::table('manager_fbs_resource_holds')->count());
        $crash = false; $this->decide($a, 'reconcile')->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.control_hold_active', false);
        $this->assertSame(1, $this->writes['create']);
    }
    public static function changedAfterRead(): array { return array_map(fn ($v) => [$v], ['rights', 'identity', 'key', 'expiry', 'stop', 'personal']); }
    #[\PHPUnit\Framework\Attributes\DataProvider('changedAfterRead')]
    public function test_rights_identity_key_expiry_and_stop_are_rechecked_after_native_reads(string $change): void
    {
        $this->existing(); $a = $this->prepare($this->selection('assign'));
        $this->afterRead = function () use ($change) {
            $this->afterRead = null;
            if ($change === 'rights') $this->permissions = ['auto_supply.view', 'integrations.view'];
            if ($change === 'identity') $this->user = 43;
            if ($change === 'key') $this->key = 'private-new-key';
            if ($change === 'expiry') $this->travel(301)->seconds();
            if ($change === 'stop') config(['services.manager.wb_supplies_enabled' => false]);
            if ($change === 'personal') $this->serviceAccount = true;
        };
        $response = $this->decide($a);
        if (in_array($change, ['rights', 'identity', 'personal'], true)) $response->assertForbidden(); else $response->assertOk()->assertJsonPath('status', 'failed');
        $this->travelBack(); $this->assertSame([], $this->writes); $this->assertSame(0, DB::table('manager_fbs_resource_holds')->count());
        $this->assertNull(ManagerWbSupplyOperation::findOrFail($a['id'])->native_started_at);
    }
    public function test_reentrant_approval_cannot_dispatch_the_same_native_step_twice(): void
    {
        $this->existing(); $a = $this->prepare($this->selection('assign')); $nested = null;
        $this->afterRead = function () use ($a, &$nested) { $this->afterRead = null; $nested = $this->decide($a)->assertOk()->json('status'); };
        $this->decide($a)->assertOk()->assertJsonPath('status', 'succeeded'); $this->assertSame('executing', $nested); $this->assertSame(1, $this->writes['assign']);
    }
    public function test_a_hundred_selected_orders_and_existing_members_all_survive_the_metadata_batches(): void
    {
        $old = []; $selected = [];
        foreach (range(1000, 1199) as $n) { $id = (string) $n; $this->orders[$id] = $this->order($id); if ($n < 1100) $old[] = $id; else $selected[] = $id; }
        $this->existing($old); $a = $this->prepare($this->selection('assign', ['order_ids' => $selected]));
        $this->assertCount(200, $a['supply']['orders']); $this->assertCount(100, $a['supply']['selected_order_ids']); $this->assertSame(200, $a['supply']['total_units']);
        $this->decide($a)->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.observed_order_count', 200); $this->assertSame(1, $this->writes['assign']);
    }
    public function test_moving_selected_orders_from_an_active_supply_keeps_both_supply_compositions_in_the_review(): void
    {
        $this->existing(); $this->existing([self::WIDE, '102'], 'Исходная', 'WB-GI-200');
        $a = $this->prepare($this->selection('assign')); $this->assertSame('WB-GI-200', $a['supply']['source_supplies'][0]['supply']['id']);
        $this->assertCount(2, $a['supply']['source_supplies'][0]['order_ids']); $this->assertSame([], $this->writes);
        $this->decide($a)->assertOk()->assertJsonPath('status', 'succeeded'); $this->assertSame([], $this->members['WB-GI-200']); $this->assertCount(2, $this->members[self::SUPPLY]);
    }
    public function test_unknown_group_blocks_legacy_assignment_of_a_different_order_to_the_same_supply(): void
    {
        $this->existing(); $a = $this->prepare($this->selection('assign')); $this->lose = true;
        $this->decide($a)->assertOk()->assertJsonPath('status', 'unknown'); $this->lose = false; $this->orders['103'] = $this->order('103');
        $this->postJson('/api/manager/fbs/prepare', ['integration_id' => 62002, 'external_id' => '103', 'operation_type' => 'fbs_assign', 'supply_id' => self::SUPPLY])->assertConflict();
        $this->assertSame(1, $this->writes['assign']); $this->assertSame(3, DB::table('manager_fbs_resource_holds')->count());
    }
    public function test_unknown_legacy_assignment_holds_the_whole_supply_against_a_new_group(): void
    {
        $this->existing(); $this->orders['103'] = $this->order('103');
        $a = $this->postJson('/api/manager/fbs/prepare', ['integration_id' => 62002, 'external_id' => '103', 'operation_type' => 'fbs_assign', 'supply_id' => self::SUPPLY])->assertOk()->json();
        $this->lose = true; $this->postJson('/api/manager/fbs-operations/'.$a['id'].'/approve', ['fingerprint' => $a['fingerprint'], 'confirmations' => []])->assertOk()->assertJsonPath('status', 'unknown');
        $this->lose = false; $b = $this->prepare($this->selection('assign')); $this->decide($b)->assertConflict();
        $this->assertSame(1, $this->writes['assign']); $this->assertSame(2, DB::table('manager_fbs_resource_holds')->count());
    }
    public function test_the_migration_cannot_destroy_even_unexecuted_audit_history(): void
    {
        $this->prepare($this->selection()); $migration = require base_path('database/migrations/2026_11_06_210000_create_manager_wb_supply_operations.php');
        try { $migration->down(); $this->fail('History must prevent destructive downgrade'); } catch (\RuntimeException $e) { $this->assertStringContainsString('history', $e->getMessage()); }
        $this->assertSame(1, ManagerWbSupplyOperation::count());
    }
    private function png(): string
    {
        $chunk = fn ($name, $data) => pack('N', strlen($data)).$name.$data.pack('N', crc32($name.$data));
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 580, 400, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\0", 580 * 3), 400))).$chunk('IEND', '');
    }
    private function documentContext(string $kind = 'supply_qr'): array
    {
        return $this->postJson(self::API.'/document/context', ['integration_id' => 62001, 'supply_id' => self::SUPPLY,
            'document_kind' => $kind, 'box_id' => $kind === 'box_label' ? 'WB-TRBX-1' : null])->assertOk()->json();
    }
    private function documentReady(bool $closed = true): void
    {
        $this->existing([self::WIDE, '102']); $this->boxes[self::SUPPLY] = ['WB-TRBX-1'];
        if ($closed) { $this->supplies[self::SUPPLY]['done'] = true; $this->supplies[self::SUPPLY]['closedAt'] = now()->utc()->toRfc3339String(); }
    }
    public function test_supply_qr_and_single_box_label_are_private_personal_downloads_without_business_commands(): void
    {
        foreach (['supply_qr', 'box_label'] as $kind) {
            $this->documentReady($kind === 'supply_qr'); $context = $this->documentContext($kind); $this->contract('document-'.$kind, $context);
            $this->assertSame($kind, $context['document_kind']); $this->assertNull($context['physical_status']); $this->assertSame('png', $context['format']);
            $before = $this->documentReads;
            $response = $this->postJson(self::API.'/document/download', ['download_ticket' => $context['download_ticket']])->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control')); $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $response->getContent()); $this->assertSame($before + 1, $this->documentReads);
        }
        $this->assertSame([], $this->writes);
    }
    public function test_qr_requires_a_closed_supply_and_a_box_label_requires_membership_in_that_supply(): void
    {
        $this->documentReady(false);
        $this->postJson(self::API.'/document/context', ['integration_id' => 62001, 'supply_id' => self::SUPPLY, 'document_kind' => 'supply_qr', 'box_id' => null])->assertUnprocessable();
        $this->postJson(self::API.'/document/context', ['integration_id' => 62001, 'supply_id' => self::SUPPLY, 'document_kind' => 'box_label', 'box_id' => 'WB-TRBX-foreign'])->assertUnprocessable();
        $this->assertSame(0, $this->documentReads);
    }
    public function test_foreign_owner_changed_store_scope_and_expired_document_tickets_never_fetch_a_file(): void
    {
        $this->documentReady(); $context = $this->documentContext(); $body = ['download_ticket' => $context['download_ticket']];
        $this->user = 43; $this->postJson(self::API.'/document/download', $body)->assertGone(); $this->user = 42;
        $this->key = 'private-rotated'; $this->postJson(self::API.'/document/download', $body)->assertGone(); $this->key = 'native-private-key';
        $this->members[self::SUPPLY] = []; $this->postJson(self::API.'/document/download', $body)->assertGone();
        $this->members[self::SUPPLY] = [self::WIDE, '102']; $this->travel(301)->seconds(); $this->postJson(self::API.'/document/download', $body)->assertGone(); $this->travelBack();
        $this->assertSame(0, $this->documentReads); $this->assertSame([], $this->writes);
    }
    public function test_export_revocation_after_native_file_read_does_not_return_the_bytes(): void
    {
        $this->documentReady(); $context = $this->documentContext();
        $this->afterDocument = function () { $this->permissions = ['auto_supply.view', 'integrations.view', 'auto_supply.create']; };
        $this->postJson(self::API.'/document/download', ['download_ticket' => $context['download_ticket']])->assertForbidden();
        $this->assertSame(1, $this->documentReads);
    }
    public function test_wrong_supply_barcode_or_non_png_payload_cannot_be_downloaded_as_a_checked_label(): void
    {
        $this->documentReady(); $context = $this->documentContext(); $this->wrongBarcode = true;
        $this->postJson(self::API.'/document/download', ['download_ticket' => $context['download_ticket']])->assertStatus(503);
        $this->wrongBarcode = false; $this->invalidPng = true; $this->postJson(self::API.'/document/download', ['download_ticket' => $context['download_ticket']])->assertStatus(503);
        $this->assertSame([], $this->writes);
    }
}
