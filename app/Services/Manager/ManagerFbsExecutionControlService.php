<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Integration;
use App\Models\ManagerFbsExecutionOperation as Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Personal persistent stop, never a native order mutation or a permission to revive old work. */
final class ManagerFbsExecutionControlService
{
    public const CHECKS = ['fbs_scope_checked', 'fbs_effect_checked', 'fbs_reopening_checked'];

    public function __construct(private ManagerStoreResolver $stores, private ManagerFbsGateway $gateway, private ManagerFbsExecutionPolicy $policy) {}

    public function context(ManagerIdentity $who, int $integration): array
    {
        $this->permission($who);
        $this->available();
        $store = $this->store($who, $integration);
        $this->verifyNative($store);

        return $this->policy->locked($store, fn ($row) => $this->publicContext($who, $store, $row));
    }

    public function prepare(ManagerIdentity $who, int $integration, string $fingerprint, string $mode, string $reason): array
    {
        $this->permission($who, true);
        $this->available();
        $reason = trim($reason);
        if (! in_array($mode, ['stopped', 'manual_only'], true) || mb_strlen($reason) < 10 || mb_strlen($reason) > 2000) {
            throw new ManagerDataException('Нужны режим и пояснение от 10 до 2000 символов.', 422);
        }
        $store = $this->store($who, $integration);
        $this->verifyNative($store);
        $operation = $this->policy->locked($store, function ($row) use ($who, $store, $fingerprint, $mode, $reason) {
            $context = $this->publicContext($who, $store, $row);
            if (! hash_equals($context['context_fingerprint'], $fingerprint)) {
                throw new ManagerDataException('Магазин или ограничение изменились. Прочитайте контекст заново.', 409);
            }
            if ($mode === $row->mode || $mode === 'manual_only' && $row->mode !== 'stopped') {
                throw new ManagerDataException('Открытие возможно только после остановки; новое решение должно менять режим.', 422);
            }
            $op = new Operation(['id' => (string) Str::uuid(), 'workspace_id' => $who->workspaceId, 'user_id' => $who->userId,
                'integration_id' => $store->integrationId, 'marketplace' => $store->marketplace, 'store_name' => $store->name, 'store_binding' => $store->binding,
                'account_key' => $row->account_key, 'plan' => ['before' => $this->policy->publicControl($row), 'target_mode' => $mode, 'reason' => $reason, 'scope' => $this->scope(),
                    'source' => ['name' => 'marketplace_api', 'observed_at' => now()->utc()->toRfc3339String(), 'store_access_verified' => true]],
                'status' => 'awaiting_confirmation', 'expires_at' => now()->addMinutes(5)]);
            $op->fingerprint = $this->fingerprint($op);
            $op->save();
            $this->event($op, 'awaiting_confirmation');

            return $op;
        });

        return $this->expose($who, $operation);
    }

    public function get(ManagerIdentity $who, string $id): Operation
    {
        $this->permission($who);
        $row = Operation::whereKey($id)->where('workspace_id', $who->workspaceId)->where('user_id', $who->userId)->first();
        if (! $row) {
            throw new ManagerDataException('Личное решение FBS не найдено.', 404);
        }
        $this->confirm($row, $row->fingerprint);
        if ($row->status === 'awaiting_confirmation' && $row->expires_at->isPast()) {
            DB::transaction(function () use ($row) {
                $op = Operation::whereKey($row->id)->lockForUpdate()->firstOrFail();
                if ($op->status === 'awaiting_confirmation' && $op->expires_at->isPast()) {
                    $op->update(['status' => 'expired', 'message' => 'Срок предложения истёк. Сохранённое ранее ограничение не менялось.']);
                    $this->event($op, 'expired');
                }
            }, 1);
        }

        return $row->fresh();
    }

    public function cancel(ManagerIdentity $who, string $id, string $fingerprint): array
    {
        $this->permission($who, true);
        $this->available();
        $row = $this->get($who, $id);
        $this->confirm($row, $fingerprint);
        DB::transaction(function () use ($row) {
            $op = Operation::whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($op->status === 'awaiting_confirmation') {
                $op->update(['status' => 'cancelled', 'message' => 'Предложение отменено. Сохранённое ранее ограничение не менялось.']);
                $this->event($op, 'cancelled');
            }
        }, 1);

        return $this->expose($who, $this->get($who, $id));
    }

