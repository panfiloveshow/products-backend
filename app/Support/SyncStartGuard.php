<?php

namespace App\Support;

use App\Models\SyncLog;

final class SyncStartGuard
{
    /**
     * В старых схемах БД marketplace был ENUM с "yandex", без "yandex_market".
     * Для sync_logs / products сохраняем каноническое значение, совместимое с ENUM.
     */
    public static function storageMarketplace(string $marketplace): string
    {
        return $marketplace === 'yandex_market' ? 'yandex' : $marketplace;
    }

    public static function isYandexFamily(string $marketplace): bool
    {
        return in_array($marketplace, ['yandex', 'yandex_market'], true);
    }

    public static function cacheLockKey(string $syncType, string $marketplace, ?int $integrationId): string
    {
        $mp = self::storageMarketplace($marketplace);

        return sprintf('sync:start:%s:%s:%s', $syncType, $mp, $integrationId ?? 'null');
    }

    /**
     * Активная синхронизация: pending (джоба ещё не взята) или running.
     */
    public static function findActiveDuplicate(string $syncType, string $marketplace, ?int $integrationId): ?SyncLog
    {
        $query = SyncLog::query()
            ->where('sync_type', $syncType)
            ->whereIn('status', [SyncLog::STATUS_PENDING, SyncLog::STATUS_RUNNING]);

        if (self::isYandexFamily($marketplace)) {
            $query->whereIn('marketplace', ['yandex', 'yandex_market']);
        } else {
            $query->where('marketplace', $marketplace);
        }

        if ($integrationId !== null) {
            $query->where('integration_id', $integrationId);
        }

        return $query->first();
    }
}
