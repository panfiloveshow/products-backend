<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Integration;
use App\Models\ManagerFbsOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Exact single-task proposals; no LLM-supplied quantities, endpoint or credentials. */
final class ManagerFbsOperationService
{
    public function __construct(private ManagerStoreResolver $stores, private ManagerFbsGateway $gateway, private ManagerFbsPublicationGuard $guard, private ManagerFbsCancellationGateway $cancellations) {}

    public function prepare(ManagerIdentity $identity, int $integrationId, string $externalId, string $type, ?string $supplyId, ?array $cancellation = null): array
    {
        $this->permission($identity, true);
        $this->available();
        if ($type === 'fbs_cancel') { $this->cancellationPermission($identity); $this->cancellationAvailable(); }
        $store = $this->store($identity, $integrationId);
        if (ManagerFbsOperation::where('workspace_id', $identity->workspaceId)->where('integration_id', $integrationId)->where('marketplace', $store->marketplace)
            ->where('external_id', $externalId)->whereNotNull('resource_key')->exists()) {
            throw new ManagerDataException('Для этого задания есть незавершённая отправка. Сначала проверьте её результат.', 409);
        }
        // Verify ownership with this seller's API before looking for global task aliases.
        // Approval always rebuilds the draft under the shared lock.
        $plan = $this->plan($store, $externalId, $type, $supplyId, $cancellation);

        return $this->guard->runMany($store->marketplace, $this->publicationTargets($plan), function () use ($identity, $store, $externalId, $type, $plan) {
            $id = (string) Str::uuid();
            $fingerprint = hash('sha256', json_encode([$id, $identity->workspaceId, $identity->userId, $store->integrationId, $plan], JSON_THROW_ON_ERROR));
            $row = DB::transaction(function () use ($identity, $store, $externalId, $type, $plan, $id, $fingerprint) {
                $row = ManagerFbsOperation::create(['id' => $id, 'workspace_id' => $identity->workspaceId, 'user_id' => $identity->userId,
                    'integration_id' => $store->integrationId, 'marketplace' => $store->marketplace, 'external_id' => $externalId, 'store_name' => $store->name,
                    'store_binding' => $store->binding, 'operation_type' => $type, 'fingerprint' => $fingerprint, 'plan' => $plan,
                    'status' => 'awaiting_confirmation', 'expires_at' => now()->addMinutes(5)]);
                if ($type === 'fbs_cancel') {
                    $row->update(['fingerprint' => $this->cancellationFingerprint($row)]);
                }
                $this->event($id, 'awaiting_confirmation');

                return $row;
            });

            return $this->expose($row);
        });
    }

    public function get(ManagerIdentity $identity, string $id): ManagerFbsOperation
    {
        $this->permission($identity);
        $row = ManagerFbsOperation::whereKey($id)->where('workspace_id', $identity->workspaceId)->where('user_id', $identity->userId)->first();
        if (! $row) {
            throw new ManagerDataException('Операция не найдена.', 404);
        }
        if ($row->status === 'awaiting_confirmation' && $row->expires_at->isPast()) {
            $this->transition($id, ['awaiting_confirmation'], 'expired', 'Срок согласования истёк.');
        }
        if ($row->operation_type === 'fbs_cancel' && $row->status === 'executing' && $row->native_started_at === null && $row->expires_at->isPast()) {
            $this->abortBeforeNative($row, 'expired', 'Срок истёк до отправки. Команда площадке не запускалась.');
        }
        if ($row->status === 'executing' && $row->execution_started_at?->lt(now()->subMinutes(3))) {
            $this->transition($id, ['executing'], 'unknown', 'Выполнение прервано. Повторная отправка заблокирована.');
        }

        return $row->fresh();
    }

    public function cancel(ManagerIdentity $identity, string $id, string $fingerprint): array
    {
        $row = $this->get($identity, $id);
        $this->confirm($row, $fingerprint);
        $this->transition($id, ['awaiting_confirmation'], 'cancelled', 'Отменено пользователем.');

        return $this->expose($this->get($identity, $id));
    }

    private function publicationTargets(array $plan): array
    {
        // fbs_assign is validated by plan() as WB-only; its plan has no marketplace field.
        return [$plan['external_id'], ...($plan['operation_type'] === 'fbs_assign' ? ['wb-supply:'.$plan['supply_id']] : []), ...(isset($plan['_order_resource']) ? [$plan['_order_resource']] : [])];
    }