    public function approve(ManagerIdentity $who, string $id, string $fingerprint, array $checks, callable $revalidate): array
    {
        $this->permission($who, true);
        $this->available();
        $row = $this->get($who, $id);
        $this->confirm($row, $fingerprint);
        if ($row->status !== 'awaiting_confirmation') {
            return $this->expose($who, $row);
        }
        if (count($checks) !== count(self::CHECKS) || array_diff(array_keys($checks), self::CHECKS) || array_filter($checks, fn ($v) => $v !== true)) {
            throw new ManagerDataException('Отметьте лично все три проверки ограничения FBS.', 422);
        }
        $who = $this->fresh($who, $revalidate);
        $store = $this->store($who, (int) $row->integration_id);
        if ($store->binding !== $row->store_binding || $this->policy->accountKey($store) !== $row->account_key) {
            throw new ManagerDataException('Ключ или привязка магазина изменились. Подготовьте новое решение.', 409);
        }
        $this->policy->locked($store, function ($state) use ($who, $row, $store, $fingerprint, $checks, $revalidate) {
            $op = Operation::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $this->confirm($op, $fingerprint);
            if ($op->status !== 'awaiting_confirmation') {
                return;
            }
            $this->available();
            $currentWho = $this->fresh($who, $revalidate);
            $latest = $this->store($currentWho, (int) $op->integration_id);
            if ($latest->binding !== $op->store_binding || $latest->name !== $op->store_name || $this->policy->accountKey($latest) !== $op->account_key) {
                throw new ManagerDataException('Доступ магазина изменился под блокировкой. Решение не применено.', 409);
            }
            $this->verifyNative($latest);
            $this->fresh($currentWho, $revalidate);
            $this->available();
            if ($op->expires_at->isPast()) {
                $op->update(['status' => 'expired', 'message' => 'Срок истёк до сохранения. Ограничение не менялось.']);
                $this->event($op, 'expired');

                return;
            }
            if ($this->policy->publicControl($state) !== $op->plan['before']) {
                $op->update(['status' => 'cancelled', 'message' => 'Ограничение уже изменено другим решением. Требуется новое согласование.']);
                $this->event($op, 'cancelled');

                return;
            }
            if ((int) $state->version >= PHP_INT_MAX) {
                throw new ManagerDataException('Версия ограничения не может быть изменена.', 503);
            }
            $version = (int) $state->version + 1;
            $at = now()->utc();
            DB::table(ManagerFbsExecutionPolicy::TABLE)->where('account_key', $state->account_key)->update(['mode' => $op->plan['target_mode'], 'version' => $version,
                'changed_by' => $who->userId, 'workspace_id' => $who->workspaceId, 'integration_id' => $store->integrationId, 'reason' => $op->plan['reason'], 'updated_at' => $at]);
            $applied = ['mode' => $op->plan['target_mode'], 'version' => (string) $version, 'changed_at' => $at->toRfc3339String()];
            $op->update(['status' => 'succeeded', 'message' => 'Ограничение сохранено. Состояния заказов на площадке этим не менялись.',
                'result' => ['applied_control' => $applied, 'confirmed_by' => (string) $who->userId, 'confirmed_at' => $at->toRfc3339String(),
                    'native_fbs_changed' => false, 'already_started_may_finish' => true, 'automations_resumed' => false, 'confirmations' => $checks]]);
            $this->event($op, 'succeeded');
        });

        return $this->expose($who, $this->get($who, $id));
    }

    public function expose(ManagerIdentity $who, Operation $op): array
    {
        $this->confirm($op, $op->fingerprint);
        $current = null;
        try {
            $store = $this->store($who, (int) $op->integration_id);
            if ($this->policy->accountKey($store) === $op->account_key) {
                $state = DB::table(ManagerFbsExecutionPolicy::TABLE)->where('account_key', $op->account_key)->first();
                if ($state) {
                    $current = $this->policy->publicControl($state);
                }
            }
        } catch (ManagerDataException) { /* The owned receipt survives loss of current store access. */
        }

        return ['id' => $op->id, 'operation_type' => 'fbs_execution_control', 'workspace_id' => (string) $op->workspace_id,
            'integration_id' => (string) $op->integration_id, 'integration_name' => $op->store_name, 'marketplace' => $op->marketplace,
            'fingerprint' => $op->fingerprint, 'status' => $op->status, 'before' => $op->plan['before'], 'target_mode' => $op->plan['target_mode'],
            'reason' => $op->plan['reason'], 'scope' => $op->plan['scope'], 'source' => $op->plan['source'],
            'required_confirmations' => $this->checks(), 'current_control' => $current, 'current_control_available' => $current !== null, 'current_checked_at' => now()->utc()->toRfc3339String(), 'administrative_stop' => ! config('services.manager.fbs_writes_enabled', true),
            'message' => $op->message, 'result' => $op->result, 'created_at' => $op->created_at->utc()->toRfc3339String(), 'expires_at' => $op->expires_at->utc()->toRfc3339String()];
    }

