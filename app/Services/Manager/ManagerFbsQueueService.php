<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** WB pages share a short-lived captured queue; Ozon uses its native v4 cursor and fixed dates. */
final class ManagerFbsQueueService
{
    public function __construct(private ManagerStoreResolver $stores, private ManagerFbsGateway $gateway) {}

    public function read(ManagerIdentity $identity, int $integrationId, ?int $limit, ?string $cursor): array
    {
        $integration = Integration::query()->whereKey($integrationId)->where('work_space_id', $identity->workspaceId)->first();
        if (! $integration) {
            throw new ManagerDataException('Магазин не найден в выбранном workspace.', 404);
        }
        $marketplace = match (strtolower((string) $integration->marketplace)) {
            'wb', 'wildberries' => 'wildberries', 'ozon' => 'ozon', default => ''
        };
        if ($marketplace === '') {
            throw new ManagerDataException('Очередь сборки подключена для WB и Ozon.', 422);
        }
        $store = $this->stores->resolve($identity, $integrationId, $marketplace);
        $context = $cursor ? $this->decode($cursor, $identity, $store) : null;
        $limit ??= $context['limit'] ?? 25;
        if ($limit < 1 || $limit > 100 || ($context && $context['limit'] !== $limit)) {
            throw new ManagerDataException('Для продолжения нужен тот же допустимый лимит.', 422);
        }
        $context ??= ['version' => 1, 'workspace_id' => $identity->workspaceId, 'user_id' => $identity->userId, 'integration_id' => $integrationId,
            'marketplace' => $marketplace, 'binding' => $store->binding, 'limit' => $limit, 'expires_at' => now()->addMinutes(5)->timestamp,
            'snapshot_id' => (string) Str::uuid(), 'offset' => 0, 'vendor_cursor' => '',
            'window' => $marketplace === 'ozon' ? ['since' => now()->utc()->subDays(30)->toIso8601ZuluString(), 'to' => now()->utc()->toIso8601ZuluString()] : []];
        $next = null;
        if ($marketplace === 'wildberries') {
            $cacheKey = 'manager-fbs-queue:'.$identity->workspaceId.':'.$identity->userId.':'.$context['snapshot_id'];
            if (! $cursor) {
                $source = $this->gateway->read($store, []);
                $snapshot = ['items' => $source['items'], 'observed_at' => now()->utc()->toRfc3339String()];
                if (! Cache::put($cacheKey, $snapshot, 300)) {
                    throw new ManagerDataException('Не удалось сохранить краткий снимок очереди.');
                }
            } else {
                $snapshot = Cache::get($cacheKey);
                if (! is_array($snapshot) || ! is_array($snapshot['items'] ?? null)) {
                    throw new ManagerDataException('Снимок очереди истёк. Получите очередь заново.', 410);
                }
            }
            $items = array_slice($snapshot['items'], $context['offset'], $limit);
            $hasMore = count($snapshot['items']) > $context['offset'] + count($items);
            if ($hasMore) {
                $context['offset'] += $limit;
                $next = $this->encode($context);
            }
            $observedAt = $snapshot['observed_at'];
        } else {
            $source = $this->gateway->read($store, $context['window'], $context['vendor_cursor'], $limit);
            $items = $source['items'];
            $hasMore = $source['has_next'];
            $observedAt = now()->utc()->toRfc3339String();
            if ($hasMore) {
                if ($source['cursor'] === $context['vendor_cursor']) {
                    throw new ManagerDataException('Источник повторил курсор. Продолжение не подтверждено.');
                }
                $context['vendor_cursor'] = $source['cursor'];
                $next = $this->encode($context);
            }
        }

        return ['workspace_id' => (string) $identity->workspaceId, 'integration_id' => (string) $integrationId, 'integration_name' => $store->name,
            'marketplace' => $marketplace, 'scope' => $marketplace === 'wildberries' ? 'new_assembly_tasks' : 'awaiting_packaging_last_30_days',
            'window' => $context['window'], 'snapshot_id' => $context['snapshot_id'], 'source_observed_at' => $observedAt, 'expires_at' => gmdate(DATE_RFC3339, $context['expires_at']),
            'items' => $items, 'limit' => $limit, 'has_more' => $hasMore, 'next_cursor' => $next, 'partial' => true,
            'source' => $marketplace === 'wildberries' ? 'WB Marketplace API' : 'Ozon Seller API', 'physical_status' => null,
            '_warnings' => [$marketplace === 'wildberries' ? 'Показаны новые сборочные задания WB на момент снимка; остальные стадии заказа сюда не входят.' : 'Показаны ожидающие упаковки отправления Ozon за последние 30 дней. Это не все заказы магазина.',
                'Сведения API не подтверждают фактическую сборку и передачу товара. Маркировку и физические действия подтверждает сотрудник.']];
    }

    private function encode(array $context): string
    {
        return Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR));
    }

    private function decode(string $cursor, ManagerIdentity $identity, ManagerStore $store): array
    {
        try {
            $context = json_decode(Crypt::decryptString($cursor), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($context) || ($context['version'] ?? null) !== 1 || ($context['workspace_id'] ?? null) !== $identity->workspaceId
                || ($context['user_id'] ?? null) !== $identity->userId || ($context['integration_id'] ?? null) !== $store->integrationId
                || ($context['marketplace'] ?? null) !== $store->marketplace || ($context['binding'] ?? null) !== $store->binding
                || ! is_int($context['expires_at'] ?? null) || $context['expires_at'] <= now()->timestamp || ! Str::isUuid($context['snapshot_id'] ?? '')
                || ! is_int($context['limit'] ?? null) || $context['limit'] < 1 || $context['limit'] > 100 || ! is_int($context['offset'] ?? null) || $context['offset'] < 0
                || ! is_string($context['vendor_cursor'] ?? null) || ! is_array($context['window'] ?? null)) {
                throw new \RuntimeException;
            }

            return $context;
        } catch (\Throwable) {
            throw new ManagerDataException('Курсор истёк или относится к другому пользователю, магазину либо доступу. Получите очередь заново.', 410);
        }
    }
}
