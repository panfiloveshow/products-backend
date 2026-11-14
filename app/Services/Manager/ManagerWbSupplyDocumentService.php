<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Integration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class ManagerWbSupplyDocumentService
{
    public function __construct(private ManagerStoreResolver $stores, private ManagerWbSupplyGateway $gateway) {}
    public function prepare(ManagerIdentity $w, int $integration, string $supply, string $kind, ?string $box): array
    {
        $this->permission($w); $s = $this->store($w, $integration); $proof = $this->proof($s, $supply, $kind, $box);
        $id = (string) Str::uuid(); $expires = now()->startOfSecond()->addMinutes(5)->timestamp;
        $ticket = ['version' => 1, 'id' => $id, 'workspace_id' => $w->workspaceId, 'user_id' => $w->userId, 'integration_id' => $integration,
            'binding' => $s->binding, 'supply_id' => $supply, 'document_kind' => $kind, 'box_id' => $box, 'snapshot' => $proof['hash'], 'expires_at' => $expires];
        return ['id' => $id, 'document_type' => 'wb_fbs_supply_document', 'workspace_id' => (string) $w->workspaceId, 'integration_id' => (string) $integration,
            'integration_name' => $s->name, 'marketplace' => 'wildberries', 'external_id' => $supply, 'document_kind' => $kind, 'box_id' => $box,
            'vendor_state' => $proof['supply']['done'] ? 'closed' : 'open', 'state_basis' => 'supply.done', 'order_count' => count($proof['members']),
            'format' => 'png', 'identity_basis' => $kind === 'supply_qr' ? 'native_barcode_matches_supply_id' : 'single_box_request',
            'download_ticket' => Crypt::encryptString(json_encode($ticket, JSON_THROW_ON_ERROR)), 'expires_at' => gmdate(DATE_RFC3339, $expires),
            'observed_at' => now()->utc()->toRfc3339String(), 'physical_status' => null];
    }
    public function download(ManagerIdentity $w, string $ticket, callable $revalidate): array
    {
        $this->permission($w);
        try {
            $d = json_decode(Crypt::decryptString($ticket), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($d) || ($d['version'] ?? null) !== 1 || ($d['workspace_id'] ?? null) !== $w->workspaceId || ($d['user_id'] ?? null) !== $w->userId
                || ! is_int($d['integration_id'] ?? null) || ! Str::isUuid($d['id'] ?? '') || ! is_string($d['binding'] ?? null) || ! is_string($d['snapshot'] ?? null)
                || ! is_string($d['supply_id'] ?? null) || ! in_array($d['document_kind'] ?? null, ['supply_qr', 'box_label'], true)
                || ! array_key_exists('box_id', $d) || ($d['box_id'] !== null && ! is_string($d['box_id'])) || ! is_int($d['expires_at'] ?? null) || $d['expires_at'] <= now()->timestamp) throw new \RuntimeException;
        } catch (\Throwable) { throw new ManagerDataException('Личная подготовка документа истекла или повреждена.', 410); }
        $fresh = function () use ($w, $d, $revalidate) {
            $actor = $revalidate();
            if (! $actor instanceof ManagerIdentity || $actor->workspaceId !== $w->workspaceId || $actor->userId !== $w->userId) throw new ManagerDataException('Личная сессия изменилась.', 403);
            $this->permission($actor); $store = $this->store($actor, $d['integration_id']);
            if ($store->binding !== $d['binding'] || $d['expires_at'] <= now()->timestamp) throw new ManagerDataException('Доступ или срок документа изменились.', 410);
            return $store;
        };
        $s = $fresh(); $proof = $this->proof($s, $d['supply_id'], $d['document_kind'], $d['box_id']);
        if (! hash_equals($d['snapshot'], $proof['hash'])) throw new ManagerDataException('Состав или параметры поставки изменились. Подготовьте документ заново.', 410);
        $s = $fresh(); $file = $this->gateway->document($s, $d['supply_id'], $d['document_kind'], $d['box_id']);
        $s = $fresh(); $proof = $this->proof($s, $d['supply_id'], $d['document_kind'], $d['box_id']);
        if (! hash_equals($d['snapshot'], $proof['hash'])) throw new ManagerDataException('Состав или параметры поставки изменились во время чтения файла.', 410);
        $fresh();
        return $file + ['filename' => 'wb-'.($d['document_kind'] === 'box_label' ? 'box-label' : 'supply-qr').'-'.$d['id'].'.png'];
    }
    private function proof(ManagerStore $s, string $supply, string $kind, ?string $box): array
    {
        if (! in_array($kind, ['supply_qr', 'box_label'], true) || ($kind === 'supply_qr' ? $box !== null : $box === null)) throw new ManagerDataException('Нужен точный вид документа и грузоместо для этикетки.', 422);
        $native = $this->gateway->supply($s, $supply); $members = $this->gateway->members($s, $supply); $boxes = $this->gateway->boxes($s, $supply);
        if ($kind === 'supply_qr' && ! $native['done']) throw new ManagerDataException('QR-код WB доступен после передачи поставки в доставку.', 422);
        if ($kind === 'box_label' && ! in_array($box, $boxes, true)) throw new ManagerDataException('Грузоместо не подтверждено в выбранной поставке.', 422);
        return ['supply' => $native, 'members' => $members, 'hash' => hash('sha256', json_encode([$native, $members, $boxes, $kind, $box], JSON_THROW_ON_ERROR))];
    }
    private function permission(ManagerIdentity $w): void
    {
        foreach (['auto_supply.view', 'integrations.view', 'auto_supply.export'] as $p) if (! $w->permits($p)) throw new ManagerDataException('Недостаточно прав на документы WB.', 403);
        if (! $w->personalConfirmed) throw new ManagerDataException('Нужна личная сессия для документа WB.', 403);
    }
    private function store(ManagerIdentity $w, int $id): ManagerStore
    {
        $local = Integration::whereKey($id)->where('work_space_id', $w->workspaceId)->first();
        if (! $local) throw new ManagerDataException('Магазин не найден.', 404);
        if (! in_array(strtolower((string) $local->marketplace), ['wb', 'wildberries'], true)) throw new ManagerDataException('Нужен магазин WB.', 422);
        return $this->stores->resolve($w, $id, 'wildberries');
    }
}
