<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Integration;
use App\Models\ManagerOzonCarriageOperation as Operation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ManagerOzonCarriageService
{
    public const CHECKS = ['carriage_scope_checked', 'ready_items_checked', 'pickup_cost_checked', 'physical_effect_checked'];
    public function __construct(private ManagerStoreResolver $stores, private ManagerOzonCarriageGateway $gateway, private ManagerFbsPublicationGuard $guard) {}

    public function methods(ManagerIdentity $who, int $integrationId, string $date): array
    {
        $this->permission($who); $s = $this->store($who, $integrationId);
        return ['workspace_id' => (string) $who->workspaceId, 'integration_id' => (string) $integrationId, 'integration_name' => $s->name,
            'marketplace' => 'ozon', 'source' => 'Ozon Seller API', 'departure_date' => $date, 'methods' => $this->gateway->methods($s, $date),
            'completeness' => 'complete', 'physical_status' => null, 'observed_at' => now()->utc()->toRfc3339String()];
    }

    public function context(ManagerIdentity $who, int $integrationId, array $input): array
    {
        $this->permission($who); $s = $this->store($who, $integrationId); $plan = $this->plan($who, $s, $input);
        return ['workspace_id' => (string) $who->workspaceId, 'integration_id' => (string) $integrationId, 'integration_name' => $s->name,
            'marketplace' => 'ozon', 'source' => 'Ozon Seller API', 'context_fingerprint' => $this->contextHash($who, $s, $plan),
            'selection' => $input, 'carriage' => $this->publicPlan($plan), 'completeness' => 'complete', 'physical_status' => null, 'observed_at' => now()->utc()->toRfc3339String()];
    }

    public function prepare(ManagerIdentity $who, int $integrationId, array $input, string $contextHash): array
    {
        $this->permission($who, true); $this->available(); $s = $this->store($who, $integrationId); $plan = $this->plan($who, $s, $input);
        if (! hash_equals($this->contextHash($who, $s, $plan), $contextHash)) throw new ManagerDataException('Состав или параметры перевозки изменились. Нужен свежий контекст.', 409);
        $id = (string) Str::uuid();
        $row = DB::transaction(function () use ($who, $s, $plan, $id) {
            $row = Operation::create(['id' => $id, 'workspace_id' => $who->workspaceId, 'user_id' => $who->userId, 'integration_id' => $s->integrationId,
                'store_name' => $s->name, 'store_binding' => $s->binding, 'operation_type' => $plan['operation_type'], 'plan' => $plan,
                'status' => 'awaiting_confirmation', 'fingerprint' => str_repeat('0', 64), 'expires_at' => now()->startOfSecond()->addMinutes(5)]);
            $row->update(['fingerprint' => $this->fingerprint($row)]); $this->event($id, 'awaiting_confirmation'); return $row;
        });
        return $this->expose($row);
    }

    public function get(ManagerIdentity $who, string $id): Operation
    {
        $this->permission($who); $this->tables();
        $r = Operation::whereKey($id)->where('workspace_id', $who->workspaceId)->where('user_id', $who->userId)->first();
        if (! $r) throw new ManagerDataException('Операция перевозки не найдена.', 404);
        $this->confirm($r, $r->fingerprint);
        if ($r->status === 'awaiting_confirmation' && $r->expires_at->timestamp <= now()->timestamp) $this->finish($r, 'expired', 'Срок личного согласования истёк.', false);
        if ($r->status === 'executing' && $r->native_started_at === null && $r->expires_at->timestamp <= now()->timestamp) $this->finish($r, 'failed', 'Срок истёк до отправки. Команда площадке не запускалась.', true);
        if ($r->status === 'executing' && $r->native_started_at !== null && $r->native_started_at->lt(now()->subMinutes(3))) {
            $this->update($r, ['executing'], ['status' => 'unknown', 'message' => 'Ответ выполнения не подтверждён. Повторная команда заблокирована.']);
        }
        return $r->fresh();
    }

    public function cancel(ManagerIdentity $who, string $id, string $fingerprint): array
    {
        $r = $this->get($who, $id); $this->confirm($r, $fingerprint);
        if ($r->status === 'awaiting_confirmation') $this->finish($r, 'cancelled', 'Карточка согласования отменена. Перевозка не отменялась.', false);
        return $this->expose($this->get($who, $id));
    }

    public function approve(ManagerIdentity $who, string $id, string $fingerprint, array $checks, callable $revalidate): array
    {
        $this->permission($who, true); $this->available(); $r = $this->get($who, $id); $this->confirm($r, $fingerprint);
        if ($r->status !== 'awaiting_confirmation') return $this->expose($r);
        if (count($checks) !== 4 || array_diff(array_keys($checks), self::CHECKS) || array_filter($checks, fn ($v) => $v !== true)) throw new ManagerDataException('Нужны все четыре личные проверки.', 422);
        $targets = $this->targets($r->plan); $parentId = $r->plan['parent_operation_id'];
        return $this->guard->runMany('ozon', $targets, function () use ($who, $r, $checks, $revalidate, $parentId, $targets) {
            $who = $this->fresh($who, $revalidate); $s = $this->store($who, (int) $r->integration_id);
            $this->sameStore($r, $s); $this->confirm($r, $r->fingerprint);
            try {
                $claimed = DB::transaction(function () use ($who, $r, $checks, $parentId, $targets) {
                    $current = Operation::whereKey($r->id)->lockForUpdate()->firstOrFail(); $this->confirm($current, $r->fingerprint);
                    if ($current->status !== 'awaiting_confirmation' || $current->expires_at->timestamp <= now()->timestamp) return false;
                    if ($parentId !== null) {
                        $parent = Operation::whereKey($parentId)->lockForUpdate()->first();
                        $this->parent($parent, $who, $current->store_binding, $current->plan['carriage_id']);
                        if ($parent->child_operation_id !== null) throw new ManagerDataException('Продолжение перевозки уже выполняется.', 409);
                        DB::table('manager_fbs_resource_holds')->where('operation_id', $parentId)->update(['operation_id' => $r->id]);
                        $parent->update(['child_operation_id' => $r->id]); $this->event($parentId, 'control_transferred');
                    }
                    foreach ($targets as $target) {
                        $key = ManagerFbsPublicationGuard::resource('ozon', $target);
                        $held = DB::table('manager_fbs_resource_holds')->where('resource_key', $key)->first();
                        if ($held && $held->operation_id !== $r->id) throw new ManagerDataException('Один из заказов уже занят другой операцией.', 409);
                        if (! $held) DB::table('manager_fbs_resource_holds')->insert(['resource_key' => $key, 'operation_id' => $r->id, 'created_at' => now()]);
                    }
                    $current->update(['status' => 'executing', 'execution_started_at' => now(), 'confirmations' => ['user_id' => $who->userId, 'values' => $checks, 'declared_at' => now()->utc()->toRfc3339String()]]);
                    $this->event($r->id, 'executing'); return true;
                });
            } catch (QueryException $e) {
                if (in_array((string) $e->getCode(), ['23000', '23505'], true)) throw new ManagerDataException('Один из заказов уже занят другой операцией.', 409);
                throw $e;
            }
            if (! $claimed) return $this->expose($this->get($who, $r->id));
            try {
                $who = $this->fresh($who, $revalidate); $s = $this->store($who, (int) $r->integration_id); $this->sameStore($r, $s);
                $currentPlan = $this->plan($who, $s, $r->plan['selection'], $r->id, $parentId);
                if ($this->canonical($currentPlan) !== $this->canonical($r->plan)) throw new ManagerDataException('Состав, готовность, место, стоимость или параметры изменились.', 422);
                $who = $this->fresh($who, $revalidate); $s = $this->store($who, (int) $r->integration_id); $this->sameStore($r, $s);
                $who = $this->fresh($who, $revalidate); $this->available(); $this->confirm($r->fresh(), $r->fingerprint);
            } catch (ManagerDataException $e) {
                $this->finish($r, 'failed', 'Проверка перед отправкой не пройдена. Команда Ozon не запускалась.', true);
                if (in_array($e->httpStatus, [401, 403, 404], true)) throw $e;
                return $this->expose($this->get($who, $r->id));
            }
            $started = DB::transaction(function () use ($r, $targets) {
                $current = Operation::whereKey($r->id)->lockForUpdate()->firstOrFail(); $this->confirm($current, $r->fingerprint);
                $keys = array_map(fn ($v) => ManagerFbsPublicationGuard::resource('ozon', $v), $targets);
                if ($current->status !== 'executing' || $current->native_started_at !== null || $current->expires_at->timestamp <= now()->timestamp
                    || DB::table('manager_fbs_resource_holds')->where('operation_id', $r->id)->whereIn('resource_key', $keys)->count() !== count($keys)) return false;
                $current->update(['native_started_at' => now()]); $this->event($r->id, 'native_started'); return true;
            });
            if (! $started) { $this->finish($r, 'failed', 'Отправка не началась до срока согласования.', true); return $this->expose($this->get($who, $r->id)); }
            $receipt = $this->gateway->submit($s, $r->plan);
            $result = ['submission_acknowledged' => $receipt['acknowledged'], 'fields_verified' => false, 'carriage_id' => $receipt['carriage_id'],
                'carriage_created_verified' => false, 'confirmation_verified' => false, 'composition_verified' => false,
                'actual_fee_verified' => false, 'physical_status' => null];
            if ($receipt['status'] === 'failed') {
                $this->finish($r, 'failed', ($receipt['stopped_before_native'] ?? false) ? 'Отправка остановлена ограничением FBS. Запрос на площадку не отправлен.' : 'Ozon отклонил запрос. Изменение не подтверждено.', false, true, $result);
            } else {
                $this->update($r, ['executing', 'unknown'], ['status' => $receipt['status'], 'native_carriage_id' => $receipt['carriage_id'], 'result' => $result,
                    'message' => $receipt['status'] === 'verifying' ? 'Ozon принял запрос. Проверяется фактический состав и состояние.' : 'Ответ на команду неизвестен. Повторная отправка заблокирована.']);
            }
            return $this->reconcile($who, $r->id);
        }, $r->id, $parentId);
    }

    public function reconcile(ManagerIdentity $who, string $id): array
    {
        $r = $this->get($who, $id);
        if (! in_array($r->status, ['verifying', 'unknown'], true)) return $this->expose($r);
        $receiptId = $r->native_carriage_id ?? $r->plan['carriage_id'];
        if ($receiptId === null) return $this->expose($r); // A matching new carriage is not a receipt for a lost create response.
        try {
            $s = $this->store($who, (int) $r->integration_id); $this->sameStore($r, $s); $seen = $this->gateway->observe($s, $r->plan, $receiptId);
            $ack = ($r->result['submission_acknowledged'] ?? false) === true;
            $verified = $r->operation_type === 'fbs_carriage_create' ? $seen['carriage_created_verified'] : $seen['confirmation_verified'];
            $result = ['submission_acknowledged' => $ack, 'fields_verified' => $verified, 'carriage_id' => $receiptId,
                ...$seen, 'actual_fee_verified' => false, 'physical_status' => null];
            $status = $ack && $verified ? 'succeeded' : ($ack && $r->execution_started_at?->gt(now()->subMinutes(15)) ? 'verifying' : 'unknown');
            $this->update($r, ['verifying', 'unknown'], ['status' => $status, 'result' => $result,
                'message' => $status === 'succeeded' ? ($r->operation_type === 'fbs_carriage_create' ? 'Создана новая перевозка, состав подтверждён API. Подтверждение перевозки — отдельный шаг.' : 'Подтверждение перевозки и её состав проверены API. Физическая передача и окончательная стоимость не подтверждены.')
                    : (! $ack ? 'API показывает текущее состояние, но приём нашей команды неизвестен. Повтор запрещён.' : 'Ожидаемые состав и состояние ещё не подтверждены. Повтор команды запрещён.')],
                $status === 'succeeded' && $r->operation_type === 'fbs_carriage_approve');
        } catch (ManagerDataException) { /* Preserve the receipt and every hold when fresh readback cannot be verified. */ }
        return $this->expose($this->get($who, $id));
    }

    public function expose(Operation $r): array
    {
        return ['id' => $r->id, 'operation_type' => $r->operation_type, 'workspace_id' => (string) $r->workspace_id,
            'integration_id' => (string) $r->integration_id, 'integration_name' => $r->store_name, 'marketplace' => 'ozon', 'fingerprint' => $r->fingerprint,
            'status' => $r->status, 'carriage' => $this->publicPlan($r->plan), 'required_confirmations' => $this->checks($r->operation_type),
            'result' => $r->result === null ? null : $r->result + ['control_hold_active' => DB::table('manager_fbs_resource_holds')->where('operation_id', $r->id)->exists(), 'child_operation_exists' => $r->child_operation_id !== null],
            'message' => $r->message, 'physical_status' => null, 'created_at' => $r->created_at->utc()->toRfc3339String(), 'expires_at' => $r->expires_at->utc()->toRfc3339String()];
    }

    private function plan(ManagerIdentity $who, ManagerStore $s, array $input, ?string $own = null, ?string $expectedParent = null): array
    {
        $plan = $this->gateway->context($s, $input); $parent = null;
        if (Schema::hasTable('manager_fbs_resource_holds')) {
            $hold = DB::table('manager_fbs_resource_holds')->where('resource_key', ManagerFbsPublicationGuard::resource('ozon', $this->methodTarget($plan)))->first();
            if ($hold && $hold->operation_id !== $own) {
                if ($plan['operation_type'] !== 'fbs_carriage_approve') throw new ManagerDataException('Для этого метода есть незавершённая перевозка.', 409);
                $p = Operation::find($hold->operation_id); $this->parent($p, $who, $s->binding, $plan['carriage_id']); $parent = $p->id;
            } elseif ($own !== null) $parent = $expectedParent;
        }
        return $plan + ['selection' => $input, 'parent_operation_id' => $parent];
    }

    private function parent(?Operation $p, ManagerIdentity $who, string $binding, ?string $carriage): void
    {
        if (! $p || (int) $p->workspace_id !== $who->workspaceId || (int) $p->user_id !== $who->userId || $p->store_binding !== $binding
            || $p->operation_type !== 'fbs_carriage_create' || ! in_array($p->status, ['succeeded', 'verifying', 'unknown'], true)
            || ($p->result['submission_acknowledged'] ?? false) !== true || $p->native_carriage_id !== $carriage) throw new ManagerDataException('Управление перевозкой занято или не имеет подтверждённой квитанции создания.', 409);
        $this->confirm($p, $p->fingerprint);
    }

    private function finish(Operation $operation, string $status, string $message, bool $beforeNative, bool $knownRejected = false, ?array $receiptResult = null): void
    {
        DB::transaction(function () use ($operation, $status, $message, $beforeNative, $knownRejected, $receiptResult) {
            $r = Operation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($beforeNative && ($r->native_started_at !== null || ! in_array($r->status, ['executing', 'awaiting_confirmation'], true))) return;
            if (! $beforeNative && ! $knownRejected && $r->status !== 'awaiting_confirmation') return;
            if ($knownRejected && ! in_array($r->status, ['executing', 'unknown'], true)) return;
            $parent = $r->plan['parent_operation_id'];
            if ($parent !== null && DB::table('manager_fbs_resource_holds')->where('operation_id', $r->id)->exists()) {
                DB::table('manager_fbs_resource_holds')->where('operation_id', $r->id)->update(['operation_id' => $parent]);
                Operation::whereKey($parent)->where('child_operation_id', $r->id)->update(['child_operation_id' => null]); $this->event($parent, 'control_restored');
            } else DB::table('manager_fbs_resource_holds')->where('operation_id', $r->id)->delete();
            $r->update(['status' => $status, 'message' => $message, ...($beforeNative ? ['result' => ['submission_acknowledged' => false, 'fields_verified' => false, 'carriage_id' => null,
                'carriage_created_verified' => false, 'confirmation_verified' => false, 'composition_verified' => false, 'native_attempted' => false, 'actual_fee_verified' => false, 'physical_status' => null]] : []),
                ...($receiptResult === null ? [] : ['result' => $receiptResult])]);
            $this->event($r->id, $status);
        });
    }

    private function update(Operation $r, array $from, array $values, bool $release = false): void
    {
        DB::transaction(function () use ($r, $from, $values, $release) {
            if (Operation::whereKey($r->id)->whereIn('status', $from)->update($values)) {
                $this->event($r->id, $values['status'] ?? 'updated');
                if ($release) DB::table('manager_fbs_resource_holds')->where('operation_id', $r->id)->delete();
            }
        });
    }
    private function fresh(ManagerIdentity $expected, callable $callback): ManagerIdentity
    {
        $who = $callback(); if (! $who instanceof ManagerIdentity || $who->workspaceId !== $expected->workspaceId || $who->userId !== $expected->userId) throw new ManagerDataException('Личная сессия изменилась.', 403);
        $this->permission($who, true); return $who;
    }
    private function store(ManagerIdentity $who, int $id): ManagerStore
    {
        $local = Integration::whereKey($id)->where('work_space_id', $who->workspaceId)->first();
        if (! $local) throw new ManagerDataException('Магазин не найден.', 404);
        if (strtolower((string) $local->marketplace) !== 'ozon') throw new ManagerDataException('Перевозки подключены для Ozon FBS.', 422);
        return $this->stores->resolve($who, $id, 'ozon');
    }
    private function sameStore(Operation $r, ManagerStore $s): void { if ($s->binding !== $r->store_binding || $s->name !== $r->store_name) throw new ManagerDataException('Доступ или магазин изменились.', 409); }
    private function permission(ManagerIdentity $who, bool $write = false): void
    {
        foreach (['auto_supply.view', 'integrations.view', ...($write ? ['auto_supply.create'] : [])] as $p) if (! $who->permits($p)) throw new ManagerDataException('Недостаточно прав на перевозки FBS.', 403);
        if ($write && ! $who->personalConfirmed) throw new ManagerDataException('Нужна явно подтверждённая личная сессия.', 403);
    }
    private function available(): void { $this->tables(); if (! config('services.manager.fbs_writes_enabled', true) || ! config('services.manager.ozon_carriages_enabled', false)) throw new ManagerDataException('Изменения перевозок остановлены администратором.', 503); }
    private function tables(): void { foreach (['manager_ozon_carriage_operations', 'manager_fbs_resource_holds', 'manager_ozon_carriage_events'] as $t) if (! Schema::hasTable($t)) throw new ManagerDataException('Журнал перевозок не установлен.', 503); }
    private function methodTarget(array $p): string { return 'carriage-method:'.$p['method']['delivery_method_id'].':'.$p['method']['departure_date']; }
    private function targets(array $p): array { return [$this->methodTarget($p), ...array_column($p['postings'], 'external_id'), ...($p['_order_targets'] ?? [])]; }
    private function publicPlan(array $p): array { unset($p['selection'], $p['parent_operation_id'], $p['_order_targets'], $p['_execution_control']); return $p; }
    private function fingerprint(Operation $r): string { return hash('sha256', $this->canonical(['ozon-carriage-v1', $r->id, (int) $r->workspace_id, (int) $r->user_id, (int) $r->integration_id, $r->store_name, $r->store_binding, $r->operation_type, $r->plan, $r->expires_at->timestamp])); }
    private function contextHash(ManagerIdentity $w, ManagerStore $s, array $p): string { return hash('sha256', $this->canonical([$w->workspaceId, $w->userId, $s->integrationId, $s->name, $s->binding, $p])); }
    private function confirm(Operation $r, string $hash): void { if (! hash_equals($r->fingerprint, $hash) || ! hash_equals($r->fingerprint, $this->fingerprint($r))) throw new ManagerDataException('Неизменяемое предложение перевозки повреждено или изменилось.', 409); }
    private function canonical(mixed $v): string { $normalize = function ($v) use (&$normalize) { if (! is_array($v)) return $v; if (! array_is_list($v)) ksort($v, SORT_STRING); return array_map($normalize, $v); }; return json_encode($normalize($v), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); }
    private function event(string $id, string $status): void { DB::table('manager_ozon_carriage_events')->insert(['operation_id' => $id, 'status' => $status, 'created_at' => now()]); }
    private function checks(string $type): array
    {
        $labels = [$type === 'fbs_carriage_create' ? 'Проверил весь состав: Ozon автоматически включает готовые отправления; состав проверяется отдельно после создания.' : 'Проверил фактический полный состав существующей перевозки.',
            'Проверил готовность всех перечисленных товаров, дату, склад, точку передачи и сообщения площадки.', 'Проверил предварительную стоимость или её отсутствие; окончательная стоимость и штрафы не подтверждены.',
            $type === 'fbs_carriage_create' ? 'Разрешаю создание новой перевозки. Её подтверждение и физическая передача — отдельные шаги.' : 'Разрешаю подтверждение перевозки в Ozon. Это не подтверждает физическую передачу товара.'];
        return array_map(fn ($k, $label) => ['key' => $k, 'label' => $label], self::CHECKS, $labels);
    }
}
