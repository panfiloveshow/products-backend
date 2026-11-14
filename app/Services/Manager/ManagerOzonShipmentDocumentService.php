<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Integration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class ManagerOzonShipmentDocumentService
{
    public function __construct(private ManagerStoreResolver $stores, private ManagerOzonShipmentGateway $gateway) {}

    public function listing(ManagerIdentity $identity, int $integrationId, string $from, string $to): array
    {
        $this->permission($identity, false);
        $store = $this->store($identity, $integrationId);
        return ['workspace_id' => (string) $identity->workspaceId, 'integration_id' => (string) $integrationId,
            'integration_name' => $store->name, 'marketplace' => 'ozon'] + $this->gateway->readList($store, $from, $to);
    }

    public function prepare(ManagerIdentity $identity, int $integrationId, string $carriageId): array
    {
        $this->permission($identity);
        $store = $this->store($identity, $integrationId);
        $details = $this->gateway->details($store, $carriageId);
        $id = (string) Str::uuid();
        $expires = now()->addMinutes(5)->timestamp;
        $ticket = Crypt::encryptString(json_encode(['version' => 1, 'document_type' => 'fbs_shipment_documents', 'id' => $id,
            'workspace_id' => $identity->workspaceId, 'user_id' => $identity->userId, 'integration_id' => $integrationId,
            'binding' => $store->binding, 'expires_at' => $expires, 'details' => $details], JSON_THROW_ON_ERROR));
        return ['id' => $id, 'document_type' => 'fbs_shipment_documents', 'workspace_id' => (string) $identity->workspaceId,
            'integration_id' => (string) $integrationId, 'integration_name' => $store->name, 'marketplace' => 'ozon',
            'external_id' => $carriageId, 'format' => 'pdf', 'download_ticket' => $ticket,
            'expires_at' => gmdate(DATE_RFC3339, $expires), 'observed_at' => now()->utc()->toRfc3339String(), 'physical_status' => null] + $details;
    }

    public function download(ManagerIdentity $identity, string $ticket, callable $revalidate): array
    {
        $this->permission($identity);
        try {
            $data = json_decode(Crypt::decryptString($ticket), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['version'] ?? null) !== 1 || ($data['document_type'] ?? null) !== 'fbs_shipment_documents'
                || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id']) || ($data['workspace_id'] ?? null) !== $identity->workspaceId
                || ($data['user_id'] ?? null) !== $identity->userId || ! is_int($data['integration_id'] ?? null)
                || ! is_string($data['binding'] ?? null) || ! is_int($data['expires_at'] ?? null) || ! is_array($data['details'] ?? null)) throw new \RuntimeException;
        } catch (\Throwable) {
            throw new ManagerDataException('Подготовка документа повреждена или относится к другому пользователю.', 410);
        }
        $fresh = function () use ($revalidate, $identity, $data): ManagerStore {
            if ($data['expires_at'] <= now()->timestamp) throw new ManagerDataException('Срок подготовки документов истёк.', 410);
            $current = $revalidate();
            if (! $current instanceof ManagerIdentity || $current->userId !== $identity->userId || $current->workspaceId !== $identity->workspaceId) throw new ManagerDataException('Личная сессия изменилась.', 403);
            $this->permission($current);
            $store = $this->store($current, $data['integration_id']);
            if ($store->binding !== $data['binding']) throw new ManagerDataException('Доступ магазина изменился. Подготовьте документы заново.', 410);
            if ($data['expires_at'] <= now()->timestamp) throw new ManagerDataException('Срок подготовки документов истёк.', 410);
            return $store;
        };
        $store = $fresh();
        $current = $this->gateway->details($store, $data['details']['carriage_id']);
        if ($this->basis($current) !== $this->basis($data['details'])) throw new ManagerDataException('Документы или параметры отгрузки изменились. Подготовьте карточку заново.', 410);
        $status = $this->gateway->readiness($store, $current);
        $store = $fresh();
        if ($status !== 'ready') {
            return ['document_status' => $status, 'can_retry' => $status === 'pending', 'retry_after_seconds' => $status === 'pending' ? 2 : 0,
                'message' => $status === 'pending' ? 'Ozon ещё формирует документы существующей отгрузки. Повторная проверка не создаёт отгрузку.' : 'Ozon не подтвердил документы этой отгрузки. Проверьте кабинет.',
                'http_status' => $status === 'pending' ? 202 : 409];
        }
        $file = $this->gateway->pdf($store, $current['carriage_id']);
        // Never release bytes to a session whose export right/store changed during the vendor request.
        $fresh();
        return $file + ['filename' => 'fbs-shipment-'.$data['id'].'.pdf'];
    }

    private function basis(array $details): array
    {
        unset($details['vendor_state']); // Already available documents can outlive a carriage status transition.
        return $details;
    }

    private function store(ManagerIdentity $identity, int $integrationId): ManagerStore
    {
        $local = Integration::whereKey($integrationId)->where('work_space_id', $identity->workspaceId)->first();
        if (! $local) throw new ManagerDataException('Магазин не найден.', 404);
        if (strtolower((string) $local->marketplace) !== 'ozon') throw new ManagerDataException('Документы отгрузки подключены для Ozon FBS.', 422);
        return $this->stores->resolve($identity, $integrationId, 'ozon');
    }

    private function permission(ManagerIdentity $identity, bool $export = true): void
    {
        foreach (['auto_supply.view', 'integrations.view', ...($export ? ['auto_supply.export'] : [])] as $slug) {
            if (! $identity->permits($slug)) throw new ManagerDataException('Недостаточно прав на документы отгрузки.', 403);
        }
    }
}