    public function approve(ManagerIdentity $identity, string $id, string $fingerprint, array $confirmations, ?callable $revalidate = null): array
    {
        $this->permission($identity, true);
        $this->available();
        $row = $this->get($identity, $id);
        $this->confirm($row, $fingerprint);
        if ($row->status !== 'awaiting_confirmation') {
            return $this->expose($row);
        }
        if ($revalidate === null) throw new ManagerDataException('Нужна повторная проверка личной сессии перед отправкой.', 503);
        if ($row->operation_type === 'fbs_cancel') {
            $this->cancellationPermission($identity); $this->cancellationAvailable();
        }
        $required = array_column($row->plan['required_confirmations'], 'key');
        if (array_diff(array_keys($confirmations), $required) || count($confirmations) !== count($required)
            || array_filter($confirmations, fn ($v) => $v !== true)) {
            throw new ManagerDataException('Подтвердите все проверки, указанные в предложении.', 422);
        }

        return $this->guard->runMany($row->marketplace, $this->publicationTargets($row->plan), function () use ($identity, $row, $id, $confirmations, $revalidate) {
            if ($revalidate !== null) $identity = $this->freshIdentity($identity, $revalidate, $row->operation_type);
            $store = $this->store($identity, (int) $row->integration_id);
            if ($store->binding !== $row->store_binding || $store->marketplace !== $row->marketplace) {
                $this->transition($id, ['awaiting_confirmation'], 'cancelled', 'Магазин или его ключ изменились. Подготовьте предложение заново.');

                return $this->expose($this->get($identity, $id));
            }
            try {
                $current = $this->plan($store, $row->external_id, $row->operation_type, $row->plan['supply_id'], $row->plan['cancellation'] ?? null);
            } catch (ManagerDataException $error) {
                if ($error->httpStatus !== 422) {
                    throw $error;
                }
                $this->transition($id, ['awaiting_confirmation'], 'cancelled', 'Состав, требования или состояние задания изменились. Подготовьте предложение заново.');

                return $this->expose($this->get($identity, $id));
            }
            $samePlan = $row->operation_type === 'fbs_cancel'
                ? $this->cancellations->canonicalJson($current) === $this->cancellations->canonicalJson($row->plan)
                : json_encode($current, JSON_THROW_ON_ERROR) === json_encode($row->plan, JSON_THROW_ON_ERROR);
            if (! $samePlan) {
                $this->transition($id, ['awaiting_confirmation'], 'cancelled', 'Задание или поставка изменились. Требуется новое согласование.');

                return $this->expose($this->get($identity, $id));
            }
            $this->available();
            try {
                $claimed = DB::transaction(function () use ($id, $row, $identity, $confirmations) {
                    $current = ManagerFbsOperation::whereKey($id)->lockForUpdate()->firstOrFail();
                    if ($current->status !== 'awaiting_confirmation' || $current->expires_at->isPast()) {
                        return false;
                    }
                    foreach ($this->publicationTargets($row->plan) as $target) DB::table('manager_fbs_resource_holds')->insert([
                        'resource_key' => ManagerFbsPublicationGuard::resource($row->marketplace, $target), 'operation_id' => $id, 'created_at' => now()]);
                    $current->update(['status' => 'executing', 'resource_key' => ManagerFbsPublicationGuard::resource($row->marketplace, $row->external_id),
                        'execution_started_at' => now(), 'confirmations' => ['values' => $confirmations, 'user_id' => $identity->userId, 'declared_at' => now()->utc()->toRfc3339String()]]);
                    $this->event($id, 'executing');

                    return true;
                });
            } catch (QueryException $error) {
                if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                    throw new ManagerDataException('Это задание уже занято другой отправкой.', 409);
                }
                throw $error;
            }
            if (! $claimed) {
                return $this->expose($this->get($identity, $id));
            }
            try {
                $identity = $this->freshIdentity($identity, $revalidate, $row->operation_type);
                $latestStore = $this->store($identity, (int) $row->integration_id);
                if ($row->operation_type === 'fbs_cancel') {
                    $latestPlan = $this->plan($latestStore, $row->external_id, 'fbs_cancel', null, $row->plan['cancellation']);
                    if ($this->cancellations->canonicalJson($latestPlan) !== $this->cancellations->canonicalJson($row->plan))
                        throw new ManagerDataException('Данные отмены изменились.', 422);
                    $current = $latestPlan;
                    // The extra cancellation reads can outlive credential changes too.
                    $latestStore = $this->store($identity, (int) $row->integration_id);
                }
                if ($latestStore->binding !== $row->store_binding || $latestStore->marketplace !== $row->marketplace || $latestStore->name !== $row->store_name)
                    throw new ManagerDataException('Доступ магазина изменился до отправки.', 422);
                $identity = $this->freshIdentity($identity, $revalidate, $row->operation_type);
                $this->available();
                if ($row->operation_type === 'fbs_cancel') $this->cancellationAvailable();
                $store = $latestStore;
            } catch (ManagerDataException $error) {
                $this->abortBeforeNative($row, 'failed', 'Права, доступ магазина или данные изменились до отправки. Команда площадке не запускалась.');
                if (in_array($error->httpStatus, [401, 403, 404], true)) throw $error;
                return $this->expose($this->get($identity, $id));
            }
            $started = DB::transaction(function () use ($row) {
                $locked = ManagerFbsOperation::whereKey($row->id)->lockForUpdate()->firstOrFail();
                $targets = array_map(fn ($target) => ManagerFbsPublicationGuard::resource($row->marketplace, $target), $this->publicationTargets($row->plan));
                $owned = DB::table('manager_fbs_resource_holds')->where('operation_id', $row->id)->whereIn('resource_key', $targets)->lockForUpdate()->get();
                if ($owned->count() !== count($targets) || $locked->expires_at->isPast() || ! config('services.manager.fbs_writes_enabled', true)) return false;
                $count = ManagerFbsOperation::whereKey($row->id)->where('workspace_id', $row->workspace_id)->where('user_id', $row->user_id)
                    ->where('fingerprint', $row->fingerprint)->where('status', 'executing')->whereNull('native_started_at')
                    ->where('resource_key', ManagerFbsPublicationGuard::resource($row->marketplace, $row->external_id))->where('expires_at', '>', now())
                    ->update(['native_started_at' => now()]);
                if ($count === 1) $this->event($row->id, 'native_started');
                return $count === 1;
            });
            if (! $started) {
                $this->abortBeforeNative($row, $row->fresh()->expires_at->isPast() ? 'expired' : 'failed', 'Срок или блокировка ресурсов изменились до отправки. Команда площадке не запускалась.');
                return $this->expose($this->get($identity, $id));
            }
            $result = $this->gateway->submit($store, $current);
            $message = ($result['stopped_before_native'] ?? false) ? 'Отправка остановлена ограничением FBS. Запрос на площадку не отправлен.' : match ($result['status']) {
                'verifying' => 'Площадка приняла запрос. Проверяется фактическое состояние API.',
                'failed' => 'Площадка отклонила запрос. Действие не подтверждено.',
                default => 'Результат запроса неизвестен. Повторная отправка заблокирована.',
            };
            $this->transition($id, ['executing'], $result['status'], $message, ['submission_acknowledged' => $result['acknowledged'], 'fields_verified' => false, 'physical_status' => null, ...(($result['stopped_before_native'] ?? false) ? ['native_attempted' => false] : [])]);

            return $this->reconcile($identity, $id);
        }, $id);
    }

    /** Read-only recovery. Matching state after a lost response does not prove authorship. */
    public function reconcile(ManagerIdentity $identity, string $id): array
    {
        $row = $this->get($identity, $id);
        if (! in_array($row->status, ['verifying', 'unknown'], true)) {
            return $this->expose($row);
        }
        try {
            $store = $this->store($identity, (int) $row->integration_id);
            if ($store->binding !== $row->store_binding || $store->marketplace !== $row->marketplace) {
                return $this->expose($row);
            }
            $cancellation = null;
            if ($row->operation_type === 'fbs_cancel') {
                $cancellation = $this->cancellations->observed($store, $row->plan);
                $state = $cancellation['vendor_state']; $matches = $cancellation['matches'];
            } elseif ($row->operation_type === 'fbs_pack') {
                $source = $this->gateway->ozonPosting($store, $row->external_id)['posting'];
                $state = $source['vendor_state'];
                $matches = in_array($state, ['awaiting_deliver', 'delivering', 'driver_pickup', 'acceptance_in_progress', 'delivered'], true)
                    && $this->productQuantities($source['items']) === $this->productQuantities($row->plan['before']['items']);
            } else {
                $supply = $this->gateway->wbSupply($store, $row->plan['supply_id']);
                $state = $this->gateway->wbOrderState($store, $row->external_id);
                $matches = in_array($state, ['confirm', 'complete'], true) && in_array($row->external_id, $supply['order_ids'], true);
            }
            $ack = ($row->result['submission_acknowledged'] ?? false) === true;
            $result = ['submission_acknowledged' => $ack, 'fields_verified' => $matches, 'vendor_state' => $state,
                'source' => 'marketplace_api', 'observed_at' => now()->utc()->toRfc3339String(), 'physical_status' => null];
            if ($cancellation !== null) {
                $result += ['cancellation_verified' => $matches, 'reason_verified' => $cancellation['reason_verified'], 'refund_status' => null, 'financial_effects_verified' => false];
            }
            if (! $ack) {
                $this->transition($id, ['unknown'], 'unknown', $matches ? 'Сейчас API возвращает ожидаемое состояние, но приём нашего запроса неизвестен. Нужна ручная проверка, повтор запрещён.' : 'Ожидаемое состояние API не подтверждено. Результат запроса неизвестен; повтор запрещён.', $result);
            } elseif ($matches) {
                $this->transition($id, ['verifying', 'unknown'], 'succeeded', $cancellation !== null
                    ? 'Площадка приняла запрос, отмена подтверждена API. Финансовые последствия и возврат денег не подтверждены.'
                    : 'Изменение состояния подтверждено API. Физическая передача товара не подтверждается этим действием.', $result);
            } elseif ($row->execution_started_at?->lt(now()->subMinutes(15))) {
                $this->transition($id, ['verifying'], 'unknown', 'Площадка приняла запрос, но ожидаемое состояние не подтверждено за 15 минут. Повтор запрещён.', $result);
            } else {
                $this->transition($id, ['verifying'], 'verifying', 'Ожидается подтверждение состояния API.', $result);
            }
        } catch (ManagerDataException) {
            // Keep the durable hold when fresh credentials or readback cannot be verified.
        }

        return $this->expose($this->get($identity, $id));
    }

    public function expose(ManagerFbsOperation $row): array
    {
        return ['id' => $row->id, 'operation_type' => $row->operation_type, 'workspace_id' => (string) $row->workspace_id,
            'integration_id' => (string) $row->integration_id, 'integration_name' => $row->store_name, 'marketplace' => $row->marketplace, 'external_id' => $row->external_id,
            'fingerprint' => $row->fingerprint, 'status' => $row->status, 'diff' => $row->plan['diff'], 'items' => $row->plan['before']['items'],
            'required_confirmations' => $row->plan['required_confirmations'], 'physical_status' => null, 'result' => $row->result, 'message' => $row->message,
            ...($row->operation_type === 'fbs_cancel' ? ['cancellation' => $row->plan['cancellation_details']] : []),
            'created_at' => $row->created_at->toIso8601String(), 'expires_at' => $row->expires_at->toIso8601String()];
    }

    private function plan(ManagerStore $store, string $externalId, string $type, ?string $supplyId, ?array $cancellation = null): array
    {
        if ($type === 'fbs_cancel') {
            if ($supplyId !== null || $cancellation === null) throw new ManagerDataException('Нужен отдельный состав отмены.', 422);
            return $this->withOrderTarget($store, $this->cancellations->plan($store, $externalId, $cancellation));
        }
        if ($type === 'fbs_pack' && $store->marketplace === 'ozon' && $supplyId === null) {
            $context = $this->gateway->ozonPosting($store, $externalId);
            $before = $context['posting'];
            if ($before['vendor_state'] !== 'awaiting_packaging' || $context['pack_blockers']) {
                throw new ManagerDataException('Требуется ручная проверка в разделе заказов: состояние, маркировка, IMEI, вес, коробки или схема доставки не допускают простой сборки.', 422);
            }
            $diff = [['field' => 'vendor_state', 'label' => 'Состояние в Ozon', 'before' => 'awaiting_packaging', 'after' => 'awaiting_deliver']];
            $confirmations = [['key' => 'items_and_packing_checked', 'label' => 'Я проверил товары, количество, упаковку и требования маркировки. Товары подготовлены к передаче.']];
            $supply = null;
        } elseif ($type === 'fbs_assign' && $store->marketplace === 'wildberries' && $supplyId !== null) {
            $rows = $this->gateway->read($store, [])['items'];
            $matches = array_values(array_filter($rows, fn ($r) => $r['external_id'] === $externalId));
            if (count($matches) !== 1) {
                throw new ManagerDataException('WB не подтвердил, что выбранное задание ещё новое.', 422);
            }
            $before = $matches[0];
            if ($before['delivery_type'] !== 'fbs' || $before['cross_border_type'] !== 0 || $before['is_b2b'] !== false
                || $before['cargo_type'] !== 1 || $before['required_metadata'] !== []) {
                throw new ManagerDataException('Для этого задания WB нужны ручная проверка маркировки, B2B, габаритов или трансграничной схемы в разделе заказов.', 422);
            }
            $supply = $this->gateway->wbSupply($store, $supplyId);
            if ($supply['done'] || in_array($externalId, $supply['order_ids'], true)
                || ($supply['order_ids'] && ($supply['cargo_type'] !== $before['cargo_type'] || $supply['cross_border_type'] !== 0 || $supply['is_b2b'] !== false))) {
                throw new ManagerDataException('Поставка закрыта, уже содержит задание или не подходит по типу товара.', 422);
            }
            $diff = [['field' => 'supply_id', 'label' => 'Поставка WB', 'before' => null, 'after' => $supplyId],
                ['field' => 'vendor_state', 'label' => 'Состояние в WB', 'before' => 'new', 'after' => 'confirm']];
            $confirmations = [];
        } else {
            throw new ManagerDataException('Неподдерживаемое действие для выбранного магазина.', 422);
        }

        return $this->withOrderTarget($store, ['operation_type' => $type, 'external_id' => $externalId, 'supply_id' => $supplyId, 'before' => $before, 'supply_before' => $supply, 'diff' => $diff, 'required_confirmations' => $confirmations]);
    }

    private function withOrderTarget(ManagerStore $store, array $plan): array
    {
        if ($store->marketplace === 'ozon') {
            if (! isset($plan['before']['order_id'])) throw new ManagerDataException('Ozon не подтвердил заказ для общего управления отправлениями.', 503);
            $plan['_order_resource'] = ManagerFbsPublicationGuard::ozonOrderTarget($store->clientId, $plan['before']['order_id']);
        }
        return app(ManagerFbsExecutionPolicy::class)->decorate($store, $plan);
    }

    public function cancellationContext(ManagerIdentity $identity, int $integrationId, string $externalId, ?array $window): array
    {
        $this->permission($identity);
        $store = $this->store($identity, $integrationId);
        $context = $this->cancellations->context($store, $externalId, $window);
        $canPrepare = $context['is_cancellable'] && $identity->personalConfirmed && $identity->permits('auto_supply.create')
            && config('services.manager.fbs_writes_enabled', true) && config('services.manager.fbs_cancellations_enabled', false)
            && \Illuminate\Support\Facades\Schema::hasColumn('manager_fbs_operations', 'native_started_at');
        return ['workspace_id' => (string) $identity->workspaceId, 'integration_id' => (string) $integrationId, 'integration_name' => $store->name,
            'marketplace' => $store->marketplace, 'external_id' => $externalId, ...$context,
            'source' => $store->marketplace === 'ozon' ? 'Ozon Seller API' : 'WB Marketplace API', 'retrieved_at' => now()->utc()->toRfc3339String(),
            'data_updated_at' => null, 'partial' => true, 'execution_allowed' => false, 'approval_available' => $canPrepare,
            'financial_effects_verified' => false, 'refund_status' => null,
            'warnings' => ['Это чтение текущего API. Отмена не отправлялась. Финансовые последствия и возврат денег не подтверждены.']];
    }

    private function cancellationPermission(ManagerIdentity $identity): void
    {
        $this->permission($identity, true);
        if (! $identity->personalConfirmed) throw new ManagerDataException('Для отмены CRM должна явно подтвердить личный аккаунт.', 403);
    }

    private function cancellationAvailable(): void
    {
        if (! config('services.manager.fbs_cancellations_enabled', false) || ! \Illuminate\Support\Facades\Schema::hasColumn('manager_fbs_operations', 'native_started_at'))
            throw new ManagerDataException('Личная отмена FBS остановлена или ожидает обновления схемы.', 503);
    }

    private function freshIdentity(ManagerIdentity $expected, callable $revalidate, string $type): ManagerIdentity
    {
        $fresh = $revalidate();
        if (! $fresh instanceof ManagerIdentity || $fresh->workspaceId !== $expected->workspaceId || $fresh->userId !== $expected->userId)
            throw new ManagerDataException('Личная сессия изменилась.', 403);
        $this->permission($fresh, true);
        if ($type === 'fbs_cancel') $this->cancellationPermission($fresh);
        return $fresh;
    }

    private function cancellationFingerprint(ManagerFbsOperation $row): string
    {
        return hash('sha256', $this->cancellations->canonicalJson(['manager_fbs_cancel_v1', $row->id, (int) $row->workspace_id, (int) $row->user_id,
            (int) $row->integration_id, $row->marketplace, $row->external_id, $row->store_name, $row->store_binding, $row->plan,
            $row->created_at->utc()->toIso8601String(), $row->expires_at->utc()->toIso8601String()]));
    }

    private function abortBeforeNative(ManagerFbsOperation $operation, string $status, string $message): void
    {
        DB::transaction(function () use ($operation, $status, $message) {
            $row = ManagerFbsOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'executing' || $row->native_started_at !== null) return;
            $row->update(['status' => $status, 'resource_key' => null, 'message' => $message,
                'result' => ['submission_acknowledged' => false, 'fields_verified' => false, 'native_attempted' => false, 'physical_status' => null]]);
            DB::table('manager_fbs_resource_holds')->where('operation_id', $row->id)->delete();
            $this->event($row->id, $status, $message);
        });
    }

    private function productQuantities(array $items): array
    {
        return array_map(fn ($p) => [$p['marketplace_product_id'], $p['quantity']], $items);
    }

    private function store(ManagerIdentity $identity, int $integrationId): ManagerStore
    {
        $local = Integration::whereKey($integrationId)->where('work_space_id', $identity->workspaceId)->first();
        if (! $local) {
            throw new ManagerDataException('Магазин не найден.', 404);
        }
        $market = match (strtolower((string) $local->marketplace)) {
            'wb', 'wildberries' => 'wildberries', 'ozon' => 'ozon', default => ''
        };
        if (! $market) {
            throw new ManagerDataException('Действия FBS поддерживают WB и Ozon.', 422);
        }

        return $this->stores->resolve($identity, $integrationId, $market);
    }

    private function permission(ManagerIdentity $identity, bool $write = false): void
    {
        foreach (['auto_supply.view', 'integrations.view', ...($write ? ['auto_supply.create'] : [])] as $slug) {
            if (! $identity->permits($slug)) {
                throw new ManagerDataException('Недостаточно прав для этого действия.', 403);
            }
        }
    }

    private function available(): void
    {
        if (! config('services.manager.fbs_writes_enabled', true)) {
            throw new ManagerDataException('Изменения FBS остановлены администратором.', 503);
        }
    }

    private function confirm(ManagerFbsOperation $row, string $fingerprint): void
    {
        if ($row->operation_type === 'fbs_cancel' && ! hash_equals($row->fingerprint, $this->cancellationFingerprint($row))) throw new ManagerDataException('Сохранённый состав отмены повреждён.', 409);
        if (! hash_equals($row->fingerprint, $fingerprint)) {
            throw new ManagerDataException('Отпечаток предложения изменился.', 409);
        }
    }

    private function transition(string $id, array $from, string $status, string $message, ?array $result = null): void
    {
        DB::transaction(function () use ($id, $from, $status, $message, $result) {
            $row = ManagerFbsOperation::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($row->status, $from, true)) {
                return;
            }
            $terminal = in_array($status, ['succeeded', 'failed', 'expired', 'cancelled'], true);
            $row->update(['status' => $status, 'message' => $message, ...($terminal ? ['resource_key' => null] : []), ...($result !== null ? ['result' => $result] : [])]);
            if ($terminal) DB::table('manager_fbs_resource_holds')->where('operation_id', $id)->delete();
            $this->event($id, $status, $message);
        });
    }

    private function event(string $id, string $status, ?string $message = null): void
    {
        DB::table('manager_fbs_operation_events')->insert(['operation_id' => $id, 'status' => $status, 'message' => $message, 'created_at' => now()]);
    }
}
