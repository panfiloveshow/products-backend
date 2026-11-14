<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Read-only Ozon catalogue, independent of inventory observations or draft availability. */
final class ManagerSupplyDestinationCatalog
{
    private const TTL = 120;

    public function __construct(private ManagerStoreResolver $stores) {}

    public function read(ManagerIdentity $who, int $integration, int $page, int $limit, ?string $snapshot, ?string $clusterId = null): array
    {
        foreach (['auto_supply.view', 'integrations.view'] as $permission) {
            if (! $who->permits($permission)) {
                throw new ManagerDataException('Недостаточно прав на планирование и магазин.', 403);
            }
        }
        $local = Integration::whereKey($integration)->where('work_space_id', $who->workspaceId)->first();
        if (! $local || ! in_array($local->marketplace, ['wildberries', 'ozon'], true)) {
            throw new ManagerDataException('Магазин не найден.', 404);
        }
        $store = $this->stores->resolve($who, $integration, $local->marketplace);
        $base = ['schema_version' => 1, 'workspace_id' => (string) $who->workspaceId, 'integration_id' => (string) $integration,
            'integration_name' => $store->name, 'marketplace' => $store->marketplace, 'scope' => 'marketplace_supply_destination_catalog',
            'availability_checked' => false, 'execution_allowed' => false, 'data_updated_at' => null, 'source_freshness_verified' => false, 'partial' => true];
        if ($store->marketplace === 'wildberries') {
            if ($snapshot !== null || $page !== 1 || $clusterId !== null) {
                throw new ManagerDataException('У неподключённого справочника нет страниц снимка.', 422);
            }

            return [...$base, 'catalog_kind' => 'clusters', 'cluster' => null, 'catalog_status' => 'unavailable', 'catalog_complete' => false,
                'unavailable_reason' => 'adapter_capability_not_enabled', 'snapshot_id' => null, 'snapshot_fingerprint' => null,
                'read_at' => now()->utc()->toRfc3339String(), 'expires_at' => null, 'items' => [], 'page' => 1, 'limit' => $limit,
                'total' => null, 'has_more' => false, 'next_page' => null,
                'source' => ['name' => 'products_adapter_capability', 'native_endpoint' => null, 'catalog_type' => null, 'native_access_verified' => false]];
        }
        if ($snapshot === null) {
            if ($page !== 1 || $clusterId !== null) {
                throw new ManagerDataException('Сначала прочитайте первую страницу и используйте её snapshot_id.', 422);
            }
            $items = $this->readNative($store);
            $snapshot = (string) Str::uuid();
            $at = CarbonImmutable::now('UTC');
            $entry = ['owner' => [$who->workspaceId, $who->userId, $integration], 'binding' => $store->binding, 'name' => $store->name,
                'snapshot_id' => $snapshot, 'limit' => $limit, 'read_at' => $at->toRfc3339String(),
                'expires_at' => $at->addSeconds(self::TTL)->toRfc3339String(), 'items' => $items];
            $entry['fingerprint'] = $this->fingerprint($entry);
            if (! Cache::put($this->cacheKey($who, $integration, $snapshot), $entry, self::TTL)) {
                throw new ManagerDataException('Не удалось сохранить снимок справочника.', 503);
            }
        } else {
            $entry = Cache::get($this->cacheKey($who, $integration, $snapshot));
        }
        if (! is_array($entry) || ($entry['owner'] ?? null) !== [$who->workspaceId, $who->userId, $integration]
            || ($entry['binding'] ?? null) !== $store->binding || ($entry['name'] ?? null) !== $store->name
            || ($entry['snapshot_id'] ?? null) !== $snapshot || ($entry['limit'] ?? null) !== $limit
            || ! is_string($entry['fingerprint'] ?? null) || ! hash_equals($entry['fingerprint'], $this->fingerprint($entry))
            || CarbonImmutable::parse($entry['expires_at'])->isPast()) {
            throw new ManagerDataException('Снимок справочника истёк или магазин изменился. Начните с первой страницы.', 409);
        }
        $cluster = null;
        $rows = array_map($this->clusterHeader(...), $entry['items']);
        if ($clusterId !== null) {
            $matches = array_values(array_filter($entry['items'], fn ($row) => $row['native_cluster_id'] === $clusterId));
            if (count($matches) !== 1) {
                throw new ManagerDataException('Кластер не найден в этом снимке.', 404);
            }
            $cluster = $this->clusterHeader($matches[0]);
            $rows = $matches[0]['warehouses'];
        }
        $total = count($rows);
        $offset = ($page - 1) * $limit;
        $items = array_slice($rows, $offset, $limit);
        $more = $offset + count($items) < $total;

        return [...$base, 'catalog_kind' => $clusterId === null ? 'clusters' : 'warehouses', 'cluster' => $cluster, 'catalog_status' => 'available', 'catalog_complete' => true, 'unavailable_reason' => null,
            'snapshot_id' => $snapshot, 'snapshot_fingerprint' => $entry['fingerprint'], 'read_at' => $entry['read_at'], 'expires_at' => $entry['expires_at'],
            'items' => $items, 'page' => $page, 'limit' => $limit, 'total' => $total, 'has_more' => $more, 'next_page' => $more ? $page + 1 : null,
            'source' => ['name' => 'Ozon Seller API', 'native_endpoint' => 'POST /v1/cluster/list', 'catalog_type' => 'CLUSTER_TYPE_OZON', 'native_access_verified' => true]];
    }

