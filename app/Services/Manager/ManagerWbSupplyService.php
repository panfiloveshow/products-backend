<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Integration;
use App\Models\ManagerWbSupplyOperation as Operation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ManagerWbSupplyService
{
    public const CHECKS = ['wb_supply_scope_checked', 'wb_supply_items_checked', 'wb_supply_conditions_checked', 'wb_supply_effect_checked'];
    public function __construct(private ManagerStoreResolver $stores, private ManagerWbSupplyGateway $gateway, private ManagerFbsPublicationGuard $guard) {}
    public function listing(ManagerIdentity $w, int $integration, string $cursor): array
    {
        $this->permission($w); $s = $this->store($w, $integration);
        return $this->scope($w, $s) + $this->gateway->listing($s, $cursor) + ['source' => 'Wildberries Marketplace API', 'observed_at' => now()->utc()->toRfc3339String(), 'physical_status' => null];
    }
    public function points(ManagerIdentity $w, int $integration, string $supply, string $city, array $types, string $day): array
    {
        $this->permission($w); $s = $this->store($w, $integration); $native = $this->gateway->supply($s, $supply);
        if ($native['done'] || $native['scan_at'] !== null) throw new ManagerDataException('Нужна открытая неотсканированная поставка.', 422);
        return $this->scope($w, $s) + ['supply_id' => $supply, 'city' => $city, 'office_types' => $types, 'shipping_date' => $day,
            'points' => $this->gateway->points($s, $supply, $city, $types, $day), 'completeness' => 'complete_response',
            'source' => 'Wildberries Marketplace API', 'observed_at' => now()->utc()->toRfc3339String(), 'physical_status' => null];
    }
    public function context(ManagerIdentity $w, int $integration, array $input): array
    {
        $this->permission($w); $s = $this->store($w, $integration); $selection = $this->gateway->selection($input); $p = $this->plan($w, $s, $selection);
        return $this->scope($w, $s) + ['selection' => $selection, 'supply' => $this->publicPlan($p), 'context_fingerprint' => $this->contextHash($w, $s, $p),
            'completeness' => 'complete_target', 'source' => 'Wildberries Marketplace API', 'observed_at' => now()->utc()->toRfc3339String(), 'physical_status' => null];
    }
    public function prepare(ManagerIdentity $w, int $integration, array $input, string $hash): array
    {
        $this->permission($w, true); $this->available(); $s = $this->store($w, $integration); $selection = $this->gateway->selection($input); $p = $this->plan($w, $s, $selection);
        if (! hash_equals($hash, $this->contextHash($w, $s, $p))) throw new ManagerDataException('Состав или условия WB изменились. Нужен новый контекст.', 409);
        $r = DB::transaction(function () use ($w, $s, $p) {
            $created = \Carbon\CarbonImmutable::now()->startOfSecond();
            $r = Operation::create(['id' => (string) Str::uuid(), 'workspace_id' => $w->workspaceId, 'user_id' => $w->userId, 'integration_id' => $s->integrationId,
                'store_name' => $s->name, 'store_binding' => $s->binding, 'operation_type' => $p['operation_type'], 'plan' => $p,
                'fingerprint' => str_repeat('0', 64), 'status' => 'awaiting_confirmation', 'created_at' => $created, 'updated_at' => $created, 'expires_at' => $created->addMinutes(5)]);
            $r->update(['fingerprint' => $this->fingerprint($r)]); $this->event($r->id, 'awaiting_confirmation'); return $r;
        }); return $this->expose($r);
    }
    public function get(ManagerIdentity $w, string $id): Operation
    {
        $this->permission($w); $this->tables(); $r = Operation::whereKey($id)->where('workspace_id', $w->workspaceId)->where('user_id', $w->userId)->first();
        if (! $r) throw new ManagerDataException('Операция поставки WB не найдена.', 404); $this->confirm($r, $r->fingerprint);
        if ($r->status === 'awaiting_confirmation' && $r->expires_at->timestamp <= now()->timestamp) $this->update($r, ['awaiting_confirmation'], ['status' => 'expired', 'message' => 'Срок согласования истёк.'], true);
        if ($r->status === 'executing' && $r->native_started_at === null && $r->expires_at->timestamp <= now()->timestamp) $this->abort($r);
        if ($r->status === 'executing' && $r->native_started_at?->lt(now()->subMinutes(3))) $this->update($r, ['executing'], ['status' => 'unknown', 'message' => 'Результат отправки неизвестен. Повтор заблокирован.']);
        return $r->fresh();
    }
    public function cancel(ManagerIdentity $w, string $id, string $hash): array
    {
        $r = $this->get($w, $id); $this->confirm($r, $hash);
        $this->update($r, ['awaiting_confirmation'], ['status' => 'cancelled', 'message' => 'Карточка отменена. Действия с поставкой не выполнялись.'], true);
        return $this->expose($this->get($w, $id));
    }
    public function approve(ManagerIdentity $w, string $id, string $hash, array $checks, callable $revalidate): array
    {
        $this->permission($w, true); $this->available(); $r = $this->get($w, $id); $this->confirm($r, $hash);
        if ($r->status !== 'awaiting_confirmation') return $this->expose($r);
        if (count($checks) !== 4 || array_diff(array_keys($checks), self::CHECKS) || array_filter($checks, fn ($v) => $v !== true)) throw new ManagerDataException('Нужны четыре личные проверки этого шага.', 422);
        return $this->guard->runMany('wildberries', $r->plan['_targets'], function () use ($w, $r, $checks, $revalidate) {
            $w = $this->fresh($w, $revalidate); $s = $this->store($w, (int) $r->integration_id); $this->sameStore($r, $s);
            try {
                $claimed = DB::transaction(function () use ($w, $r, $checks) {
                    $current = Operation::whereKey($r->id)->lockForUpdate()->firstOrFail(); $this->confirm($current, $r->fingerprint);
                    if ($current->status !== 'awaiting_confirmation' || $current->expires_at->timestamp <= now()->timestamp) return false;
                    foreach ($r->plan['_targets'] as $target) DB::table('manager_fbs_resource_holds')->insert(['resource_key' => ManagerFbsPublicationGuard::resource('wildberries', $target), 'operation_id' => $r->id, 'created_at' => now()]);
                    $current->update(['status' => 'executing', 'execution_started_at' => now(), 'confirmations' => ['user_id' => $w->userId, 'values' => $checks, 'declared_at' => now()->utc()->toRfc3339String()]]);
                    $this->event($r->id, 'executing'); return true;
                });
            } catch (QueryException $e) {
                if (in_array((string) $e->getCode(), ['23000', '23505'], true)) throw new ManagerDataException('Поставка или одно из заданий уже заняты.', 409); throw $e;
            }
            if (! $claimed) return $this->expose($this->get($w, $r->id));
            try {
                $w = $this->fresh($w, $revalidate); $s = $this->store($w, (int) $r->integration_id); $this->sameStore($r, $s);
                $current = $this->plan($w, $s, $r->plan['_selection'], $r->id);
                if ($this->canonical($current) !== $this->canonical($r->plan)) throw new ManagerDataException('Данные поставки изменились.', 422);
                $s = $this->store($w, (int) $r->integration_id); $this->sameStore($r, $s); $w = $this->fresh($w, $revalidate); $this->available();
            } catch (ManagerDataException $e) {
                $this->abort($r); if (in_array($e->httpStatus, [401, 403, 404], true)) throw $e; return $this->expose($this->get($w, $r->id));
            }
            $started = DB::transaction(function () use ($r) {
                $current = Operation::whereKey($r->id)->lockForUpdate()->firstOrFail(); $this->confirm($current, $r->fingerprint);
                $keys = array_map(fn ($target) => ManagerFbsPublicationGuard::resource('wildberries', $target), $r->plan['_targets']);
                if ($current->status !== 'executing' || $current->native_started_at !== null || $current->expires_at->timestamp <= now()->timestamp
                    || DB::table('manager_fbs_resource_holds')->where('operation_id', $r->id)->whereIn('resource_key', $keys)->count() !== count($keys)) return false;
                $current->update(['native_started_at' => now()]); $this->event($r->id, 'native_started'); return true;
            });
            if (! $started) { $this->abort($r); return $this->expose($this->get($w, $r->id)); }
            $receipt = $this->gateway->submit($s, $r->plan);
            $result = ['submission_acknowledged' => $receipt['acknowledged'], 'fields_verified' => false, 'membership_verified' => false,
                'supply_id' => $receipt['supply_id'], 'created_box_ids' => $receipt['created_box_ids'], 'physical_status' => null, 'actual_cost_verified' => false];
            $this->update($r, ['executing', 'unknown'], ['status' => $receipt['status'], 'native_supply_id' => $receipt['supply_id'], 'result' => $result,
                'message' => ($receipt['stopped_before_native'] ?? false) ? 'Отправка остановлена ограничением FBS. Запрос на площадку не отправлен.' : ($receipt['status'] === 'verifying' ? 'WB принял запрос; результат проверяется чтением.' : ($receipt['status'] === 'failed' ? 'WB отклонил запрос.' : 'Ответ неизвестен. Повторная команда заблокирована.'))], $receipt['status'] === 'failed');
            return $this->reconcile($w, $r->id);
        }, $r->id);
    }
    public function reconcile(ManagerIdentity $w, string $id): array
    {
        $r = $this->get($w, $id); if (! in_array($r->status, ['verifying', 'unknown'], true)) return $this->expose($r);
        $native = $r->native_supply_id ?? $r->plan['supply_before']['id'] ?? null;
        if ($native === null) return $this->expose($r);
        try {
            $s = $this->store($w, (int) $r->integration_id); $this->sameStore($r, $s);
            $seen = $this->gateway->observe($s, $r->plan, $native, $r->result['created_box_ids'] ?? null, $r->plan['_selection']);
            $ack = ($r->result['submission_acknowledged'] ?? false) === true; $status = $ack && $seen['fields_verified'] ? 'succeeded' : ($ack && $r->execution_started_at?->gt(now()->subMinutes(15)) ? 'verifying' : 'unknown');
            $this->update($r, ['verifying', 'unknown'], ['status' => $status, 'result' => ['submission_acknowledged' => $ack, 'supply_id' => $native,
                'created_box_ids' => $r->result['created_box_ids'] ?? null, ...$seen],
                'message' => $status === 'succeeded' ? 'Согласованный шаг и состав проверены API WB. Физическое выполнение и окончательная стоимость не подтверждены.'
                    : ($ack ? 'Ожидаемый результат пока не подтверждён. Команда не повторяется.' : 'Текущее состояние прочитано, но приём нашей команды неизвестен. Повтор запрещён.')], $status === 'succeeded');
        } catch (ManagerDataException) { /* Receipt and holds survive unavailable native readback. */ }
        return $this->expose($this->get($w, $id));
    }
    public function expose(Operation $r): array
    {
        return ['id' => $r->id, 'operation_type' => $r->operation_type, 'workspace_id' => (string) $r->workspace_id, 'integration_id' => (string) $r->integration_id,
            'integration_name' => $r->store_name, 'marketplace' => 'wildberries', 'fingerprint' => $r->fingerprint, 'status' => $r->status, 'supply' => $this->publicPlan($r->plan),
            'required_confirmations' => $this->checks($r->operation_type), 'result' => $r->result === null ? null : $r->result + ['control_hold_active' => DB::table('manager_fbs_resource_holds')->where('operation_id', $r->id)->exists()],
            'message' => $r->message, 'physical_status' => null, 'created_at' => $r->created_at->utc()->toRfc3339String(), 'expires_at' => $r->expires_at->utc()->toRfc3339String()];
    }
    private function plan(ManagerIdentity $w, ManagerStore $s, array $selection, ?string $own = null): array
    {
        $p = app(ManagerFbsExecutionPolicy::class)->decorate($s, $this->gateway->context($s, $selection));
        if ($p['operation_type'] === 'fbs_wb_supply_create') {
            if (Schema::hasTable('manager_wb_supply_operations') && Operation::where('workspace_id', $w->workspaceId)->where('integration_id', $s->integrationId)
                ->where('operation_type', 'fbs_wb_supply_create')->whereIn('status', ['executing', 'verifying', 'unknown'])->when($own, fn ($q) => $q->where('id', '!=', $own))->exists())
                throw new ManagerDataException('Есть незавершённое создание поставки. Сначала проверьте его журнал.', 409);
            $targets = ['wb-create:'.hash_hmac('sha256', $s->apiKey, (string) config('app.key'))];
        } else {
            $targets = ['wb-supply:'.$p['supply_before']['id'], ...array_column($p['orders'], 'external_id')];
            foreach ($p['source_supplies'] as $source) $targets = [...$targets, 'wb-supply:'.$source['supply']['id'], ...$source['order_ids']];
        }
        $targets = array_values(array_unique($targets)); sort($targets, SORT_STRING);
        if (count($targets) > 1001) throw new ManagerDataException('Все затрагиваемые поставки и задания превышают размер безопасной группы. Разделите личные согласования.', 422);
        return $p + ['_selection' => $selection, '_targets' => $targets];
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
    private function abort(Operation $r): void
    {
        DB::transaction(function () use ($r) {
            $current = Operation::whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($current->native_started_at !== null) return;
            $this->update($current, ['executing'], ['status' => 'failed', 'message' => 'Свежая проверка или срок не пройдены. Команда WB не запускалась.',
                'result' => ['submission_acknowledged' => false, 'fields_verified' => false, 'membership_verified' => false, 'supply_id' => null, 'created_box_ids' => null,
                    'native_attempted' => false, 'physical_status' => null, 'actual_cost_verified' => false]], true);
        });
    }
    private function permission(ManagerIdentity $w, bool $write = false): void
    {
        foreach (['auto_supply.view', 'integrations.view', ...($write ? ['auto_supply.create'] : [])] as $p) if (! $w->permits($p)) throw new ManagerDataException('Недостаточно прав на поставки WB.', 403);
        if ($write && ! $w->personalConfirmed) throw new ManagerDataException('Нужна явно подтверждённая личная сессия.', 403);
    }
    private function fresh(ManagerIdentity $expected, callable $callback): ManagerIdentity
    {
        $w = $callback(); if (! $w instanceof ManagerIdentity || $w->workspaceId !== $expected->workspaceId || $w->userId !== $expected->userId) throw new ManagerDataException('Личная сессия изменилась.', 403);
        $this->permission($w, true); return $w;
    }
    private function store(ManagerIdentity $w, int $id): ManagerStore
    {
        $local = Integration::whereKey($id)->where('work_space_id', $w->workspaceId)->first();
        if (! $local) throw new ManagerDataException('Магазин не найден.', 404);
        if (! in_array(strtolower((string) $local->marketplace), ['wb', 'wildberries'], true)) throw new ManagerDataException('Нужен магазин WB.', 422);
        return $this->stores->resolve($w, $id, 'wildberries');
    }
    private function sameStore(Operation $r, ManagerStore $s): void { if ($r->store_binding !== $s->binding || $r->store_name !== $s->name) throw new ManagerDataException('Магазин или ключи изменились.', 409); }
    private function available(): void { $this->tables(); if (! config('services.manager.fbs_writes_enabled', true) || ! config('services.manager.wb_supplies_enabled', false)) throw new ManagerDataException('Изменения поставок WB остановлены.', 503); }
    private function tables(): void { foreach (['manager_wb_supply_operations', 'manager_wb_supply_events', 'manager_fbs_resource_holds'] as $table) if (! Schema::hasTable($table)) throw new ManagerDataException('Журнал поставок WB не установлен.', 503); }
    private function scope(ManagerIdentity $w, ManagerStore $s): array { return ['workspace_id' => (string) $w->workspaceId, 'integration_id' => (string) $s->integrationId, 'integration_name' => $s->name, 'marketplace' => 'wildberries']; }
    private function publicPlan(array $p): array { unset($p['_selection'], $p['_targets'], $p['_metadata_hash'], $p['_execution_control']); return $p; }
    private function contextHash(ManagerIdentity $w, ManagerStore $s, array $p): string { return hash('sha256', $this->canonical([$w->workspaceId, $w->userId, $s->integrationId, $s->binding, $s->name, $p])); }
    private function fingerprint(Operation $r): string { return hash('sha256', $this->canonical(['wb-supply-v1', $r->id, (int) $r->workspace_id, (int) $r->user_id, (int) $r->integration_id, $r->store_name, $r->store_binding, $r->operation_type, $r->plan, $r->expires_at->timestamp])); }
    private function confirm(Operation $r, string $hash): void { if (! hash_equals($r->fingerprint, $hash) || ! hash_equals($r->fingerprint, $this->fingerprint($r))) throw new ManagerDataException('Неизменяемое предложение WB изменилось или повреждено.', 409); }
    private function canonical(mixed $v): string { $n = function ($v) use (&$n) { if (! is_array($v)) return $v; if (! array_is_list($v)) ksort($v, SORT_STRING); return array_map($n, $v); }; return json_encode($n($v), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
    private function event(string $id, string $status): void { DB::table('manager_wb_supply_events')->insert(['operation_id' => $id, 'status' => $status, 'created_at' => now()]); }
    private function checks(string $type): array
    {
        $effects = ['fbs_wb_supply_create' => 'создание пустой поставки с указанным именем', 'fbs_wb_supply_assign' => 'добавление/перемещение только выбранных заданий и их перевод на сборку',
            'fbs_wb_supply_shipping' => 'изменение только показанных параметров отгрузки', 'fbs_wb_supply_boxes' => 'создание указанного количества новых грузомест',
            'fbs_wb_supply_deliver' => 'закрытие поставки и перевод всех показанных заданий в доставку'];
        $labels = ['Проверил магазин, поставку, весь состав и исходные поставки перемещаемых заданий.', 'Проверил товары, маркировку, физическую готовность и грузоместа.',
            'Проверил пункт, дату, способ отгрузки, доступность и сообщения проверки; окончательная стоимость и штрафы не подтверждены.',
            'Лично разрешаю '.$effects[$type].'. Физическая передача API не подтверждается.'];
        return array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], self::CHECKS, $labels);
    }
}
