<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Services\SellicoApiService;
use Illuminate\Support\Facades\Http;

/** Revalidates integration ownership/status instead of trusting a local key cache. */
final class ManagerStoreResolver
{
    public function __construct(private SellicoApiService $crm) {}

    public function resolve(ManagerIdentity $identity, int $integrationId, string $marketplace): ManagerStore
    {
        try {
            $token = $this->crm->getServiceToken();
            if (! is_string($token) || $token === '') {
                throw new \RuntimeException;
            }
            $base = rtrim((string) config('services.sellico.base_url', 'https://sellico.ru/api'), '/');
            $response = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])
                ->get($base.'/get-integrations/'.$identity->workspaceId);
            $rows = $response->json('data') ?? $response->json();
            if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows)) {
                throw new \RuntimeException;
            }
            $matches = [];
            foreach ($rows as $row) {
                if (! is_array($row) || (string) ($row['work_space_id'] ?? '') !== (string) $identity->workspaceId) {
                    throw new \RuntimeException;
                }
                if ((string) ($row['id'] ?? '') === (string) $integrationId) {
                    $matches[] = $row;
                }
            }
            if (count($matches) !== 1) {
                throw new \RuntimeException;
            }
            $row = $matches[0];
            $type = match (strtolower((string) ($row['type'] ?? ''))) {
                'wildberries', 'wb' => 'wildberries', 'ozon' => 'ozon', default => ''
            };
            if ($type !== $marketplace || ($row['status'] ?? '') !== 'active') {
                throw new \RuntimeException;
            }
            $credentials = is_array($row['credentials'] ?? null) ? $row['credentials'] : $row;
            $key = $credentials['api_key'] ?? $credentials['token'] ?? null;
            $client = $credentials['client_id'] ?? $credentials['seller_id'] ?? null;
            if (! is_string($key) || $key === '' || ($type === 'ozon' && (! is_scalar($client) || (string) $client === ''))) {
                throw new \RuntimeException;
            }
            $secret = (string) config('app.key');
            if ($secret === '') {
                throw new \RuntimeException;
            }
            $binding = hash_hmac('sha256', json_encode([$identity->workspaceId, $integrationId, $type, $key, $client, $row['updated_at'] ?? null], JSON_THROW_ON_ERROR), $secret);

            return new ManagerStore($identity->workspaceId, $integrationId, $type, (string) ($row['name'] ?? ''), $key, $client === null ? null : (string) $client, $binding);
        } catch (\Throwable) {
            throw new ManagerDataException('Не удалось подтвердить активный магазин в выбранном workspace.');
        }
    }
}
