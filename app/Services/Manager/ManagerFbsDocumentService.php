<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Integration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Labels are downloaded by the employee; never injected into model context or treated as printed. */
final class ManagerFbsDocumentService
{
    public const MAX_BYTES = 8388608;

    public function __construct(private ManagerStoreResolver $stores, private ManagerFbsGateway $gateway, private ManagerOzonLabelService $ozonLabels) {}

    public function prepare(ManagerIdentity $identity, int $integrationId, string $externalId): array
    {
        $this->permission($identity);
        $store = $this->store($identity, $integrationId);
        $state = $this->state($store, $externalId);
        if ($store->marketplace === 'ozon' && $state !== 'awaiting_deliver') throw new ManagerDataException('Формирование этикетки Ozon доступно для отправления, ожидающего отгрузки.', 422);
        $id = (string) Str::uuid();
        $expires = now()->addMinutes(5)->timestamp;
        if ($store->marketplace === 'ozon') $this->ozonLabels->prepare($identity, $store, $id, $externalId, $expires);
        $ticket = Crypt::encryptString(json_encode(['version' => $store->marketplace === 'ozon' ? 2 : 1, 'id' => $id, 'workspace_id' => $identity->workspaceId, 'user_id' => $identity->userId,
            'integration_id' => $integrationId, 'marketplace' => $store->marketplace, 'binding' => $store->binding, 'external_id' => $externalId, 'expires_at' => $expires], JSON_THROW_ON_ERROR));

        return ['id' => $id, 'document_type' => 'fbs_label', 'workspace_id' => (string) $identity->workspaceId, 'integration_id' => (string) $integrationId,
            'integration_name' => $store->name, 'marketplace' => $store->marketplace, 'external_id' => $externalId, 'vendor_state' => $state,
            'format' => $store->marketplace === 'wildberries' ? 'png' : 'pdf', 'download_ticket' => $ticket,
            'expires_at' => gmdate(DATE_RFC3339, $expires), 'observed_at' => now()->utc()->toRfc3339String(), 'physical_status' => null];
    }

    public function download(ManagerIdentity $identity, string $ticket, ?callable $revalidate = null): array
    {
        $this->permission($identity);
        try {
            $data = json_decode(Crypt::decryptString($ticket), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ! in_array($data['version'] ?? null, [1, 2], true) || ! is_string($data['id'] ?? null)
                || ($data['workspace_id'] ?? null) !== $identity->workspaceId || ($data['user_id'] ?? null) !== $identity->userId
                || ! is_int($data['integration_id'] ?? null) || ! is_string($data['external_id'] ?? null) || ! is_string($data['binding'] ?? null)
                || ! is_int($data['expires_at'] ?? null) || $data['expires_at'] <= now()->timestamp) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new ManagerDataException('Ссылка на документ истекла или относится к другому пользователю. Подготовьте документ заново.', 410);
        }
        $store = $this->store($identity, $data['integration_id']);
        if ($store->binding !== $data['binding'] || $store->marketplace !== ($data['marketplace'] ?? null)) {
            throw new ManagerDataException('Магазин или его доступ изменились. Подготовьте документ заново.', 410);
        }
        $this->state($store, $data['external_id']);
        if ($store->marketplace === 'ozon') {
            if ($data['version'] !== 2 || $revalidate === null) throw new ManagerDataException('Для актуальной этикетки Ozon нужна новая личная подготовка.', 410);
            return $this->ozonLabels->download($identity, $data, $store, function (bool $creating) use ($identity, $data, $revalidate) {
                $fresh = $revalidate();
                if (! $fresh instanceof ManagerIdentity || $fresh->workspaceId !== $identity->workspaceId || $fresh->userId !== $identity->userId) throw new ManagerDataException('Личная сессия изменилась.', 403);
                $this->permission($fresh);
                $freshStore = $this->store($fresh, $data['integration_id']);
                if ($freshStore->binding !== $data['binding'] || $freshStore->marketplace !== 'ozon') throw new ManagerDataException('Доступ магазина изменился.', 410);
                $state = $this->state($freshStore, $data['external_id']);
                if ($creating && $state !== 'awaiting_deliver') throw new ManagerDataException('Ozon больше не подтверждает состояние для формирования этикетки.', 422);
                $lastIdentity = $revalidate();
                if (! $lastIdentity instanceof ManagerIdentity || $lastIdentity->workspaceId !== $identity->workspaceId || $lastIdentity->userId !== $identity->userId) throw new ManagerDataException('Личная сессия изменилась.', 403);
                $this->permission($lastIdentity);
                $lastStore = $this->store($lastIdentity, $data['integration_id']);
                if ($lastStore->binding !== $data['binding'] || $lastStore->marketplace !== 'ozon') throw new ManagerDataException('Доступ магазина изменился.', 410);
                return $lastStore;
            });
        }
        try {
            $client = Http::accept('*/*')->connectTimeout(3)->timeout(20)->withOptions(['allow_redirects' => false,
                'progress' => static function ($total, $downloaded) {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new \RuntimeException('Document too large');
                    }
                }]);
            if ($store->marketplace === 'wildberries') {
                $number = filter_var($data['external_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($number === false) {
                    throw new \RuntimeException;
                }
                $response = $client->withHeaders(['Authorization' => $store->apiKey])->post('https://marketplace-api.wildberries.ru/api/v3/orders/stickers?type=png&width=58&height=40', ['orders' => [$number]]);
                $rows = $response->json('stickers');
                if (! $response->successful() || ! is_array($rows) || count($rows) !== 1 || (string) ($rows[0]['orderId'] ?? '') !== $data['external_id'] || ! is_string($rows[0]['file'] ?? null)) {
                    throw new \RuntimeException;
                }
                $bytes = base64_decode($rows[0]['file'], true);
                if (! is_string($bytes) || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || ! @getimagesizefromstring($bytes)) {
                    throw new \RuntimeException;
                }
                $mime = 'image/png';
                $extension = 'png';
            }
            if (strlen($bytes) > self::MAX_BYTES || $bytes === '') {
                throw new \RuntimeException;
            }

            return ['bytes' => $bytes, 'mime' => $mime, 'filename' => 'fbs-label-'.$data['id'].'.'.$extension];
        } catch (\Throwable) {
            throw new ManagerDataException('Площадка не вернула проверенную этикетку. Документ не получен. Печать не подтверждена.');
        }
    }

    private function state(ManagerStore $store, string $externalId): string
    {
        $state = $store->marketplace === 'wildberries' ? $this->gateway->wbOrderState($store, $externalId) : $this->gateway->ozonPosting($store, $externalId)['posting']['vendor_state'];
        if (! in_array($state, $store->marketplace === 'wildberries' ? ['confirm', 'complete'] : ['awaiting_deliver', 'delivering', 'driver_pickup', 'acceptance_in_progress', 'delivered'], true)) {
            throw new ManagerDataException('Этикетка для этого состояния не подтверждена. Сначала проверьте сборку в разделе заказов.', 422);
        }

        return $state;
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
            throw new ManagerDataException('Этикетки подключены для WB и Ozon.', 422);
        }

        return $this->stores->resolve($identity, $integrationId, $market);
    }

    private function permission(ManagerIdentity $identity): void
    {
        foreach (['auto_supply.view', 'auto_supply.export', 'integrations.view'] as $slug) {
            if (! $identity->permits($slug)) {
                throw new ManagerDataException('Недостаточно прав на документы FBS.', 403);
            }
        }
    }
}