    private function publicContext(ManagerIdentity $who, ManagerStore $store, object $row): array
    {
        $control = $this->policy->publicControl($row);

        return ['workspace_id' => (string) $who->workspaceId, 'integration_id' => (string) $store->integrationId, 'integration_name' => $store->name, 'marketplace' => $store->marketplace,
            'control' => $control, 'scope' => $this->scope(), 'source' => ['name' => 'marketplace_api', 'observed_at' => now()->utc()->toRfc3339String(), 'store_access_verified' => true],
            'administrative_stop' => ! config('services.manager.fbs_writes_enabled', true),
            'context_fingerprint' => hash('sha256', $this->canonical([$who->workspaceId, $who->userId, $store->binding, $row->account_key, $control, $this->scope()]))];
    }

    private function scope(): array
    {
        return ['kind' => 'products_fbs_seller', 'physical_seller_aliases_included' => true, 'other_services_covered' => false,
            'commands' => ['single_fbs', 'ozon_packing_queue', 'ozon_carriages', 'wb_fbs_supplies', 'legacy_products_fbs'],
            'reading_and_documents_available' => true, 'already_started_may_finish' => true, 'native_fbs_changed' => false, 'automations_resumed' => false];
    }

    private function checks(): array
    {
        return [['key' => self::CHECKS[0], 'label' => 'Проверил магазин и область: продавец и его подключения в исполнителях FBS Products.'],
            ['key' => self::CHECKS[1], 'label' => 'Понимаю: новые команды блокируются, но начатое действие может завершиться; статусы заказов сами не меняются.'],
            ['key' => self::CHECKS[2], 'label' => 'Открытие допускает только новые личные согласования. Старые карточки, очереди и автоматизации не возобновляются.']];
    }

    private function verifyNative(ManagerStore $store): void
    {
        $this->gateway->read($store, ['since' => now()->subDays(1)->utc()->toRfc3339String(), 'to' => now()->utc()->toRfc3339String()], '', 1);
        $this->policy->accountKey($store);
    }

    private function store(ManagerIdentity $who, int $integration): ManagerStore
    {
        $local = Integration::where('work_space_id', $who->workspaceId)->whereKey($integration)->first();
        $market = match (strtolower((string) $local?->marketplace)) {
            'wb', 'wildberries' => 'wildberries', 'ozon' => 'ozon', default => null
        };
        if ($market === null) {
            throw new ManagerDataException('Интеграция не найдена в выбранном workspace.', 404);
        }

        return $this->stores->resolve($who, $integration, $market);
    }

    private function permission(ManagerIdentity $who, bool $write = false): void
    {
        foreach (['auto_supply.view', 'integrations.view', ...($write ? ['auto_supply.create'] : [])] as $slug) {
            if (! $who->permits($slug)) {
                throw new ManagerDataException('Недостаточно прав для ограничения FBS.', 403);
            }
        }
        if ($write && ! $who->personalConfirmed) {
            throw new ManagerDataException('CRM должна явно подтвердить личный аккаунт.', 403);
        }
    }

    private function fresh(ManagerIdentity $expected, callable $revalidate): ManagerIdentity
    {
        $who = $revalidate();
        if (! $who instanceof ManagerIdentity || $who->userId !== $expected->userId || $who->workspaceId !== $expected->workspaceId) {
            throw new ManagerDataException('Личная сессия изменилась.', 403);
        }
        $this->permission($who, true);

        return $who;
    }

    private function available(): void
    {
        if (config('services.manager.fbs_execution_controls_enabled', false) !== true || ! Schema::hasTable('manager_fbs_execution_operations') || ! Schema::hasTable('manager_fbs_execution_events')) {
            throw new ManagerDataException('Личное ограничение FBS выключено или ожидает обновления схемы.', 503);
        }
    }

    private function fingerprint(Operation $op): string
    {
        return hash('sha256', $this->canonical(['fbs-execution-v1', $op->id, (int) $op->workspace_id, (int) $op->user_id, (int) $op->integration_id,
            $op->marketplace, $op->store_name, $op->store_binding, $op->account_key, $op->plan, $op->expires_at->timestamp]));
    }

    private function confirm(Operation $op, string $fingerprint): void
    {
        if (! hash_equals($op->fingerprint, $fingerprint) || ! hash_equals($op->fingerprint, $this->fingerprint($op))) {
            throw new ManagerDataException('Не подтверждён неизменяемый состав ограничения FBS.', 409);
        }
    }

    private function canonical(mixed $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            } if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($sort, $v);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function event(Operation $row, string $status): void
    {
        DB::table('manager_fbs_execution_events')->insert(['operation_id' => $row->id, 'status' => $status, 'created_at' => now()]);
    }
}