    private function clusterHeader(array $cluster): array
    {
        $cluster['warehouses_count'] = count($cluster['warehouses']);
        unset($cluster['warehouses']);

        return $cluster;
    }

    public function readNative(ManagerStore $store): array
    {
        if ($store->marketplace !== 'ozon') {
            throw new ManagerDataException('Нужен проверенный магазин Ozon.', 422);
        }
        $response = Http::withHeaders(['Client-Id' => $store->clientId, 'Api-Key' => $store->apiKey])->acceptJson()->timeout(30)
            ->withOptions(['allow_redirects' => false])->post('https://api-seller.ozon.ru/v1/cluster/list', ['cluster_type' => 'CLUSTER_TYPE_OZON']);
        if (in_array($response->status(), [401, 403], true)) {
            throw new ManagerDataException('Ozon не подтвердил доступ к справочнику. Проверьте ключ и категорию доступа магазина.', 403);
        }
        if ($response->status() === 429) {
            throw new ManagerDataException('Ozon ограничил частоту чтения справочника. Повторите чтение позже.', 429);
        }
        if (! $response->successful() || strlen($response->body()) > 20_000_000) {
            $this->bad();
        }
        $raw = $response->json();
        if (! is_array($raw) || ! empty($raw['error']) || ! empty($raw['code']) || ! empty($raw['errors'])) {
            $this->bad();
        }
        $nested = is_array($raw['result'] ?? null) && array_key_exists('clusters', $raw['result']);
        $flat = array_key_exists('clusters', $raw);
        if ($nested === $flat) {
            $this->bad();
        }
        $body = $nested ? $raw['result'] : $raw;
        foreach ([$raw, $body] as $envelope) {
            foreach (['has_next', 'has_more', 'cursor', 'next_cursor', 'next_page', 'last_id'] as $key) {
                if (array_key_exists($key, $envelope) && $envelope[$key] !== false && $envelope[$key] !== '') {
                    $this->bad();
                }
            }
        }
        $clusters = $body['clusters'];
        if (! is_array($clusters) || ! array_is_list($clusters) || count($clusters) > 10000) {
            $this->bad();
        }
        $out = [];
        $warehouseCount = 0;
        foreach ($clusters as $cluster) {
            if (! is_array($cluster)) {
                $this->bad();
            }
            $id = $this->id($cluster['id'] ?? null);
            if (isset($out[$id])) {
                $this->bad();
            }
            $logistics = $cluster['logistic_clusters'] ?? null;
            if (! is_array($logistics) || ! array_is_list($logistics)) {
                $this->bad();
            }
            $warehouses = [];
            foreach ($logistics as $logistic) {
                if (! is_array($logistic) || ! is_array($logistic['warehouses'] ?? null) || ! array_is_list($logistic['warehouses'])) {
                    $this->bad();
                }
                $logisticId = isset($logistic['id']) ? $this->id($logistic['id'], true) : null;
                foreach ($logistic['warehouses'] as $warehouse) {
                    if (! is_array($warehouse) || ++$warehouseCount > 100000) {
                        $this->bad();
                    }
                    if (isset($warehouse['warehouse_id'], $warehouse['id']) && (string) $warehouse['warehouse_id'] !== (string) $warehouse['id']) {
                        $this->bad();
                    }
                    $warehouseId = $this->id($warehouse['warehouse_id'] ?? $warehouse['id'] ?? null);
                    $key = $warehouseId.'|'.($logisticId ?? 'none');
                    if (isset($warehouses[$key])) {
                        $this->bad();
                    }
                    $warehouses[$key] = ['id' => $warehouseId, 'name' => $this->text($warehouse['name'] ?? null),
                        'type' => $this->text($warehouse['type'] ?? null), 'logistic_cluster_id' => $logisticId, 'marketplace_available' => null];
                }
            }
            ksort($warehouses, SORT_STRING);
            $out[$id] = ['id' => 'cluster:'.$id, 'native_cluster_id' => $id,
                'macrolocal_cluster_id' => isset($cluster['macrolocal_cluster_id']) ? $this->id($cluster['macrolocal_cluster_id'], true) : null,
                'name' => $this->text($cluster['name'] ?? null), 'type' => isset($cluster['type']) ? $this->text($cluster['type']) : null,
                'marketplace_available' => null, 'warehouses' => array_values($warehouses)];
        }
        ksort($out, SORT_STRING);

        return array_values($out);
    }

    private function id(mixed $value, bool $zero = false): string
    {
        if (! (is_int($value) || is_string($value)) || ! preg_match($zero ? '/^(0|[1-9][0-9]{0,18})$/D' : '/^[1-9][0-9]{0,18}$/D', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $zero ? 0 : 1]]) === false) {
            $this->bad();
        }

        return (string) $value;
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 500) {
            $this->bad();
        }

        return $value;
    }

    private function fingerprint(array $entry): string
    {
        unset($entry['fingerprint']);

        return hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function cacheKey(ManagerIdentity $who, int $integration, string $snapshot): string
    {
        return 'manager:supply-catalog:'.hash('sha256', implode('|', [$who->workspaceId, $who->userId, $integration, $snapshot]));
    }

    private function bad(): never
    {
        throw new ManagerDataException('Площадка не подтвердила полный справочник кластеров и складов Ozon.', 503);
    }
}
