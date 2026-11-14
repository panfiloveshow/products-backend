<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\AutoSupplyPlan;
use App\Models\Integration;
use App\Models\ManagerOzonFboOperation as Operation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ManagerOzonFboService
{
    public const CHECKS = ['fbo_scope_checked', 'fbo_items_checked', 'fbo_conditions_checked', 'fbo_effect_checked'];

    public function __construct(private ManagerStoreResolver $stores, private ManagerOzonFboGateway $gateway, private ManagerOzonFboPlan $plans, private ManagerFboPublicationGuard $guard) {}

    public function context(ManagerIdentity $who, int $integration, array $selection): array
    {
        $this->permission($who);
        $store = $this->store($who, $integration);
        $plan = $this->plans->build($who, $store, $selection);

        return $this->scope($who, $store) + ['selection' => $selection, 'supply' => ManagerOzonFboPlan::publicPlan($plan),
            'context_fingerprint' => $this->contextHash($who, $store, $plan), 'source' => 'Ozon Seller API', 'observed_at' => now()->utc()->toRfc3339String()];
    }

    public function prepare(ManagerIdentity $who, int $integration, array $selection, string $hash): array
    {
        $this->permission($who, true);
        $this->available();
        $store = $this->store($who, $integration);
        $plan = $this->plans->build($who, $store, $selection);
        if (! hash_equals($hash, $this->contextHash($who, $store, $plan))) {
            throw new ManagerDataException('План или условия Ozon изменились. Получите новый контекст.', 409);
        }
        $r = DB::transaction(function () use ($who, $store, $plan) {
            $at = CarbonImmutable::now()->startOfSecond();
            $r = Operation::create(['id' => (string) Str::uuid(), 'workspace_id' => $who->workspaceId, 'user_id' => $who->userId, 'integration_id' => $store->integrationId,
                'store_name' => $store->name, 'store_binding' => $store->binding, 'operation_type' => $plan['operation_type'], 'plan' => $plan,
                'fingerprint' => str_repeat('0', 64), 'status' => 'awaiting_confirmation', 'created_at' => $at, 'updated_at' => $at, 'expires_at' => $at->addMinutes(5)]);
            $r->update(['fingerprint' => $this->fingerprint($r)]);
            $this->event($r->id, 'awaiting_confirmation');

            return $r;
        });

        return $this->expose($r);
    }

    public function availability(ManagerIdentity $who, string $id): array
    {
        $r = $this->get($who, $id);
        $store = $this->store($who, (int) $r->integration_id);
        $this->sameStore($r, $store);
        if ($r->operation_type !== 'fbo_ozon_draft_create' || $r->status !== 'succeeded' || ! $r->native_draft_id) {
            throw new ManagerDataException('Нужен рассчитанный личный черновик.', 409);
        }

        return $this->scope($who, $store) + ['draft_operation_id' => $r->id, 'draft' => $this->gateway->draftInfo($store, $r->native_draft_id),
            'composition_verified' => false, 'source' => 'Ozon Seller API', 'observed_at' => now()->utc()->toRfc3339String()];
    }

    public function slots(ManagerIdentity $who, string $id, string $warehouse, string $from, string $to): array
    {
        $r = $this->get($who, $id);
        $store = $this->store($who, (int) $r->integration_id);
        $this->sameStore($r, $store);
        if ($r->operation_type !== 'fbo_ozon_draft_create' || $r->status !== 'succeeded' || ! $r->native_draft_id) {
            throw new ManagerDataException('Нужен рассчитанный личный черновик.', 409);
        }
        $this->plans->warehouse($store, $r->native_draft_id, $r->plan, $warehouse);

        return $this->scope($who, $store) + ['draft_operation_id' => $r->id,
            ...$this->gateway->timeslots($store, $r->native_draft_id, $r->plan['cluster']['macrolocal_cluster_id'], $warehouse, $from, $to),
            'source' => 'Ozon Seller API', 'observed_at' => now()->utc()->toRfc3339String()];
    }

    public function get(ManagerIdentity $who, string $id): Operation
    {
        $this->permission($who);
        $this->tables();
        $r = Operation::whereKey($id)->where('workspace_id', $who->workspaceId)->where('user_id', $who->userId)->first();
        if (! $r) {
            throw new ManagerDataException('Операция FBO не найдена.', 404);
        }
        $this->confirm($r, $r->fingerprint);
        if ($r->status === 'awaiting_confirmation' && $r->expires_at->lte(now())) {
            $this->update($r, ['awaiting_confirmation'], ['status' => 'expired', 'message' => 'Срок согласования истёк.']);
        }
        if ($r->status === 'executing' && $r->native_started_at === null && $r->expires_at->lte(now())) {
            $this->abort($r);
        }
        if ($r->status === 'executing' && $r->native_started_at?->lt(now()->subMinutes(3))) {
            $this->update($r, ['executing'], ['status' => 'unknown', 'message' => 'Результат отправки неизвестен. Повтор заблокирован.']);
        }

        return $r->fresh();
    }

    public function cancel(ManagerIdentity $who, string $id, string $hash): array
    {
        $r = $this->get($who, $id);
        $this->confirm($r, $hash);
        $this->update($r, ['awaiting_confirmation'], ['status' => 'cancelled', 'message' => 'Предложение отменено до отправки.']);

        return $this->expose($this->get($who, $id));
    }

    public function approve(ManagerIdentity $who, string $id, string $hash, array $checks, callable $revalidate): array
    {
        $this->permission($who, true);
        $this->available();
        $r = $this->get($who, $id);
        $this->confirm($r, $hash);
        if ($r->status !== 'awaiting_confirmation') {
            return $this->expose($r);
        }
        if (count($checks) !== 4 || array_diff(array_keys($checks), self::CHECKS) || array_filter($checks, fn ($v) => $v !== true)) {
            throw new ManagerDataException('Нужны четыре личные проверки этого шага.', 422);
        }

        return $this->guard->run($r->plan['_account'], function () use ($who, $r, $checks, $revalidate) {
            $who = $this->fresh($who, $revalidate);
            $store = $this->store($who, (int) $r->integration_id);
            $this->sameStore($r, $store);
            try {
                $claimed = DB::transaction(function () use ($who, $r, $checks) {
                    $local = AutoSupplyPlan::whereKey($r->plan['plan_id'])->lockForUpdate()->firstOrFail();
                    if ($local->materialized_at !== null) {
                        throw new ManagerDataException('План уже исполняется другим способом.', 409);
                    }
                    $current = Operation::whereKey($r->id)->lockForUpdate()->firstOrFail();
                    $this->confirm($current, $r->fingerprint);
                    if ($current->status !== 'awaiting_confirmation' || $current->expires_at->lte(now())) {
                        return false;
                    }
                    foreach ($this->keys($r) as $key) {
                        DB::table('manager_fbo_resource_holds')->insert(['resource_key' => $key, 'operation_id' => $r->id, 'created_at' => now()]);
                    }
                    $current->update(['status' => 'executing', 'execution_started_at' => now(), 'native_draft_id' => $r->plan['draft_id'], 'confirmations' => ['user_id' => $who->userId, 'values' => $checks, 'declared_at' => now()->utc()->toRfc3339String()]]);
                    $this->event($r->id, 'executing');

                    return true;
                });
            } catch (QueryException $e) {
                if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                    throw new ManagerDataException('План или черновик уже имеет исполнение. Проверьте его журнал.', 409);
                }
                throw $e;
            }
            if (! $claimed) {
                return $this->expose($this->get($who, $r->id));
            }
            $before = function () use ($who, $r, $revalidate) {
                $who = $this->fresh($who, $revalidate);
                $this->available();
                $store = $this->store($who, (int) $r->integration_id);
                $this->sameStore($r, $store);
                $currentPlan = $this->plans->build($who, $store, $r->plan['_selection']);
                if (ManagerOzonFboPlan::canonical($currentPlan) !== ManagerOzonFboPlan::canonical($r->plan)) {
                    throw new ManagerDataException('План, SKU, приёмка или время изменились.', 409);
                }
                $who = $this->fresh($who, $revalidate);
                $this->sameStore($r, $this->store($who, (int) $r->integration_id));
                $this->available();
                DB::transaction(function () use ($r) {
                    $local = AutoSupplyPlan::whereKey($r->plan['plan_id'])->lockForUpdate()->firstOrFail();
                    if ($this->plans->localVersion($local) !== $r->plan['_local_version']) {
                        throw new ManagerDataException('Исходный план изменился перед отправкой.', 409);
                    }
                    $current = Operation::whereKey($r->id)->lockForUpdate()->firstOrFail();
                    $this->confirm($current, $r->fingerprint);
                    if ($current->status !== 'executing' || $current->native_started_at !== null || $current->expires_at->lte(now())
                        || DB::table('manager_fbo_resource_holds')->where('operation_id', $r->id)->whereIn('resource_key', $this->keys($r))->count() !== 2) {
                        throw new ManagerDataException('Согласование или блокировка больше не действуют.', 409);
                    }
                    $current->update(['native_started_at' => now()]);
                    $this->event($r->id, 'native_started');
                });
            };
            $receipt = $r->operation_type === 'fbo_ozon_draft_create'
                ? $this->gateway->submitDirectDraft($store, $r->plan['cluster']['macrolocal_cluster_id'], array_map(fn ($i) => ['sku' => $i['sku'], 'quantity' => $i['quantity']], $r->plan['items']), $before)
                : $this->gateway->submitDirectSupply($store, $r->plan['draft_id'], $r->plan['cluster']['macrolocal_cluster_id'], $r->plan['warehouse']['id'],
                    ['from_in_timezone' => $r->plan['slot']['from_in_timezone'], 'to_in_timezone' => $r->plan['slot']['to_in_timezone']], $before);
            $this->update($r, ['executing', 'unknown'], ['status' => $receipt['status'], 'native_draft_id' => $receipt['native_id'] ?? $r->plan['draft_id'],
                'result' => ['submission_acknowledged' => $receipt['acknowledged'], 'native_attempted' => $receipt['native_attempted'], 'fields_verified' => false,
                    'draft_created_verified' => false, 'composition_verified' => false, 'order_id' => null, 'physical_status' => null, 'actual_cost_verified' => false],
                'message' => $receipt['status'] === 'failed' ? 'Команда не подтверждена: проверьте причину перед новым предложением.' : 'Запрос отправлен; результат проверяется чтением. Повтор команды заблокирован.'], $receipt['status'] === 'failed' ? 'all' : null);

            return $this->reconcile($who, $r->id);
        }, $r->id);
    }

    public function reconcile(ManagerIdentity $who, string $id): array
    {
        $r = $this->get($who, $id);
        if (! in_array($r->status, ['verifying', 'unknown'], true) || ! $r->native_draft_id) {
            return $this->expose($r);
        }
        try {
            $store = $this->store($who, (int) $r->integration_id);
            $this->sameStore($r, $store);
            $result = $r->result ?? ['submission_acknowledged' => false, 'native_attempted' => $r->native_started_at !== null,
                'fields_verified' => false, 'draft_created_verified' => false, 'composition_verified' => false,
                'order_id' => null, 'physical_status' => null, 'actual_cost_verified' => false];
            $ack = ($result['submission_acknowledged'] ?? false) === true;
            if ($r->operation_type === 'fbo_ozon_draft_create') {
                $info = $this->gateway->draftInfo($store, $r->native_draft_id);
                $matching = array_values(array_filter($info['clusters'] ?? [], fn ($c) => $c['macrolocal_cluster_id'] === $r->plan['cluster']['macrolocal_cluster_id'] && $c['supply_type'] === 'DIRECT'));
                if ($info['status'] === 'FAILED') {
                    $this->update($r, ['verifying', 'unknown'], ['status' => 'failed', 'result' => $result + ['draft_status' => 'FAILED'], 'message' => 'Ozon отклонил расчёт черновика.'], 'all');
                } elseif ($ack && $info['status'] === 'SUCCESS' && empty($info['errors']) && count($matching) === 1 && count($info['clusters']) === 1) {
                    $this->update($r, ['verifying', 'unknown'], ['status' => 'succeeded', 'result' => [...$result, 'draft_created_verified' => true, 'draft_status' => 'SUCCESS', 'observed_at' => now()->utc()->toRfc3339String()],
                        'message' => 'Черновик рассчитан. Полный состав приёмки и время проверяются отдельно перед созданием заявки.'], 'account');
                }
            } else {
                $status = $this->gateway->creationStatus($store, $r->native_draft_id);
                if ($status['status'] === 'FAILED') {
                    // A failed status does not establish that an earlier ambiguous command never created an order.
                    $this->update($r, ['verifying', 'unknown'], ['status' => 'unknown', 'message' => 'Ozon сообщил ошибку; требуется сверка. Повтор команды заблокирован.']);
                } elseif ($status['status'] === 'SUCCESS' && $status['order_id'] !== null) {
                    $order = $this->gateway->order($store, $status['order_id']);
                    $items = [];
                    $matching = count($order['supplies']) > 0;
                    foreach ($order['supplies'] as $supply) {
                        if ($supply['warehouse_id'] !== $r->plan['warehouse']['id'] || $supply['is_crossdock'] !== false || $supply['bundle_id'] === null) {
                            $matching = false;
                            break;
                        }
                        $items = [...$items, ...$this->gateway->bundle($store, $supply['bundle_id'])['items']];
                    }
                    $composition = $matching && $this->plans->sameItems($r->plan['items'], $items);
                    $slot = $order['timeslot'];
                    $time = $slot !== null && $slot['from_utc'] === $r->plan['slot']['from_utc'] && $slot['to_utc'] === $r->plan['slot']['to_utc'];
                    $valid = $ack && $composition && $time && $order['dropoff_warehouse'] !== null && $order['dropoff_warehouse']['id'] === $r->plan['warehouse']['id'];
                    $this->update($r, ['verifying', 'unknown'], ['status' => $valid ? 'succeeded' : 'unknown', 'native_order_id' => $order['order_id'],
                        'result' => [...$result, 'fields_verified' => $valid, 'composition_verified' => $composition, 'order_id' => $order['order_id'], 'order_state' => $order['state'],
                            'observed_warehouse_id' => $order['dropoff_warehouse']['id'] ?? null, 'observed_from_utc' => $slot['from_utc'] ?? null, 'observed_to_utc' => $slot['to_utc'] ?? null,
                            'observed_at' => now()->utc()->toRfc3339String()],
                        'message' => $valid ? 'Заявка, склад, полный состав и время проверены API Ozon. Физическая поставка и окончательная стоимость не подтверждены.' : 'Фактическая заявка прочитана, но согласованный результат полностью не совпал. Повтор заблокирован.'], $valid ? 'account' : null);
                }
            }
        } catch (ManagerDataException) { /* Keep the durable receipt and holds on unavailable readback. */
        }

        return $this->expose($this->get($who, $id));
    }

    public function expose(Operation $r): array
    {
        return ['id' => $r->id, 'operation_type' => $r->operation_type, 'workspace_id' => (string) $r->workspace_id, 'integration_id' => (string) $r->integration_id,
            'integration_name' => $r->store_name, 'marketplace' => 'ozon', 'fingerprint' => $r->fingerprint, 'status' => $r->status, 'supply' => ManagerOzonFboPlan::publicPlan($r->plan),
            'required_confirmations' => $this->checks($r->operation_type), 'result' => $r->result === null ? null : [...$r->result,
                'control_hold_active' => DB::table('manager_fbo_resource_holds')->where('operation_id', $r->id)->where('resource_key', $this->keys($r)[0])->exists()],
            'native_draft_id' => $r->native_draft_id, 'native_order_id' => $r->native_order_id, 'message' => $r->message, 'physical_status' => null,
            'created_at' => $r->created_at->utc()->toRfc3339String(), 'expires_at' => $r->expires_at->utc()->toRfc3339String()];
    }

    private function update(Operation $r, array $from, array $values, ?string $release = null): void
    {
        DB::transaction(function () use ($r, $from, $values, $release) {
            if (! Operation::whereKey($r->id)->whereIn('status', $from)->update($values)) {
                return;
            }
            $this->event($r->id, $values['status'] ?? 'updated');
            if ($release === 'all') {
                DB::table('manager_fbo_resource_holds')->where('operation_id', $r->id)->delete();
            } elseif ($release === 'account') {
                DB::table('manager_fbo_resource_holds')->where('operation_id', $r->id)->where('resource_key', $this->keys($r)[0])->delete();
            }
        });
    }

    private function abort(Operation $r): void
    {
        DB::transaction(function () use ($r) {
            $current = Operation::whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($current->native_started_at === null) {
                $this->update($current, ['executing'], ['status' => 'failed', 'message' => 'Проверка до отправки не пройдена. Ozon не вызывался.'], 'all');
            }
        });
    }

    private function permission(ManagerIdentity $who, bool $write = false): void
    {
        foreach (['auto_supply.view', 'integrations.view', ...($write ? ['auto_supply.create'] : [])] as $p) {
            if (! $who->permits($p)) {
                throw new ManagerDataException('Недостаточно прав на поставки Ozon.', 403);
            }
        }
        if ($write && ! $who->personalConfirmed) {
            throw new ManagerDataException('Нужна явно подтверждённая личная сессия.', 403);
        }
    }

    private function fresh(ManagerIdentity $expected, callable $callback): ManagerIdentity
    {
        $who = $callback();
        if (! $who instanceof ManagerIdentity || $who->workspaceId !== $expected->workspaceId || $who->userId !== $expected->userId) {
            throw new ManagerDataException('Личная сессия изменилась.', 403);
        }
        $this->permission($who, true);

        return $who;
    }

    private function store(ManagerIdentity $who, int $id): ManagerStore
    {
        if (! Integration::whereKey($id)->where('work_space_id', $who->workspaceId)->where('marketplace', 'ozon')->exists()) {
            throw new ManagerDataException('Магазин Ozon не найден.', 404);
        }

        return $this->stores->resolve($who, $id, 'ozon');
    }

    private function sameStore(Operation $r, ManagerStore $s): void
    {
        if ($r->store_binding !== $s->binding || $r->store_name !== $s->name) {
            throw new ManagerDataException('Магазин или ключи изменились.', 409);
        }
    }

    private function tables(): void
    {
        foreach (['manager_ozon_fbo_operations', 'manager_ozon_fbo_events', 'manager_fbo_resource_holds'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new ManagerDataException('Журнал FBO не установлен.', 503);
            }
        }
    }

    private function available(): void
    {
        $this->tables();
        if (! config('services.manager.ozon_fbo_enabled', false)) {
            throw new ManagerDataException('Создание поставок FBO остановлено.', 503);
        }
    }

    private function scope(ManagerIdentity $w, ManagerStore $s): array
    {
        return ['workspace_id' => (string) $w->workspaceId, 'integration_id' => (string) $s->integrationId, 'integration_name' => $s->name, 'marketplace' => 'ozon'];
    }

    private function keys(Operation $r): array
    {
        return [ManagerFboPublicationGuard::key('account', $r->plan['_account']), ManagerFboPublicationGuard::key($r->operation_type === 'fbo_ozon_draft_create' ? 'plan' : 'draft', $r->plan['_account'], $r->operation_type === 'fbo_ozon_draft_create' ? $r->plan['plan_id'].':'.$r->plan['cluster']['native_cluster_id'] : $r->plan['draft_id'])];
    }

    private function contextHash(ManagerIdentity $w, ManagerStore $s, array $p): string
    {
        return hash('sha256', ManagerOzonFboPlan::canonical([$w->workspaceId, $w->userId, $s->integrationId, $s->binding, $s->name, $p]));
    }

    private function fingerprint(Operation $r): string
    {
        return hash('sha256', ManagerOzonFboPlan::canonical(['ozon-fbo-v1', $r->id, (int) $r->workspace_id, (int) $r->user_id, (int) $r->integration_id, $r->store_name, $r->store_binding, $r->operation_type, $r->plan, $r->expires_at->timestamp]));
    }

    private function confirm(Operation $r, string $hash): void
    {
        if (! hash_equals($r->fingerprint, $hash) || ! hash_equals($r->fingerprint, $this->fingerprint($r))) {
            throw new ManagerDataException('Неизменяемое предложение FBO изменилось.', 409);
        }
    }

    private function event(string $id, string $status): void
    {
        DB::table('manager_ozon_fbo_events')->insert(['operation_id' => $id, 'status' => $status, 'created_at' => now()]);
    }

    private function checks(string $type): array
    {
        $labels = ['Проверил магазин, утверждённый план и кластер назначения.', 'Проверил весь состав, количество, SKU и физическую готовность товара.',
            'Проверил исходные данные, прогноз, ограничения, неизвестную или предварительную стоимость; для заявки также склад, полную приёмку и время.',
            $type === 'fbo_ozon_draft_create' ? 'Лично разрешаю только создать и рассчитать черновик Ozon. Заявка требует отдельного выбора и согласования.' : 'Лично разрешаю создать заявку Ozon на показанный склад, состав и время. Это не подтверждает физическую поставку.'];

        return array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], self::CHECKS, $labels);
    }
}
