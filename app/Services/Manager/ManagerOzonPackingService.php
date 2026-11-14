<?php

namespace App\Services\Manager;

use App\Models\Integration;
use App\Models\ManagerOzonPackingOperation as Operation;
use App\Models\ManagerOzonPackingItem as Item;
use App\Jobs\RunManagerOzonPackingGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ManagerOzonPackingService
{
    public const CHECKS = ['ozon_packing_scope_checked', 'ozon_packing_packages_checked', 'ozon_packing_requirements_checked', 'ozon_packing_effect_checked'];
    private const ACTIVE = ['pending', 'executing', 'verifying', 'unknown'];
    public function __construct(private ManagerStoreResolver $stores, private ManagerOzonPackingGateway $gateway, private ManagerFbsPublicationGuard $guard) {}

    public function context(ManagerIdentity $who, int $integrationId, array $selection, callable $revalidate): array
    {
        $this->permission($who); $store = $this->store($who, $integrationId); $plan = $this->gateway->context($store, $selection);
        $this->checkReadScope($who, $store, $revalidate());
        return ['workspace_id' => (string) $who->workspaceId, 'integration_id' => (string) $integrationId, 'integration_name' => $store->name, 'marketplace' => 'ozon', 'source' => 'Ozon Seller API',
            'group' => $this->gateway->publicPlan($plan), 'selection' => $plan['_selection'], 'context_fingerprint' => $this->contextHash($who, $store, $plan),
            'completeness' => 'complete_target', 'physical_status' => null, 'observed_at' => now()->utc()->toRfc3339String()];
    }

    public function prepare(ManagerIdentity $who, int $integrationId, array $selection, string $contextHash, callable $revalidate): array
    {
        $this->permission($who, true); $this->available(); $store = $this->store($who, $integrationId); $plan = $this->gateway->context($store, $selection);
        return $this->preparePlan($who, $store, $plan, $contextHash, $revalidate);
    }

    public function onePackageContext(ManagerIdentity $who, int $integrationId, array $numbers, callable $revalidate): array
    {
        $this->permission($who); $store = $this->store($who, $integrationId); $plan = $this->gateway->onePackageContext($store, $numbers);
        $this->checkReadScope($who, $store, $revalidate());
        return ['workspace_id' => (string) $who->workspaceId, 'integration_id' => (string) $integrationId, 'integration_name' => $store->name, 'marketplace' => 'ozon', 'source' => 'Ozon Seller API',
            'group' => $this->gateway->publicPlan($plan), 'selection' => $plan['_selection'], 'context_fingerprint' => $this->contextHash($who, $store, $plan),
            'completeness' => 'complete_target', 'physical_status' => null, 'observed_at' => now()->utc()->toRfc3339String()];
    }

    public function prepareOnePackage(ManagerIdentity $who, int $integrationId, array $numbers, string $contextHash, callable $revalidate): array
    {
        $this->permission($who, true); $this->available(); $store = $this->store($who, $integrationId); $plan = $this->gateway->onePackageContext($store, $numbers);
        return $this->preparePlan($who, $store, $plan, $contextHash, $revalidate);
    }

    private function preparePlan(ManagerIdentity $who, ManagerStore $store, array $plan, string $contextHash, callable $revalidate): array
    {
        $this->checkReadScope($who, $store, $revalidate(), true); $this->available();
        if (! hash_equals($this->contextHash($who, $store, $plan), $contextHash)) throw new ManagerDataException('Состав группы изменился. Нужен свежий контекст.', 409);
        return $this->guard->runMany('ozon', $plan['_targets'], function () use ($who, $store, $plan) {
            $op = DB::transaction(function () use ($who, $store, $plan) {
                $at = CarbonImmutable::now('UTC')->startOfSecond();
                $op = new Operation(['id' => (string) Str::uuid(), 'workspace_id' => $who->workspaceId, 'user_id' => $who->userId, 'integration_id' => $store->integrationId,
                    'store_name' => $store->name, 'store_binding' => $store->binding, 'plan' => $plan, 'status' => 'awaiting_confirmation', 'created_at' => $at, 'updated_at' => $at, 'expires_at' => $at->addMinutes(5)]);
                $op->fingerprint = $this->fingerprint($op); $op->save();
                foreach ($plan['sources'] as $i => $source) Item::create(['id' => (string) Str::uuid(), 'operation_id' => $op->id, 'position' => $i,
                    'source_posting_number' => $source['posting']['external_id'], 'plan' => $source, 'status' => 'prepared']);
                $this->event($op->id, 'awaiting_confirmation'); return $op;
            });
            return $this->expose($op);
        });
    }

    public function get(ManagerIdentity $who, string $id): array
    {
        $this->permission($who); $op = $this->owned($who, $id);
        if ($op->status === 'awaiting_confirmation' && $op->expires_at->timestamp <= now()->timestamp) $this->halt($op, 'expired', 'expired');
        $claimed = $op->status === 'executing' && DB::table('manager_ozon_packing_outbox')->where('operation_id', $id)->whereNotNull('worker_claimed_at')->exists();
        if ($op->status === 'executing' && ($op->expires_at->timestamp <= now()->timestamp || $claimed && $op->heartbeat_at?->lt(now()->subSeconds(180)))) $this->halt($op, 'worker_expired');
        return $this->expose($op->fresh());
    }

    public function approve(ManagerIdentity $who, string $id, string $hash, array $checks, string $bearer, callable $revalidate): array
    {
        $this->permission($who, true); $this->available(); $op = $this->owned($who, $id); $this->confirm($op, $hash);
        if ($op->status !== 'awaiting_confirmation') return $this->get($who, $id);
        if (count($checks) !== 4 || array_diff(array_keys($checks), self::CHECKS) || array_filter($checks, fn ($v) => $v !== true)) throw new ManagerDataException('Нужны четыре личные проверки группы.', 422);
        if (! $bearer || strlen($bearer) > 16384) throw new ManagerDataException('Нужна действительная личная сессия.', 401);
        $this->guard->runMany('ozon', $op->plan['_targets'], function () use ($who, $op, $hash, $checks, $bearer, $revalidate) {
            $actor = $this->sameActor($op, $revalidate(), true); $store = $this->store($actor, (int) $op->integration_id); $this->sameStore($op, $store);
            try {
                DB::transaction(function () use ($op, $hash, $checks, $bearer, $actor) {
                    $row = Operation::whereKey($op->id)->lockForUpdate()->firstOrFail(); $this->confirm($row, $hash);
                    if ($row->status !== 'awaiting_confirmation' || $row->expires_at->timestamp <= now()->timestamp) return;
                    foreach ($row->plan['_targets'] as $target) DB::table('manager_fbs_resource_holds')->insert(['resource_key' => ManagerFbsPublicationGuard::resource('ozon', $target), 'operation_id' => $row->id, 'created_at' => now()]);
                    $token = (string) Str::uuid();
                    DB::table('manager_ozon_packing_outbox')->insert(['operation_id' => $row->id, 'payload_cipher' => Crypt::encryptString(json_encode(['bearer' => $bearer, 'token' => $token], JSON_THROW_ON_ERROR)),
                        'token_hash' => hash('sha256', $token), 'state' => 'pending', 'queue_attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
                    Item::where('operation_id', $row->id)->where('status', 'prepared')->update(['status' => 'pending']);
                    $row->update(['status' => 'executing', 'approved_at' => now(), 'heartbeat_at' => now(), 'confirmations' => ['user_id' => $actor->userId, 'values' => $checks, 'declared_at' => now()->utc()->toRfc3339String()]]);
                    $this->event($row->id, 'approved'); DB::afterCommit(fn () => $this->dispatchApproved($row->id));
                });
            } catch (QueryException $e) {
                if (in_array((string) $e->getCode(), ['23000', '23505'], true)) throw new ManagerDataException('Группа или один из заказов уже обрабатывается.', 409); throw $e;
            }
        });
        return $this->get($who, $id);
    }

    public function cancel(ManagerIdentity $who, string $id, string $hash): array
    {
        $this->permission($who); $op = $this->owned($who, $id); $this->confirm($op, $hash);
        DB::transaction(function () use ($op, $hash) {
            $row = Operation::whereKey($op->id)->lockForUpdate()->firstOrFail(); $this->confirm($row, $hash);
            if (! in_array($row->status, ['awaiting_confirmation', 'executing'], true)) return;
            $this->stopPending($row, 'remaining_stopped'); $this->aggregate($row); $this->closeOutbox($row->id);
            if (! Item::where('operation_id', $row->id)->whereNotNull('native_started_at')->exists()) $row->status = 'cancelled';
            $row->message_code = 'remaining_stopped'; $row->save(); $this->event($row->id, 'remaining_stopped');
        });
        return $this->get($who, $id);
    }

    public function reconcile(ManagerIdentity $who, string $id, callable $revalidate): array
    {
        $this->permission($who); $op = $this->owned($who, $id); $this->get($who, $id); $op->refresh();
        foreach (Item::where('operation_id', $id)->whereIn('status', ['verifying', 'unknown'])->orderBy('position')->get() as $item) {
            if (! $item->receipt) continue;
            $this->observeItem($op, $item, fn () => $revalidate());
        }
        return $this->get($who, $id);
    }

    public function dispatchApproved(string $id): bool
    {
        if (! $this->enabled()) return false;
        $cipher = DB::transaction(function () use ($id) {
            $op = Operation::whereKey($id)->lockForUpdate()->first(); $outbox = DB::table('manager_ozon_packing_outbox')->where('operation_id', $id)->lockForUpdate()->first();
            if (! $op || ! $outbox || $op->status !== 'executing' || $op->expires_at->timestamp <= now()->timestamp || $outbox->worker_claimed_at !== null || $outbox->state !== 'pending'
                || $outbox->last_queue_attempt_at !== null && CarbonImmutable::parse($outbox->last_queue_attempt_at)->gt(now()->subSeconds(30))) return null;
            $this->confirm($op, $op->fingerprint);
            DB::table('manager_ozon_packing_outbox')->where('operation_id', $id)->update(['last_queue_attempt_at' => now(), 'queue_attempts' => $outbox->queue_attempts + 1, 'updated_at' => now()]);
            return $outbox->payload_cipher;
        });
        if ($cipher === null) return false;
        try {
            $payload = json_decode(Crypt::decryptString($cipher), true, 8, JSON_THROW_ON_ERROR);
            RunManagerOzonPackingGroup::dispatch($id, $payload['token']);
            DB::table('manager_ozon_packing_outbox')->where('operation_id', $id)->update(['queued_at' => now(), 'updated_at' => now()]);
            return true;
        } catch (\Throwable) { return false; }
    }

    public function dispatchPending(int $limit = 50): int
    {
        if (! Schema::hasTable('manager_ozon_packing_outbox') || ! Schema::hasTable('manager_ozon_packing_operations')) return 0;
        $expired = DB::table('manager_ozon_packing_outbox as q')->join('manager_ozon_packing_operations as o', 'o.id', '=', 'q.operation_id')
            ->whereNotNull('q.payload_cipher')->where('o.expires_at', '<=', now())->orderBy('o.expires_at')->limit(100)->pluck('q.operation_id');
        foreach ($expired as $id) {
            $op = Operation::find($id);
            if (in_array($op->status, ['succeeded', 'failed', 'cancelled', 'expired'], true)) DB::table('manager_ozon_packing_outbox')->where('operation_id', $id)->update(['state' => 'closed', 'payload_cipher' => null, 'updated_at' => now()]);
            else $this->halt($op, 'queue_expired');
        }
        if (! $this->enabled()) return 0; $this->tables(); $n = 0;
        foreach (DB::table('manager_ozon_packing_outbox')->where('state', 'pending')->whereNull('worker_claimed_at')->orderBy('created_at')->limit(min(100, max(1, $limit)))->pluck('operation_id') as $id) {
            $op = Operation::find($id);
            if ($op && $op->expires_at->timestamp <= now()->timestamp) { $this->halt($op, 'queue_expired'); continue; }
            if ($this->dispatchApproved($id)) $n++;
        }
        return $n;
    }

    public function execute(string $id, string $token, string $claim): void
    {
        $started = false;
        try {
            $cipher = DB::transaction(function () use ($id, $token, $claim) {
                $op = Operation::whereKey($id)->lockForUpdate()->first(); $box = DB::table('manager_ozon_packing_outbox')->where('operation_id', $id)->lockForUpdate()->first();
                if (! $op || ! $box || ! Str::isUuid($claim) || ! hash_equals($box->token_hash, hash('sha256', $token)) || $box->state !== 'pending'
                    || $box->worker_claimed_at !== null || $op->status !== 'executing' || $op->approved_at === null) return null;
                $this->confirm($op, $op->fingerprint);
                DB::table('manager_ozon_packing_outbox')->where('operation_id', $id)->update(['state' => 'claimed', 'worker_claimed_at' => now(), 'worker_claim_id' => $claim, 'updated_at' => now()]);
                $op->update(['heartbeat_at' => now()]); $this->event($id, 'worker_claimed'); return $box->payload_cipher;
            });
            if ($cipher === null) return; $started = true; $op = Operation::findOrFail($id); $this->available();
            $payload = json_decode(Crypt::decryptString($cipher), true, 8, JSON_THROW_ON_ERROR);
            if (! is_string($payload['bearer'] ?? null) || ! $payload['bearer'] || strlen($payload['bearer']) > 16384 || ($payload['token'] ?? null) !== $token) throw new ManagerDataException('Личный контекст исполнения повреждён.', 409);
            $request = Request::create('/manager/ozon-packing/worker', 'POST'); $request->headers->set('Authorization', 'Bearer '.$payload['bearer']); $request->headers->set('X-Sellico-Workspace', (string) $op->workspace_id);
            $fresh = fn (bool $write = false) => $this->sameActor($op, app(ManagerSessionVerifier::class)->verify($request), $write);
            $this->consent($op); $this->checkItems($op); $who = $fresh(true); $store = $this->store($who, (int) $op->integration_id); $this->sameStore($op, $store);
            $this->guard->runMany('ozon', $op->plan['_targets'], function () use ($op, $token, $claim, $fresh) {
                if (! $this->holdsComplete($op->id, $op->plan['_targets'])) throw new ManagerDataException('Управление группой изменилось.', 409);
                // Validate the complete approved group before any source command.
                foreach ($op->plan['sources'] as $source) {
                    $this->workerReady($op, $token, $claim); $who = $fresh(true); $store = $this->store($who, (int) $op->integration_id); $this->sameStore($op, $store);
                    $current = $this->gateway->source($store, ['posting_number' => $source['posting']['external_id'], 'packages' => $source['packages']]);
                    if ($this->canonical($source) !== $this->canonical($current)) throw new ManagerDataException('Данные группы изменились.', 409);
                    $this->heartbeat($op->id);
                }
                foreach (Item::where('operation_id', $op->id)->orderBy('position')->get() as $item) {
                    if ($item->status !== 'pending') continue;
                    $this->workerReady($op, $token, $claim); $this->checkItem($op, $item); $who = $fresh(true); $store = $this->store($who, (int) $op->integration_id); $this->sameStore($op, $store);
                    $source = $item->plan; $current = $this->gateway->source($store, ['posting_number' => $item->source_posting_number, 'packages' => $source['packages']]);
                    if ($this->canonical($source) !== $this->canonical($current)) throw new ManagerDataException('Исходное отправление изменилось.', 409);
                    $who = $fresh(true); $store = $this->store($who, (int) $op->integration_id); $this->sameStore($op, $store); $this->workerReady($op, $token, $claim);
                    $marked = DB::transaction(function () use ($op, $item, $token, $claim) {
                        $this->workerReady($op, $token, $claim); $row = Item::whereKey($item->id)->lockForUpdate()->firstOrFail(); $this->checkItem($op, $row);
                        if ($row->status !== 'pending' || $row->native_started_at !== null || ! $this->holdsComplete($op->id, $this->itemTargets($row))) return false;
                        $row->update(['status' => 'executing', 'native_started_at' => now()]); $this->heartbeat($op->id); $this->event($op->id, 'native_started', $row->id); return true;
                    });
                    if (! $marked) break;
                    $receipt = $this->gateway->submit($store, $source);
                    $this->recordReceipt($op, $item, $receipt, $token, $claim); $item->refresh();
                    if ($receipt['status'] === 'verifying') $this->observeItem($op, $item, $fresh);
                    $item->refresh();
                    if (in_array($item->status, ['unknown', 'failed'], true)) break;
                }
            }, $op->id);
            $this->finishExecution($id, $token, $claim, 'execution_finished');
        } catch (\Throwable) {
            if ($started) $this->failed($id, $token, $claim);
        }
    }

    public function failed(string $id, string $token, string $claim): void
    {
        $this->finishExecution($id, $token, $claim, 'worker_stopped');
    }

    private function workerReady(Operation $op, string $token, string $claim): void
    {
        $this->available(); $current = $op->fresh(); $this->confirm($current, $op->fingerprint);
        if ($current->status !== 'executing' || $current->expires_at->timestamp <= now()->timestamp || ! $this->workerOwns($op->id, $token, $claim)) throw new ManagerDataException('Разрешение группы или исполнитель изменились.', 409);
        $this->consent($current);
    }
    private function workerOwns(string $id, string $token, string $claim): bool
    {
        return DB::table('manager_ozon_packing_outbox')->where('operation_id', $id)->where('state', 'claimed')->where('worker_claim_id', $claim)->where('token_hash', hash('sha256', $token))->exists();
    }
    private function consent(Operation $op): void
    {
        $c = $op->confirmations; $values = $c['values'] ?? null;
        if (($c['user_id'] ?? null) !== (int) $op->user_id || ! is_array($values) || count($values) !== 4 || array_diff(array_keys($values), self::CHECKS) || array_filter($values, fn ($v) => $v !== true)) throw new ManagerDataException('Личное согласование группы не подтверждено.', 409);
    }
    private function checkItem(Operation $op, Item $item): void
    {
        if ($item->operation_id !== $op->id || ! isset($op->plan['sources'][$item->position]) || $item->source_posting_number !== $op->plan['sources'][$item->position]['posting']['external_id']
            || $this->canonical($item->plan) !== $this->canonical($op->plan['sources'][$item->position])) throw new ManagerDataException('Состав отдельного отправления изменился.', 409);
    }
    private function checkItems(Operation $op): void
    {
        $items = Item::where('operation_id', $op->id)->orderBy('position')->get();
        if ($items->count() !== $op->plan['source_count']) throw new ManagerDataException('Журнал группы неполон.', 409);
        foreach ($items as $i => $item) {
            $this->checkItem($op, $item);
            if ($item->position !== $i || $item->status !== 'pending' || $item->native_started_at !== null || $item->receipt !== null) throw new ManagerDataException('Группа уже начинала исполнение или изменилась.', 409);
        }
    }
    private function heartbeat(string $id): void { Operation::whereKey($id)->update(['heartbeat_at' => now()]); }

    private function closeOutbox(string $id): void
    {
        DB::table('manager_ozon_packing_outbox')->where('operation_id', $id)->update(['state' => 'closed', 'payload_cipher' => null, 'updated_at' => now()]);
    }

    private function recordReceipt(Operation $op, Item $item, array $receipt, string $token, string $claim): void
    {
        DB::transaction(function () use ($op, $item, $receipt, $token, $claim) {
            $current = Operation::whereKey($op->id)->lockForUpdate()->firstOrFail(); $this->confirm($current, $op->fingerprint);
            $box = DB::table('manager_ozon_packing_outbox')->where('operation_id', $op->id)->first();
            if (! $box || $box->worker_claim_id !== $claim || ! hash_equals($box->token_hash, hash('sha256', $token))) return;
            $row = Item::whereKey($item->id)->lockForUpdate()->firstOrFail(); $this->checkItem($op, $row);
            if ($row->native_started_at === null || $row->receipt !== null) return;
            $row->receipt = $receipt; $row->status = $receipt['status']; $row->message_code = ($receipt['stopped_before_native'] ?? false) ? 'stopped_before_native' : ($receipt['status'] === 'unknown' ? 'native_response_unknown' : ($receipt['status'] === 'failed' ? 'native_refused' : 'receipt_recorded')); $row->save();
            if ($row->status !== 'failed' && ! $this->receiptHolds($op, $row)) $row->update(['status' => 'unknown', 'message_code' => 'control_conflict']);
            if (in_array($row->status, ['failed', 'unknown'], true)) { $this->stopPending($current, 'stopped_after_problem'); $this->closeOutbox($op->id); }
            $this->event($op->id, 'receipt_'.$row->status, $row->id); $this->aggregate($current);
        });
    }

    private function observeItem(Operation $op, Item $item, callable $fresh): void
    {
        $this->checkItem($op, $item); $who = $this->sameActor($op, $fresh()); $store = $this->store($who, (int) $op->integration_id); $this->sameStore($op, $store);
        $receipt = $item->receipt; if (! $receipt) return;
        $observed = $this->gateway->observe($store, $item->plan, $receipt);
        $who = $this->sameActor($op, $fresh()); $store = $this->store($who, (int) $op->integration_id); $this->sameStore($op, $store);
        DB::transaction(function () use ($op, $item, $receipt, $observed) {
            $current = Operation::whereKey($op->id)->lockForUpdate()->firstOrFail(); $this->confirm($current, $op->fingerprint);
            $row = Item::whereKey($item->id)->lockForUpdate()->firstOrFail(); $this->checkItem($op, $row);
            if (! in_array($row->status, ['verifying', 'unknown'], true) || $this->canonical($row->receipt) !== $this->canonical($receipt)) return;
            $completeHolds = $this->receiptHolds($op, $row);
            $row->update(['status' => $completeHolds ? $observed['status'] : 'unknown', 'result' => $observed,
                'message_code' => ! $completeHolds ? 'control_conflict' : ($observed['status'] === 'succeeded' ? 'verified' : ($observed['status'] === 'failed' ? 'ship_failed' : 'result_unverified'))]);
            if (in_array($row->status, ['failed', 'unknown'], true)) { $this->stopPending($current, 'stopped_after_problem'); $this->closeOutbox($op->id); }
            $this->event($op->id, 'observed_'.$row->status, $row->id); $this->aggregate($current);
        });
    }

    private function receiptHolds(Operation $op, Item $item): bool
    {
        foreach ($item->receipt['posting_numbers'] ?? [] as $number) DB::table('manager_fbs_resource_holds')->insertOrIgnore([
            'resource_key' => ManagerFbsPublicationGuard::resource('ozon', $number), 'operation_id' => $op->id, 'created_at' => now()]);
        return $this->holdsComplete($op->id, $this->itemTargets($item));
    }
    private function itemTargets(Item $item): array { return array_values(array_unique([$item->source_posting_number, $item->plan['_order_target'], ...($item->receipt['posting_numbers'] ?? [])])); }
    private function holdsComplete(string $id, array $targets): bool
    {
        $keys = array_values(array_unique(array_map(fn ($v) => ManagerFbsPublicationGuard::resource('ozon', $v), $targets)));
        return DB::table('manager_fbs_resource_holds')->where('operation_id', $id)->whereIn('resource_key', $keys)->count() === count($keys);
    }

    private function finishExecution(string $id, string $token, string $claim, string $reason): void
    {
        DB::transaction(function () use ($id, $token, $claim, $reason) {
            $op = Operation::whereKey($id)->lockForUpdate()->first();
            if (! $op || ! $this->workerOwns($id, $token, $claim)) return;
            $this->stopPending($op, $reason); $this->aggregate($op);
            DB::table('manager_ozon_packing_outbox')->where('operation_id', $id)->update(['state' => 'closed', 'payload_cipher' => null, 'updated_at' => now()]);
            $this->event($id, $reason);
        });
    }
    private function halt(Operation $op, string $reason, ?string $terminal = null): void
    {
        DB::transaction(function () use ($op, $reason, $terminal) {
            $row = Operation::whereKey($op->id)->lockForUpdate()->firstOrFail();
            if ($terminal !== null && $row->status !== 'awaiting_confirmation') return;
            $this->stopPending($row, $reason); $this->aggregate($row);
            if ($terminal !== null) $row->update(['status' => $terminal, 'message_code' => $reason]);
            DB::table('manager_ozon_packing_outbox')->where('operation_id', $op->id)->update(['state' => 'closed', 'payload_cipher' => null, 'updated_at' => now()]);
            $this->event($op->id, $reason);
        });
    }
    private function stopPending(Operation $op, string $reason): void
    {
        foreach (Item::where('operation_id', $op->id)->whereIn('status', ['prepared', 'pending', 'executing'])->lockForUpdate()->get() as $item) {
            $item->update(['status' => $item->native_started_at === null ? 'not_attempted' : 'unknown', 'message_code' => $reason]);
        }
    }
    private function aggregate(Operation $op): void
    {
        $items = Item::where('operation_id', $op->id)->orderBy('position')->get(); $active = []; $statuses = $items->pluck('status')->all();
        foreach ($items as $item) if (in_array($item->status, self::ACTIVE, true)) $active = array_merge($active, $this->itemTargets($item));
        $keys = array_values(array_unique(array_map(fn ($v) => ManagerFbsPublicationGuard::resource('ozon', $v), $active)));
        $query = DB::table('manager_fbs_resource_holds')->where('operation_id', $op->id); if ($keys) $query->whereNotIn('resource_key', $keys); $query->delete();
        $allSucceeded = $items->count() > 0 && $items->count() === $op->plan['source_count'] && count(array_filter($statuses, fn ($s) => $s === 'succeeded')) === count($statuses);
        $status = in_array('unknown', $statuses, true) ? 'unknown' : (array_intersect($statuses, ['prepared', 'pending', 'executing']) ? 'executing' : (in_array('verifying', $statuses, true) ? 'verifying' : ($allSucceeded ? 'succeeded' : 'failed')));
        $op->update(['status' => $status, 'heartbeat_at' => now(), 'message_code' => $status === 'succeeded' ? 'verified' : ($status === 'failed' ? 'group_incomplete' : 'result_unverified')]);
    }

    private function result(Operation $op): array
    {
        $items = []; $verifiedPackages = 0; $counts = array_fill_keys(['attempted', 'succeeded', 'failed', 'unknown', 'verifying', 'not_attempted', 'pending'], 0);
        $held = DB::table('manager_fbs_resource_holds')->where('operation_id', $op->id)->pluck('resource_key')->all();
        $rows = Item::where('operation_id', $op->id)->orderBy('position')->get();
        if ($rows->count() !== $op->plan['source_count']) throw new ManagerDataException('Журнал группы неполон. Нужна проверка сохранённых данных; повторная сборка запрещена.', 409);
        foreach ($rows as $item) {
            $status = in_array($item->status, ['prepared', 'executing'], true) ? 'pending' : $item->status; $counts[$status]++;
            if ($item->native_started_at !== null) $counts['attempted']++;
            $verifiedPackages += count(array_filter($item->result['observed_postings'] ?? [], fn ($p) => ($p['assembly_verified'] ?? false) === true));
            $items[] = ['source_posting_number' => $item->source_posting_number, 'status' => $status, 'native_started' => $item->native_started_at !== null,
                'submission_acknowledged' => ($item->receipt['acknowledged'] ?? false) === true, 'receipt_posting_numbers' => $item->receipt['posting_numbers'] ?? [],
                'fields_verified' => $item->status === 'succeeded' && ($item->result['fields_verified'] ?? false) === true,
                'composition_verified' => ($item->result['composition_verified'] ?? false) === true, 'observed_postings' => $item->result['observed_postings'] ?? [],
                'observed_at' => $item->result['observed_at'] ?? null, 'control_hold_active' => in_array($item->status, self::ACTIVE, true) && (bool) array_intersect($held, array_map(fn ($target) => ManagerFbsPublicationGuard::resource('ozon', $target), $this->itemTargets($item))), 'message_code' => $item->message_code];
        }
        $outcome = $op->status === 'succeeded' ? 'complete' : (in_array($op->status, ['unknown', 'verifying'], true) ? 'unresolved' : ($counts['pending'] ? 'pending' : ($counts['succeeded'] || $verifiedPackages ? 'partial' : 'none')));
        return ['submission_acknowledged' => $counts['attempted'] === count($items) && ! array_filter($items, fn ($i) => ! $i['submission_acknowledged']),
            'fields_verified' => $op->status === 'succeeded', 'control_hold_active' => (bool) $held,
            'group_outcome' => $outcome, 'verified_package_count' => $verifiedPackages, 'source_count' => count($items), ...array_combine(array_map(fn ($s) => $s.'_count', array_keys($counts)), array_values($counts)),
            'items' => $items, 'physical_status' => null, 'actual_cost_verified' => false];
    }

    private function owned(ManagerIdentity $who, string $id): Operation
    {
        $this->tables(); $op = Operation::whereKey($id)->where('workspace_id', $who->workspaceId)->where('user_id', $who->userId)->first();
        if (! $op) throw new ManagerDataException('Группа не найдена.', 404);
        $this->confirm($op, $op->fingerprint); return $op;
    }
    private function sameActor(Operation $op, mixed $who, bool $write = false): ManagerIdentity
    {
        if (! $who instanceof ManagerIdentity || $who->workspaceId !== (int) $op->workspace_id || $who->userId !== (int) $op->user_id) throw new ManagerDataException('Личная сессия изменилась.', 403);
        $this->permission($who, $write); return $who;
    }
    private function checkReadScope(ManagerIdentity $expected, ManagerStore $store, mixed $who, bool $write = false): void
    {
        if (! $who instanceof ManagerIdentity || $who->workspaceId !== $expected->workspaceId || $who->userId !== $expected->userId) throw new ManagerDataException('Личная сессия изменилась.', 403);
        $this->permission($who, $write); $current = $this->store($who, $store->integrationId);
        if ($current->binding !== $store->binding || $current->name !== $store->name) throw new ManagerDataException('Магазин или ключ изменились во время чтения.', 409);
    }
    private function sameStore(Operation $op, ManagerStore $store): void
    {
        if ($store->binding !== $op->store_binding || $store->name !== $op->store_name) throw new ManagerDataException('Магазин или ключ изменились.', 409);
    }
    private function enabled(): bool { return config('services.manager.fbs_writes_enabled', true) && config('services.manager.ozon_packing_enabled', false) && config('services.manager.ozon_packing_outbox_enabled', false); }
    private function available(): void { $this->tables(); if (! $this->enabled()) throw new ManagerDataException('Групповая сборка Ozon остановлена администратором.', 503); }
    private function tables(): void
    {
        foreach (['manager_ozon_packing_operations', 'manager_ozon_packing_items', 'manager_ozon_packing_outbox', 'manager_ozon_packing_events', 'manager_fbs_resource_holds'] as $table)
            if (! Schema::hasTable($table)) throw new ManagerDataException('Журнал групповой сборки Ozon не установлен.', 503);
    }
    private function fingerprint(Operation $op): string
    {
        return hash('sha256', $this->canonical(['ozon-packing-v1', $op->id, (int) $op->workspace_id, (int) $op->user_id, (int) $op->integration_id,
            $op->store_name, $op->store_binding, $op->plan, $op->created_at->timestamp, $op->expires_at->timestamp]));
    }
    private function confirm(Operation $op, string $hash): void
    {
        if (! hash_equals($op->fingerprint, $hash) || ! hash_equals($op->fingerprint, $this->fingerprint($op))) throw new ManagerDataException('Неизменяемое предложение группы повреждено или изменилось.', 409);
    }
    private function event(string $id, string $status, ?string $item = null): void { DB::table('manager_ozon_packing_events')->insert(['operation_id' => $id, 'item_id' => $item, 'status' => $status, 'created_at' => now()]); }

    private function expose(Operation $op): array
    {
        return ['id' => $op->id, 'workspace_id' => (string) $op->workspace_id, 'integration_id' => (string) $op->integration_id, 'integration_name' => $op->store_name,
            'marketplace' => 'ozon', 'operation_type' => ManagerOzonPackingGateway::TYPE, 'fingerprint' => $op->fingerprint, 'status' => $op->status,
            'group' => $this->gateway->publicPlan($op->plan), 'required_confirmations' => array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], self::CHECKS, [
                'Проверил магазин и все исходные отправления группы.', 'Проверил товары, количества и состав каждой упаковки.',
                'Проверил физическую готовность и требования маркировки в разделе заказов.', 'Лично разрешаю отдельную сборку каждого отправления по API. Возможен частичный результат; неизвестная команда не повторяется. Физическая передача не подтверждается.']),
            'result' => $op->status === 'awaiting_confirmation' ? null : $this->result($op), 'message_code' => $op->message_code, 'physical_status' => null,
            'created_at' => $op->created_at->utc()->toRfc3339String(), 'expires_at' => $op->expires_at->utc()->toRfc3339String()];
    }
    private function permission(ManagerIdentity $who, bool $write = false): void
    {
        foreach (['auto_supply.view', 'integrations.view', ...($write ? ['auto_supply.create'] : [])] as $p) if (! $who->permits($p)) throw new ManagerDataException('Недостаточно прав на сборку Ozon.', 403);
        if ($write && ! $who->personalConfirmed) throw new ManagerDataException('Нужна явно подтверждённая личная сессия.', 403);
    }
    private function store(ManagerIdentity $who, int $id): ManagerStore
    {
        $local = Integration::whereKey($id)->where('work_space_id', $who->workspaceId)->first();
        if (! $local) throw new ManagerDataException('Магазин не найден.', 404);
        if (strtolower((string) $local->marketplace) !== 'ozon') throw new ManagerDataException('Групповая сборка подключена для Ozon FBS.', 422);
        return $this->stores->resolve($who, $id, 'ozon');
    }
    private function contextHash(ManagerIdentity $w, ManagerStore $s, array $p): string { return hash('sha256', $this->canonical([$w->workspaceId, $w->userId, $s->integrationId, $s->name, $s->binding, $p])); }
    private function canonical(mixed $v): string
    {
        $normalize = function ($v) use (&$normalize) {
            if (is_object($v)) $v = (array) $v;
            if (! is_array($v)) return $v;
            if (! array_is_list($v)) ksort($v, SORT_STRING);
            return array_map($normalize, $v);
        };
        return json_encode($normalize($v), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
