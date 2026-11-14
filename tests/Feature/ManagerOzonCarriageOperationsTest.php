<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\ManagerOzonCarriageOperation as Operation;
use App\Services\Manager\ManagerDataException;
use App\Services\Manager\ManagerFbsPublicationGuard as Guard;
use App\Services\Manager\ManagerOzonCarriageService as Service;
use App\Services\SellicoApiService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagerOzonCarriageOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;
    private const ID = '9007199254740993';
    private const API = '/api/manager/ozon-carriages';
    private array $permissions = ['auto_supply.view', 'auto_supply.create', 'integrations.view'];
    private int $user = 42;
    private mixed $serviceAccount = false;
    private string $key = 'native-private-key';
    private bool $created = false;
    private bool $formed = false;
    private bool $entrusted = false;
    private bool $unavailable = false;
    private int $creates = 0;
    private int $approves = 0;
    private int $writeStatus = 200;
    private bool $lost = false;
    private ?int $containers = null;
    private array $ready;
    private ?array $nativeMembers = null;
    private mixed $fee = ['currency_code' => 'RUB', 'value' => 12.34];
    private $afterReady = null;
    private $duringCreate = null;
    private bool $badPagination = false;
    private bool $wrongMemberAct = false;
    private array $platformMessages = [];
    private bool $partial = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sellico.base_url' => 'https://crm.test/api', 'services.sellico.skip_permission_check' => true,
            'services.manager.fbs_writes_enabled' => true, 'services.manager.ozon_carriages_enabled' => true]);
        Http::preventStrayRequests(); Cache::flush();
        $this->mock(SellicoApiService::class, fn ($m) => $m->shouldReceive('getServiceToken')->andReturn('service-session'));
        $this->withToken('human-session')->withHeader('X-Sellico-Workspace', '8');
        foreach ([62001, 62002] as $id) Integration::factory()->ozon()->create(['id' => $id, 'work_space_id' => 8]);
        $this->ready = [$this->posting('100-1-1', 101, 2), $this->posting('100-2-1', 102, 1)];
        Http::fake(function ($r) {
            $url = $r->url();
            if ($url === 'https://crm.test/api/user') return Http::response(['id' => $this->user, ...($this->serviceAccount === 'missing' ? [] : ['is_service_account' => $this->serviceAccount])]);
            if ($url === 'https://crm.test/api/workspaces/8/user-permissions') return Http::response(['user_id' => $this->user, 'permissions' => $this->permissions]);
            if ($url === 'https://crm.test/api/get-integrations/8') return Http::response(array_map(fn ($id) => ['id' => $id, 'work_space_id' => 8, 'status' => 'active', 'type' => 'ozon', 'name' => 'Native store', 'api_key' => $this->key, 'client_id' => '555'], [62001, 62002]));
            $this->assertTrue(str_starts_with($url, 'https://api-seller.ozon.ru/'));
            $this->assertTrue($r->hasHeader('Api-Key', [$this->key])); $this->assertTrue($r->hasHeader('Client-Id', ['555'])); $this->assertFalse($r->hasHeader('Authorization'));
            $path = parse_url($url, PHP_URL_PATH);
            if ($path === '/v2/carriage/delivery/list') {
                $this->assertSame('2026-11-06', $r['filter']['departure_date']); $this->assertSame(100, $r['limit']);
                return Http::response(['has_next' => false, 'cursor' => '', 'methods' => [[
                    'delivery_method_id' => 301, 'warehouse_id' => 201, 'delivery_method_name' => 'Ozon FBS', 'departure_date' => '2026-11-06',
                    'integration_type' => 'ozon', 'is_rfbs' => false, 'has_entrusted_acceptance' => $this->entrusted, 'errors' => $this->platformMessages, 'dropoff_address' => 'Пункт Ozon', 'dropoff_point_id' => 401,
                    'carriages' => [['id' => $this->created ? (int) self::ID : 0, 'all_blr_traceable' => false, 'available_actions' => $this->created ? ['get_details'] : ['create'],
                        'postings_count' => count($this->created ? ($this->nativeMembers ?? $this->ready) : $this->ready), 'status' => $this->created ? ($this->formed ? 'formed' : 'new') : 'new', 'pickup_fee' => $this->fee]],
                    'private_customer' => 'buyer-private',
                ]]]);
            }
            if ($path === '/v4/posting/fbs/list') {
                $this->assertSame(['awaiting_deliver'], $r['filter']['statuses']); $this->assertSame(['301'], $r['filter']['delivery_method_ids']);
                $this->assertSame(['201'], $r['filter']['warehouse_ids']); $this->assertFalse($r['filter']['is_blr_traceable']);
                $offset = $r['cursor'] === '' ? 0 : (int) substr($r['cursor'], 5);
                $rows = array_slice($this->ready, $offset, 100); $next = $offset + count($rows) < count($this->ready);
                if ($this->afterReady) ($this->afterReady)();
                return Http::response(['postings' => $rows, 'has_next' => $this->badPagination || $next, 'cursor' => $this->badPagination ? '' : ($next ? 'page-'.($offset + count($rows)) : '')]);
            }
            if ($path === '/v1/carriage/get') {
                $this->assertSame((int) self::ID, $r['carriage_id']);
                return Http::response(['carriage_id' => (int) self::ID, 'delivery_method_id' => 301, 'warehouse_id' => 201, 'departure_date' => '2026-11-06',
                    'integration_type' => 'ozon', 'all_blr_traceable' => false, 'containers_count' => $this->containers ?? 0, 'status' => $this->formed ? 'formed' : 'new',
                    'is_partial' => $this->partial, 'partial_num' => $this->partial ? 2 : 0, 'has_postings_for_next_carriage' => $this->partial]);
            }
            if ($path === '/v2/posting/fbs/act/get-postings') {
                $this->assertSame((int) self::ID, $r['id']);
                if ($this->unavailable) return Http::response(['private' => 'native-private-key'], 503);
                return Http::response(['result' => array_map(fn ($p) => ['id' => $this->wrongMemberAct ? 999 : (int) self::ID, 'is_blr_traceable' => false, 'seller_error' => '', ...$p], $this->nativeMembers ?? $this->ready)]);
            }
            if ($path === '/v1/carriage/create') {
                $this->creates++; $this->assertSame(['delivery_method_id' => 301, 'departure_date' => '2026-11-06T15:00:00+03:00', 'all_blr_traceable' => false], $r->data());
                if ($this->duringCreate) ($this->duringCreate)();
                if ($this->writeStatus === 200 || $this->lost) $this->created = true;
                if ($this->lost) throw new \Illuminate\Http\Client\ConnectionException('private-source-error');
                return Http::response($this->writeStatus === 200 ? ['carriage_id' => (int) self::ID] : ['private' => 'private-source-error'], $this->writeStatus);
            }
            if ($path === '/v1/carriage/approve') {
                $this->approves++; $this->assertSame((int) self::ID, $r['carriage_id']);
                $this->assertSame(['carriage_id' => (int) self::ID, ...($this->entrusted ? ['containers_count' => 3] : [])], $r->data());
                if ($this->writeStatus === 200 || $this->lost) { $this->formed = true; $this->containers = $this->entrusted ? 3 : 0; }
                if ($this->lost) throw new \Illuminate\Http\Client\ConnectionException('private-source-error');
                return Http::response($this->writeStatus === 200 ? '{}' : '{"message":"private-source-error"}', $this->writeStatus);
            }
            $this->fail('Unexpected native endpoint: only current reads/create/approve are permitted');
        });
    }
    private function posting(string $id, int $sku, int $quantity): array
    {
        return ['posting_number' => $id, 'order_id' => 701, 'status' => 'awaiting_deliver', 'delivery_method' => ['id' => 301, 'warehouse_id' => 201],
            'products' => [['sku' => $sku, 'offer_id' => 'SKU-'.$sku, 'name' => 'Товар '.$sku, 'quantity' => $quantity, 'price' => 'private-finance']], 'buyer' => 'buyer-private'];
    }
    private function selection(bool $approve = false): array
    {
        return ['operation_type' => $approve ? 'fbs_carriage_approve' : 'fbs_carriage_create', 'delivery_method_id' => $approve ? null : '301',
            'departure_at' => $approve ? null : '2026-11-06T15:00:00+03:00', 'all_blr_traceable' => $approve ? null : false,
            'carriage_id' => $approve ? self::ID : null, 'containers_count' => $approve && $this->entrusted ? 3 : null];
    }
    private function context(bool $approve = false, int $store = 62001): array
    {
        $query = ['integration_id' => $store, ...array_filter($this->selection($approve), fn ($v) => $v !== null)];
        return $this->getJson(self::API.'/context?'.http_build_query($query))->assertOk()->json();
    }
    private function prepare(bool $approve = false, int $store = 62001): array
    {
        $c = $this->context($approve, $store);
        return $this->postJson(self::API.'/prepare', ['integration_id' => $store, 'selection' => $c['selection'], 'context_fingerprint' => $c['context_fingerprint']])->assertOk()->json();
    }
    private function decision(array $o, string $decision = 'approve', ?array $checks = null)
    {
        return $this->postJson(self::API.'/'.$o['id'].'/'.$decision, $decision === 'reconcile' ? [] : ['fingerprint' => $o['fingerprint'], ...($decision === 'approve' ? ['confirmations' => $checks ?? array_fill_keys(Service::CHECKS, true)] : [])]);
    }
    private function held(): int { return DB::table('manager_fbs_resource_holds')->count(); }
    private function contract(string $name, array $data): void
    {
        if (! $dir = getenv('SELYA_OZON_CARRIAGE_CONTRACT_DIR')) return;
        if (! is_dir($dir)) mkdir($dir, 0700, true); chmod($dir, 0700);
        file_put_contents($dir.'/'.$name.'.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)); chmod($dir.'/'.$name.'.json', 0600);
    }
    public function test_two_personal_proposals_create_a_draft_then_confirm_the_actual_complete_carriage(): void
    {
        $methods = $this->getJson(self::API.'/methods?integration_id=62001&departure_date=2026-11-06')->assertOk()->json(); $this->contract('methods', $methods);
        $context = $this->context(); $this->contract('context-create', $context); $this->assertSame(2, $context['carriage']['posting_count']);
        $a = $this->prepare(); $this->contract('proposal-create', $a); $this->assertSame(0, $this->creates); $this->assertSame(0, $this->held());
        $created = $this->decision($a)->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.carriage_created_verified', true)->assertJsonPath('result.confirmation_verified', false)->json();
        $this->contract('created', $created); $this->assertSame(1, $this->creates); $this->assertSame(0, $this->approves); $this->assertSame(4, $this->held());
        $this->contract('context-approve', $this->context(true)); $b = $this->prepare(true); $this->contract('proposal-approve', $b);
        $confirmed = $this->decision($b)->assertOk()->assertJsonPath('status', 'succeeded')->assertJsonPath('result.confirmation_verified', true)->assertJsonPath('result.control_hold_active', false)->json();
        $this->contract('confirmed', $confirmed); $this->assertSame(1, $this->approves); $this->assertSame(0, $this->held());
        $this->decision($a)->assertOk(); $this->decision($b)->assertOk(); $this->assertSame(1, $this->creates); $this->assertSame(1, $this->approves);
        $public = json_encode([$methods, $context, $a, $created, $b, $confirmed]);
        foreach (['native-private-key', 'buyer-private', 'private-finance', 'store_binding', 'parent_operation_id'] as $secret) $this->assertStringNotContainsString($secret, $public);
    }
    public function test_four_explicit_personal_checks_and_exact_fingerprint_are_required(): void
    {
        $a = $this->prepare();
        foreach ([[], ['carriage_scope_checked' => true], array_fill_keys(Service::CHECKS, 1), [...array_fill_keys(Service::CHECKS, true), 'extra' => true]] as $checks) $this->decision($a, 'approve', $checks)->assertUnprocessable();
        $this->decision([...$a, 'fingerprint' => str_repeat('0', 64)])->assertConflict(); $this->assertSame(0, $this->creates); $this->assertSame(0, $this->held());
    }
    public function test_missing_personal_profile_flag_service_account_and_revoked_rights_cannot_prepare_writes(): void
    {
        $a = $this->prepare();
        foreach (['missing', true] as $value) { $this->serviceAccount = $value; $this->decision($a)->assertStatus($value === true ? 403 : 503); }
        $this->serviceAccount = false; $this->permissions = ['auto_supply.view', 'integrations.view']; $this->decision($a)->assertForbidden();
        $this->assertSame(0, $this->creates);
    }
    public function test_expired_cancelled_foreign_and_wrong_workspace_proposals_are_not_dispatched(): void
    {
        $a = $this->prepare(); $this->user = 43; $this->decision($a)->assertNotFound(); $this->user = 42;
        $this->decision($a, 'cancel')->assertOk()->assertJsonPath('status', 'cancelled'); $this->decision($a)->assertOk();
        $b = $this->prepare(); $this->travel(5)->minutes(); $this->decision($b)->assertOk()->assertJsonPath('status', 'expired');
        $this->assertSame(0, $this->creates); $this->assertSame(0, $this->held());
    }
    public function test_changed_member_quantity_fee_and_key_require_fresh_review_without_a_native_attempt(): void
    {
        foreach (['quantity', 'fee', 'key'] as $case) {
            $a = $this->prepare();
            if ($case === 'quantity') $this->ready[0]['products'][0]['quantity']++;
            if ($case === 'fee') $this->fee['value'] = 20;
            if ($case === 'key') $this->key = 'rotated';
            $r = $this->decision($a); if ($case === 'key') $r->assertConflict(); else $r->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.native_attempted', false);
            $this->assertSame(0, $this->creates); $this->assertSame(0, $this->held());
        }
    }
    public function test_rights_key_identity_expiry_and_stop_are_rechecked_after_the_last_vendor_read(): void
    {
        foreach (['rights', 'key', 'identity', 'expiry', 'stop'] as $case) {
            $a = $this->prepare();
            $this->afterReady = function () use ($case) {
                if ($case === 'rights') $this->permissions = ['auto_supply.view', 'integrations.view'];
                if ($case === 'key') $this->key = 'rotated';
                if ($case === 'identity') $this->user = 43;
                if ($case === 'expiry') $this->travel(5)->minutes();
                if ($case === 'stop') config(['services.manager.ozon_carriages_enabled' => false]);
            };
            $r = $this->decision($a); if (in_array($case, ['rights', 'identity'], true)) $r->assertForbidden(); else $r->assertOk()->assertJsonPath('status', 'failed');
            $this->assertSame(0, $this->creates); $this->assertSame(0, $this->held());
            $this->afterReady = null; $this->permissions = ['auto_supply.view', 'auto_supply.create', 'integrations.view']; $this->user = 42; $this->key = 'native-private-key'; config(['services.manager.ozon_carriages_enabled' => true]);
        }
    }
    public function test_reentrant_approval_and_alias_store_cannot_send_a_second_create(): void
    {
        $a = $this->prepare(); $alias = $this->prepare(false, 62002);
        $this->duringCreate = function () use ($a, $alias) {
            $this->decision($a)->assertOk()->assertJsonPath('status', 'executing'); $this->decision($alias)->assertConflict();
        };
        $this->decision($a)->assertOk()->assertJsonPath('status', 'succeeded'); $this->assertSame(1, $this->creates);
    }
    public function test_durable_group_holds_block_connected_old_order_paths_after_cache_locks_are_lost(): void
    {
        $a = $this->prepare();
        $this->duringCreate = function () {
            Cache::lock('manager-fbs-target:'.Guard::resource('ozon', '100-1-1'))->forceRelease();
            try { app(Guard::class)->run('ozon', '100-1-1', fn () => $this->fail('old writer ran')); $this->fail('hold ignored'); }
            catch (ManagerDataException $e) { $this->assertSame(409, $e->httpStatus); }
        };
        $this->decision($a)->assertOk(); $this->assertSame(4, $this->held());
    }
    public function test_lost_create_ack_is_unknown_and_a_coincidental_new_carriage_never_authorizes_replay(): void
    {
        $a = $this->prepare(); $this->lost = true;
        $r = $this->decision($a)->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result.submission_acknowledged', false)->json(); $this->contract('unknown-create', $r);
        $this->lost = false; $this->decision($a)->assertOk()->assertJsonPath('status', 'unknown'); $this->decision($a, 'reconcile')->assertOk()->assertJsonPath('status', 'unknown');
        $this->assertSame(1, $this->creates); $this->assertSame(4, $this->held());
        $this->getJson(self::API.'/context?'.http_build_query(['integration_id' => 62001, ...array_filter($this->selection(true), fn ($v) => $v !== null)]))->assertConflict();
    }
    public function test_lost_approval_ack_stays_unknown_even_when_api_shows_the_confirmed_state(): void
    {
        $this->created = true; $a = $this->prepare(true); $this->lost = true;
        $r = $this->decision($a)->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result.confirmation_verified', true)->assertJsonPath('result.submission_acknowledged', false)->json(); $this->contract('unknown-approve', $r);
        $this->lost = false; $this->decision($a, 'reconcile')->assertOk()->assertJsonPath('status', 'unknown'); $this->decision($a)->assertOk();
        $this->assertSame(1, $this->approves); $this->assertSame(4, $this->held());
    }
    public function test_known_create_receipt_survives_unavailable_readback_and_only_reads_are_retried(): void
    {
        $a = $this->prepare(); $this->unavailable = true;
        $this->decision($a)->assertOk()->assertJsonPath('status', 'verifying')->assertJsonPath('result.carriage_id', self::ID);
        $this->decision($a)->assertOk(); $this->assertSame(1, $this->creates);
        $this->unavailable = false; config(['services.manager.ozon_carriages_enabled' => false]);
        $this->decision($a, 'reconcile')->assertOk()->assertJsonPath('status', 'succeeded'); $this->assertSame(1, $this->creates);
    }
    public function test_changed_automatic_membership_requires_a_new_review_of_the_actual_carriage(): void
    {
        $a = $this->prepare(); $this->duringCreate = function () { $this->ready[] = $this->posting('100-3-1', 103, 1); };
        $this->decision($a)->assertOk()->assertJsonPath('status', 'verifying')->assertJsonPath('result.composition_verified', false);
        $this->duringCreate = null; $b = $this->prepare(true); $this->assertSame(3, $b['carriage']['posting_count']);
        $this->decision($b)->assertOk()->assertJsonPath('status', 'succeeded'); $this->assertSame(0, $this->held()); $this->assertSame(1, $this->creates); $this->assertSame(1, $this->approves);
    }
    public function test_child_preflight_failure_restores_parent_control_instead_of_releasing_a_created_draft(): void
    {
        $a = $this->prepare(); $this->decision($a)->assertOk(); $b = $this->prepare(true);
        $this->fee['value'] = 50; $this->decision($b)->assertOk()->assertJsonPath('status', 'failed');
        $this->assertSame(4, DB::table('manager_fbs_resource_holds')->where('operation_id', $a['id'])->count());
        $this->assertNull(Operation::find($a['id'])->child_operation_id); $this->assertSame(0, $this->approves);
    }
    public function test_entrusted_places_are_only_sent_in_the_separate_confirmation(): void
    {
        $this->entrusted = true; $a = $this->prepare(); $this->decision($a)->assertOk()->assertJsonPath('status', 'succeeded');
        $b = $this->prepare(true); $this->assertSame(3, $b['carriage']['containers_count']);
        $this->decision($b)->assertOk()->assertJsonPath('result.confirmation_verified', true); $this->assertSame(1, $this->creates); $this->assertSame(1, $this->approves);
    }
    public function test_malformed_pagination_and_unknown_or_duplicate_members_do_not_create_a_proposal(): void
    {
        $this->badPagination = true; $this->getJson(self::API.'/context?'.http_build_query(['integration_id' => 62001, ...array_filter($this->selection(), fn ($v) => $v !== null)]))->assertStatus(503);
        $this->badPagination = false; $this->ready[] = $this->ready[0];
        $this->getJson(self::API.'/context?'.http_build_query(['integration_id' => 62001, ...array_filter($this->selection(), fn ($v) => $v !== null)]))->assertStatus(503);
        $this->assertSame(0, Operation::count()); $this->assertSame(0, $this->creates);
    }
    public function test_json_object_order_is_canonical_but_tampered_quantities_and_owner_expiry_are_not_accepted(): void
    {
        $a = $this->prepare(); $row = Operation::find($a['id']); $row->update(['plan' => array_reverse($row->plan, true)]);
        $this->decision($a)->assertOk()->assertJsonPath('status', 'succeeded');
        $b = $this->prepare(true); $row = Operation::find($b['id']); $p = $row->plan; $p['postings'][0]['items'][0]['quantity']++; $row->update(['plan' => $p]);
        $this->decision($b)->assertConflict(); $this->assertSame(0, $this->approves);
    }
    public function test_native_rejection_releases_new_claim_but_429_and_interrupted_dispatch_do_not_replay(): void
    {
        $a = $this->prepare(); $this->writeStatus = 400;
        $this->decision($a)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.control_hold_active', false); $this->assertSame(0, $this->held());
        $this->decision($a)->assertOk(); $this->assertSame(1, $this->creates);
        $b = $this->prepare(); $this->writeStatus = 429; $this->decision($b)->assertOk()->assertJsonPath('status', 'unknown');
        $this->decision($b)->assertOk(); $this->assertSame(2, $this->creates); $this->assertSame(4, $this->held());
    }
    public function test_unknown_fields_client_quantities_and_unsupported_date_timezone_are_rejected(): void
    {
        $a = $this->prepare(); $this->postJson(self::API.'/'.$a['id'].'/approve', ['fingerprint' => $a['fingerprint'], 'confirmations' => array_fill_keys(Service::CHECKS, true), 'postings' => []])->assertUnprocessable();
        $this->getJson(self::API.'/context?integration_id=62001&operation_type=fbs_carriage_create&delivery_method_id=301&departure_at=2026-11-06&all_blr_traceable=0')->assertUnprocessable();
        $this->assertSame(0, $this->creates);
    }
    public function test_migration_preserves_even_unexecuted_audit_history(): void
    {
        $this->prepare(); $migration = require database_path('migrations/2026_11_06_200000_create_manager_ozon_carriage_operations.php');
        try { $migration->down(); $this->fail('History dropped'); } catch (\RuntimeException $e) { $this->assertStringContainsString('history', $e->getMessage()); }
        $this->assertSame(1, Operation::count());
    }
    public function test_status_and_hold_release_commit_together_and_recovery_never_repeats_native_confirmation(): void
    {
        $this->created = true; $a = $this->prepare(true); $interrupt = true;
        DB::listen(function ($query) use (&$interrupt) {
            if ($interrupt && str_starts_with($query->sql, 'delete from "manager_fbs_resource_holds"')) {
                $interrupt = false; throw new \RuntimeException('simulated process failure during terminal transaction');
            }
        });
        $this->decision($a)->assertStatus(503);
        $this->assertSame('verifying', Operation::find($a['id'])->status); $this->assertSame(4, $this->held());
        $this->decision($a, 'reconcile')->assertOk()->assertJsonPath('status', 'succeeded');
        $this->assertSame(0, $this->held()); $this->assertSame(1, $this->approves);
    }
    public function test_all_pages_of_a_101_posting_group_are_reviewed_held_created_and_confirmed(): void
    {
        $this->ready = array_map(fn ($n) => $this->posting('100-'.$n.'-1', 1000 + $n, 1), range(1, 101));
        $a = $this->prepare(); $this->assertSame(101, $a['carriage']['posting_count']);
        $this->decision($a)->assertOk()->assertJsonPath('status', 'succeeded'); $this->assertSame(103, $this->held());
        $b = $this->prepare(true); $this->assertSame(101, count($b['carriage']['postings']));
        $this->decision($b)->assertOk()->assertJsonPath('status', 'succeeded'); $this->assertSame(0, $this->held());
        $this->assertSame(1, $this->creates); $this->assertSame(1, $this->approves);
    }
    public function test_scope_larger_than_the_verified_limit_or_a_foreign_act_member_never_prepares_a_write(): void
    {
        $original = $this->ready; $this->ready = array_fill(0, 1001, $original[0]);
        $this->getJson(self::API.'/context?'.http_build_query(['integration_id' => 62001, ...array_filter($this->selection(), fn ($v) => $v !== null)]))->assertUnprocessable();
        $this->ready = $original; $this->created = true; $this->wrongMemberAct = true;
        $this->getJson(self::API.'/context?'.http_build_query(['integration_id' => 62001, ...array_filter($this->selection(true), fn ($v) => $v !== null)]))->assertStatus(503);
        $this->assertSame(0, Operation::count()); $this->assertSame(0, $this->creates + $this->approves);
    }
    public function test_a_definite_native_rejection_restores_parent_control_in_the_same_terminal_transaction(): void
    {
        $a = $this->prepare(); $this->decision($a)->assertOk(); $b = $this->prepare(true); $this->writeStatus = 400;
        $this->decision($b)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.submission_acknowledged', false);
        $this->assertSame(4, DB::table('manager_fbs_resource_holds')->where('operation_id', $a['id'])->count());
        $this->assertSame(0, DB::table('manager_fbs_resource_holds')->where('operation_id', $b['id'])->count());
        $this->assertNull(Operation::find($a['id'])->child_operation_id); $this->assertSame(1, $this->approves);
    }
    public function test_explicit_native_warning_is_preserved_for_review_but_errors_and_unknown_severity_block_writes(): void
    {
        $this->platformMessages = [['code' => 'has_seller_returns_in_stock', 'status' => 'warning', 'description' => 'Есть возвраты, которые нужно забрать']];
        $a = $this->prepare(); $this->contract('proposal-warning', $a); $this->assertSame('warning', $a['carriage']['method']['platform_messages'][0]['severity']);
        $this->decision($a)->assertOk()->assertJsonPath('status', 'succeeded');
        $this->created = false; DB::table('manager_fbs_resource_holds')->where('operation_id', $a['id'])->delete();
        foreach (['error', 'unverified-status'] as $severity) {
            $this->platformMessages[0]['status'] = $severity;
            $this->getJson(self::API.'/context?'.http_build_query(['integration_id' => 62001, ...array_filter($this->selection(), fn ($v) => $v !== null)]))->assertStatus($severity === 'error' ? 422 : 503);
        }
        $this->assertSame(1, $this->creates);
    }
    public function test_a_group_that_cannot_fit_the_complete_review_is_rejected_without_truncating_or_writing(): void
    {
        $this->ready = array_map(function ($n) { $p = $this->posting('100-'.$n.'-1', 1000 + $n, 1); $p['products'][0]['name'] = str_repeat('x', 3990); return $p; }, range(1, 800));
        $this->getJson(self::API.'/context?'.http_build_query(['integration_id' => 62001, ...array_filter($this->selection(), fn ($v) => $v !== null)]))->assertUnprocessable();
        $this->assertSame(0, Operation::count()); $this->assertSame(0, $this->creates); $this->assertSame(0, $this->held());
    }
    public function test_partial_carriage_metadata_is_reviewed_and_a_changed_partition_cannot_be_confirmed_silently(): void
    {
        $this->created = true; $this->partial = true; $a = $this->prepare(true); $this->contract('proposal-partial', $a);
        $this->assertSame(['is_partial' => true, 'partial_num' => 2, 'has_postings_for_next_carriage' => true], $a['carriage']['partition']);
        $this->partial = false; $this->decision($a)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result.native_attempted', false);
        $this->assertSame(0, $this->approves);
        $this->partial = true; $b = $this->prepare(true); $confirmed = $this->decision($b)->assertOk()->assertJsonPath('result.carriage_partition.partial_num', 2)->assertJsonPath('status', 'succeeded')->json();
        $this->contract('confirmed-partial', $confirmed);
    }
}
