<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\ManagerFbsOperation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Physical task IDs are locked independently of workspace aliases, API token and application-key rotation. */
final class ManagerFbsPublicationGuard
{
    public function run(string $marketplace, string $externalId, callable $callback, ?string $ownOperation = null): mixed
    {
        return $this->runMany($marketplace, [$externalId], $callback, $ownOperation);
    }

    public function runMany(string $marketplace, array $externalIds, callable $callback, ?string $ownOperation = null, ?string $parentOperation = null): mixed
    {
        if (! config('services.manager.fbs_writes_enabled', true)) {
            throw new ManagerDataException('Изменения FBS остановлены администратором.', 503);
        }
        if (! Schema::hasTable('manager_fbs_resource_holds')) throw new ManagerDataException('Общий журнал блокировок FBS не установлен.', 503);
        if (! $externalIds || count($externalIds) > 2001) throw new ManagerDataException('Непроверенный размер группы FBS.', 422);
        $keys = array_values(array_unique(array_map(fn ($id) => self::resource($marketplace, $id), $externalIds)));
        sort($keys, SORT_STRING);
        $locks = [];
        try {
            foreach ($keys as $key) {
                $lock = Cache::lock('manager-fbs-target:'.$key, 180);
                if (! $lock->get()) throw new ManagerDataException('Это задание или группа уже обрабатывается.', 409);
                $locks[] = $lock;
            }
            if (ManagerFbsOperation::whereIn('resource_key', $keys)
                ->when($ownOperation, fn ($q) => $q->where('id', '!=', $ownOperation))->exists()) {
                throw new ManagerDataException('Есть незавершённая отправка этого задания. Сначала проверьте её результат.', 409);
            }
            $owners = array_values(array_filter([$ownOperation, $parentOperation]));
            if (DB::table('manager_fbs_resource_holds')->whereIn('resource_key', $keys)->when($owners, fn ($q) => $q->whereNotIn('operation_id', $owners))->exists())
                throw new ManagerDataException('Отправление включено в незавершённую операцию отгрузки. Сначала проверьте её результат.', 409);

            return $callback();
        } finally {
            foreach (array_reverse($locks) as $lock) $lock->release();
        }
    }

    public static function ozonOrderTarget(string $clientId, string $orderId): string
    {
        foreach ([$clientId, $orderId] as $id) if (! preg_match('/^[1-9][0-9]{0,18}$/D', $id) || strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)
            throw new ManagerDataException('Ozon не подтвердил идентификатор продавца или заказа.', 503);
        return 'ozon-order:'.$clientId.':'.$orderId;
    }

    public static function resource(string $marketplace, string $externalId): string
    {
        return hash('sha256', $marketplace.':'.$externalId);
    }
}
