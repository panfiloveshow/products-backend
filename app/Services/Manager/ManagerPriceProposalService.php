<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\ManagerPriceProposal;
use App\Models\Integration;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Immutable review drafts. Approval and dispatch belong to the execution stage. */
final class ManagerPriceProposalService
{
    public function __construct(private ManagerPriceService $prices, private ManagerStoreResolver $stores,
        private ManagerPricePolicy $policy, private ManagerPriceApproval $approvals = new ManagerPriceApproval,
        private ManagerPriceExecutorClient $executor = new ManagerPriceExecutorClient,
        private ManagerPriceControlClient $controls = new ManagerPriceControlClient) {}

    public function prepare(ManagerIdentity $identity, int $integrationId, string $productId, string $scheme, string $price, array $constraints, #[\SensitiveParameter] string $token = ''): array
    {
        $this->permission($identity, true); $this->available();
        $constraints = $this->policy->normalize($constraints);
        $price = DecimalMoney::normalize($price);
        $captured = $this->prices->forProposal($identity, $integrationId, $productId, $scheme, $price, $constraints);
        if (($captured['scenario']['status'] ?? null) !== 'forecast' || ! is_array($captured['_proposal_binding'] ?? null)) {
            throw new ManagerDataException('Сначала получите согласованный финансовый прогноз выбранной цены.', 409);
        }
        $captured = $this->captureControl($identity, $integrationId, $captured, $token);
        $binding = $captured['_proposal_binding']; unset($captured['_proposal_binding']);
        $row = new ManagerPriceProposal(['id' => (string) Str::uuid(), 'workspace_id' => $identity->workspaceId, 'created_by' => $identity->userId,
            'integration_id' => $integrationId, 'product_id' => $productId, 'product_sku' => $captured['sku'],
            'integration_name' => $captured['integration_name'], 'marketplace' => $captured['marketplace'], 'fulfillment_type' => $scheme,
            'seller_price' => $price, 'constraints' => $constraints, 'snapshot' => $captured, 'binding' => $binding,
            'status' => 'ready_for_review', 'created_at' => now()->utc(), 'expires_at' => now()->utc()->addMinutes(5), 'events' => []]);
        $row->fingerprint = $this->fingerprint($row);
        $this->event($row, 'prepared');

        return $this->expose($row, $identity);
    }

    public function get(ManagerIdentity $identity, string $id): array
    {
        $row = $this->owned($identity, $id); $this->scope($identity, $row);
        DB::transaction(function () use ($row) {
            $locked = ManagerPriceProposal::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $this->integrity($locked, $locked->fingerprint);
            if ($locked->status === 'ready_for_review' && $locked->expires_at->isPast()) $this->event($locked, 'expired', ['status' => 'expired']);
        });

        return $this->expose($row->fresh(), $identity);
    }

    public function cancel(ManagerIdentity $identity, string $id, string $fingerprint): array
    {
        $row = $this->owned($identity, $id); $this->scope($identity, $row);
        DB::transaction(function () use ($row, $fingerprint) {
            $locked = ManagerPriceProposal::whereKey($row->id)->lockForUpdate()->firstOrFail(); $this->integrity($locked, $fingerprint);
            if ($locked->status === 'ready_for_review') $this->event($locked, 'cancelled', ['status' => 'cancelled']);
        });

        return $this->get($identity, $id);
    }

    public function recheck(ManagerIdentity $identity, string $id, string $fingerprint, #[\SensitiveParameter] string $token = ''): array
    {
        $this->permission($identity, true); $this->available();
        $row = $this->owned($identity, $id); $this->integrity($row, $fingerprint);
        $original = $this->get($identity, $id);
        if ($original['status'] !== 'ready_for_review') return $original;
        try {
            $current = $this->prices->forProposal($identity, (int) $row->integration_id, $row->product_id, $row->fulfillment_type, $row->seller_price, $row->constraints);
            $current = $this->captureControl($identity, (int) $row->integration_id, $current, $token);
        } catch (ManagerDataException $error) {
            if (! in_array($error->httpStatus, [409, 422], true)) throw $error;
            $current = []; // A definite incompatibility revokes this review.
        }
        $same = $this->encoded($current['_proposal_binding'] ?? null) === $this->encoded($row->binding)
            && ($current['scenario']['status'] ?? null) === 'forecast'
            && $this->encoded($this->comparison($current)) === $this->encoded($this->comparison($row->snapshot));
        DB::transaction(function () use ($row, $fingerprint, $same) {
            $locked = ManagerPriceProposal::whereKey($row->id)->lockForUpdate()->firstOrFail(); $this->integrity($locked, $fingerprint);
            if ($locked->status !== 'ready_for_review') return;
            if ($locked->expires_at->isPast()) $this->event($locked, 'expired', ['status' => 'expired']);
            elseif (! $same) $this->event($locked, 'source_changed', ['status' => 'cancelled', 'message_code' => 'source_changed']);
            else $this->event($locked, 'snapshot_rechecked');
        });

        return $this->get($identity, $id);
    }

    public function approve(ManagerIdentity $identity, string $id, string $fingerprint, string $reviewHash, array $confirmations, #[\SensitiveParameter] string $token): array
    {
        $this->executionPermission($identity); $this->executionAvailable();
        if (count($confirmations) !== 2 || ($confirmations['financial_assumptions_checked'] ?? null) !== true
            || ($confirmations['exact_price_and_limits_checked'] ?? null) !== true) throw new ManagerDataException('Лично проверьте допущения расчёта, точную цену и ограничения.', 422);
        $row = $this->owned($identity, $id); $this->integrity($row, $fingerprint);
        // Any later approval request reads the previous result. It cannot send
        // another request after a crash, a timeout or an uncertain native write.
        if ($row->status !== 'ready_for_review') return $this->get($identity, $id);
        $checked = $this->recheck($identity, $id, $fingerprint, $token);
        if ($checked['status'] !== 'ready_for_review') return $checked;
        $row->refresh();
        $review = $this->approvals->review($row);
        if (! hash_equals($review['hash'], $reviewHash)) throw new ManagerDataException('Состав личной проверки изменился.', 409);
        $store = $this->stores->resolve($identity, (int) $row->integration_id, $row->marketplace);
        $command = $this->approvals->command($row, $store, $review);
        $claimed = DB::transaction(function () use ($row, $fingerprint, $command, $review, $confirmations): bool {
            $locked = ManagerPriceProposal::whereKey($row->id)->lockForUpdate()->firstOrFail(); $this->integrity($locked, $fingerprint);
            if ($locked->status !== 'ready_for_review') return false;
            if ($locked->expires_at->isPast()) { $this->event($locked, 'expired', ['status' => 'expired']); return false; }
            $this->event($locked, 'personally_approved', ['status' => 'executing', 'dispatch_started_at' => now()->utc(),
                'approval' => ['version' => 'manager_price_approval_record_v1', 'command' => $command, 'review' => $review,
                    'confirmations' => $confirmations, 'confirmed_by' => (string) $locked->created_by, 'confirmed_at' => now()->utc()->toIso8601String()]]);
            return true;
        });
        if ($claimed) {
            try { $receipt = $this->executor->send($identity, $token, $command); $this->recordReceipt($row, $receipt); }
            catch (\Throwable) {
                DB::transaction(function () use ($row) {
                    $locked = ManagerPriceProposal::whereKey($row->id)->lockForUpdate()->firstOrFail();
                    if ($locked->status === 'executing') $this->event($locked, 'receipt_unavailable', ['status' => 'unknown', 'message_code' => 'receipt_unavailable']);
                });
            }
        }
        return $this->get($identity, $id);
    }

    public function reconcile(ManagerIdentity $identity, string $id, #[\SensitiveParameter] string $token): array
    {
        $row = $this->owned($identity, $id); $this->scope($identity, $row); $this->integrity($row, $row->fingerprint);
        if (in_array($row->status, ['executing', 'verifying', 'unknown'], true) && $row->approval !== null) {
            // Reconciliation is read-only at the native transport boundary.
            // A missing operation is not permission to try sending it again.
            try { $this->recordReceipt($row, $this->executor->read($identity, $token, $id)); } catch (\Throwable) {}
        }
        return $this->get($identity, $id);
    }

    public function validateExecution(ManagerIdentity $identity, string $id, array $command, #[\SensitiveParameter] string $token = ''): array
    {
        $this->executionPermission($identity); $this->executionAvailable();
        $row = $this->owned($identity, $id); $this->scope($identity, $row); $this->integrity($row, $row->fingerprint);
        $approval = $row->approval;
        if (! in_array($row->status, ['executing', 'verifying', 'unknown'], true) || $row->expires_at->isPast() || ! is_array($approval)
            || ($approval['version'] ?? null) !== 'manager_price_approval_record_v1' || ($approval['confirmed_by'] ?? null) !== (string) $identity->userId
            || ! is_array($approval['command'] ?? null) || $this->approvals->encodedCommand($command) !== $this->approvals->encodedCommand($approval['command'])
            || $this->approvals->encoded($approval['review'] ?? null) !== $this->approvals->encoded($this->approvals->review($row))) {
            throw new ManagerDataException('Нет действующего личного согласования именно этой команды.', 409);
        }
        $current = $this->prices->forProposal($identity, (int) $row->integration_id, $row->product_id, $row->fulfillment_type, $row->seller_price, $row->constraints);
        $current = $this->captureControl($identity, (int) $row->integration_id, $current, $token);
        if ($this->encoded($current['_proposal_binding'] ?? null) !== $this->encoded($row->binding)
            || $this->encoded($this->comparison($current)) !== $this->encoded($this->comparison($row->snapshot))) {
            throw new ManagerDataException('Исходная цена или финансовый расчёт изменились перед отправкой.', 409);
        }
        // Recheck the owner's stop/status after the potentially slow native read.
        $row->refresh(); $this->executionAvailable();
        if ($row->expires_at->isPast() || ! in_array($row->status, ['executing', 'verifying', 'unknown'], true)) throw new ManagerDataException('Согласование цены завершилось.', 409);
        return ['version' => 'manager_price_validation_v1', 'operation_id' => $row->id, 'proposal_fingerprint' => $row->fingerprint,
            'financial_review_hash' => $approval['review']['hash'], 'execution_allowed' => true, 'expires_at' => $row->expires_at->utc()->toIso8601String()];
    }

    private function recordReceipt(ManagerPriceProposal $row, array $receipt): void
    {
        if (($receipt['proposal_fingerprint'] ?? null) !== $row->fingerprint || ($receipt['operation_id'] ?? null) !== $row->id
            || ($receipt['marketplace'] ?? null) !== ($row->marketplace === 'wildberries' ? 'wb' : 'ozon')) throw new ManagerDataException('Квитанция относится к другой команде.', 503);
        DB::transaction(function () use ($row, $receipt) {
            $locked = ManagerPriceProposal::whereKey($row->id)->lockForUpdate()->firstOrFail(); $this->integrity($locked, $row->fingerprint);
            if (! in_array($locked->status, ['executing', 'verifying', 'unknown'], true)) return;
            $oldId = $locked->executor_receipt['native_change_id'] ?? null;
            if ($oldId !== null && $oldId !== $receipt['native_change_id']) throw new ManagerDataException('Сменился журнал исполнения цены.', 409);
            if ($locked->status === 'verifying' && $receipt['status'] === 'executing') return;
            if ($locked->status === 'unknown' && $receipt['status'] === 'executing') return;
            $this->event($locked, 'native_receipt_'.$receipt['status'], ['status' => $receipt['status'], 'executor_receipt' => $receipt, 'message_code' => null]);
        });
    }

    private function executionPermission(ManagerIdentity $identity): void
    {
        $this->permission($identity, true);
        foreach (['marketing.manage', 'integrations.view'] as $p) if (! $identity->permits($p)) throw new ManagerDataException('Нет личного права на изменение цен.', 403);
    }
    private function executionAvailable(): void
    {
        $this->available();
        if (! config('services.manager.price_execution_enabled', false) || ! config('services.manager.price_executor_url')
            || ! Schema::hasColumn('manager_price_proposals', 'approval')) throw new ManagerDataException('Исполнение цен Сели остановлено или ожидает настройки.', 503);
    }

    private function owned(ManagerIdentity $identity, string $id): ManagerPriceProposal
    {
        $this->permission($identity, false);
        $row = ManagerPriceProposal::whereKey($id)->where('workspace_id', $identity->workspaceId)->where('created_by', $identity->userId)->first();
        if (! $row) throw new ManagerDataException('Предложение цены не найдено.', 404);

        return $row;
    }

    private function scope(ManagerIdentity $identity, ManagerPriceProposal $row): void
    {
        $product = Product::whereKey($row->product_id)->where('integration_id', $row->integration_id)->first();
        $integration = Integration::whereKey($row->integration_id)->where('work_space_id', $identity->workspaceId)->first();
        if (! $integration || ! $product || $product->marketplace !== $row->marketplace || $integration->marketplace !== $row->marketplace) throw new ManagerDataException('Товар предложения не найден.', 404);
        $this->stores->resolve($identity, (int) $row->integration_id, $row->marketplace);
    }

    private function permission(ManagerIdentity $identity, bool $prepare): void
    {
        if ($prepare && ! $identity->personalConfirmed) throw new ManagerDataException('CRM должна подтвердить личный аккаунт для подготовки цены.', 403);
        foreach (['products.view', 'marketing.view', 'unit_economics.view', ...($prepare ? ['unit_economics.calculate'] : [])] as $permission) {
            if (! $identity->permits($permission)) throw new ManagerDataException('Недостаточно прав на предложение цены.', 403);
        }
    }

    private function available(): void
    {
        if (! config('services.manager.price_proposals_enabled', false) || ! Schema::hasTable('manager_price_proposals')) {
            throw new ManagerDataException('Подготовка предложений цены Сели остановлена или ожидает обновления схемы.', 503);
        }
    }

    private function fingerprint(ManagerPriceProposal $row): string
    {
        return hash('sha256', json_encode($this->stable(['manager_price_proposal_v1', $row->id, (string) $row->workspace_id, (string) $row->created_by,
            (string) $row->integration_id, $row->product_id, $row->product_sku, $row->marketplace, $row->fulfillment_type, $row->integration_name,
            DecimalMoney::normalize($row->seller_price), $row->constraints, $row->snapshot, $row->binding,
            $row->created_at->utc()->toIso8601String(), $row->expires_at->toIso8601String()]), JSON_THROW_ON_ERROR));
    }

    private function integrity(ManagerPriceProposal $row, string $fingerprint): void
    {
        if (! hash_equals($row->fingerprint, $fingerprint) || ! hash_equals($row->fingerprint, $this->fingerprint($row))) throw new ManagerDataException('Состав предложения цены изменился.', 409);
    }

    private function stable(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (! array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->stable($item);

        return $value;
    }

    private function encoded(mixed $value): string
    {
        return json_encode($this->stable($value), JSON_THROW_ON_ERROR);
    }

    private function captureControl(ManagerIdentity $identity, int $integrationId, array $snapshot, #[\SensitiveParameter] string $token): array
    {
        $snapshot['price_execution_control'] = config('services.manager.price_execution_enabled', false) && ($snapshot['scenario']['status'] ?? null) === 'forecast'
            ? $this->controls->capture($identity, $token, $integrationId, $snapshot) : null;
        return $snapshot;
    }

    private function comparison(array $snapshot): array
    {
        // Observation/inspection clocks may advance without changing the exact
        // native baseline, money, constraints or provenance being reviewed.
        unset($snapshot['_proposal_binding'], $snapshot['snapshot']['observed_at'], $snapshot['scenario']['financial_evidence']['checked_at'],
            $snapshot['scenario']['before']['calculated_at'], $snapshot['scenario']['after']['calculated_at']);

        return $snapshot;
    }

    private function event(ManagerPriceProposal $row, string $event, array $changes = []): void
    {
        $row->forceFill([...$changes, 'events' => [...($row->events ?? []), ['event' => $event, 'at' => now()->utc()->toIso8601String()]]])->save();
    }

    private function expose(ManagerPriceProposal $row, ManagerIdentity $identity): array
    {
        $review = null;
        try { $review = $this->approvals->review($row); } catch (ManagerDataException) {}
        $approvalAvailable = $review !== null && $row->status === 'ready_for_review' && ! $row->expires_at->isPast()
            && ($row->snapshot['price_execution_control']['version'] ?? null) === 'price_execution_context_v1'
            && $identity->personalConfirmed && $identity->permits('marketing.manage') && $identity->permits('integrations.view') && $identity->permits('unit_economics.calculate')
            && config('services.manager.price_execution_enabled', false) && config('services.manager.price_executor_url')
            && Schema::hasColumn('manager_price_proposals', 'approval');
        return ['schema_version' => 1, 'operation_type' => 'price_change_proposal', 'id' => $row->id,
            'workspace_id' => (string) $row->workspace_id, 'integration_id' => (string) $row->integration_id, 'marketplace' => $row->marketplace,
            'product_id' => $row->product_id, 'product_sku' => $row->product_sku, 'fulfillment_type' => $row->fulfillment_type,
            'seller_price' => $row->seller_price, 'constraints' => $row->constraints, 'snapshot' => $row->snapshot,
            'fingerprint' => $row->fingerprint, 'status' => $row->status, 'message_code' => $row->message_code,
            'execution_allowed' => false, 'price_changed' => $row->status === 'succeeded', 'approval_available' => (bool) $approvalAvailable,
            'personal_review' => $review, 'executor_receipt' => $row->executor_receipt,
            'created_at' => $row->created_at->utc()->toIso8601String(), 'expires_at' => $row->expires_at->toIso8601String()];
    }
}
