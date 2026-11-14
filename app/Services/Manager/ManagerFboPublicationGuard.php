<?php

declare(strict_types=1);

namespace App\Services\Manager;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Serializes the identified Products FBO writers by Native seller account. */
final class ManagerFboPublicationGuard
{
    public static function key(string $kind, string $account, string $id = ''): string
    {
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $account) || strlen($account) === 19 && strcmp($account, '9223372036854775807') > 0) {
            throw new ManagerDataException('Не подтверждён продавец Ozon.', 503);
        }

        return hash('sha256', 'ozon-fbo:'.$kind.':'.$account.':'.$id);
    }

    public function run(string $account, callable $callback, ?string $own = null): mixed
    {
        if (! Schema::hasTable('manager_fbo_resource_holds')) {
            throw new ManagerDataException('Журнал FBO не установлен.', 503);
        }
        $key = self::key('account', $account);
        $lock = Cache::lock('manager-fbo:'.$key, 180);
        if (! $lock->get()) {
            throw new ManagerDataException('Продавец уже выполняет операцию FBO.', 409);
        }
        try {
            if (DB::table('manager_fbo_resource_holds')->where('resource_key', $key)->when($own, fn ($q) => $q->where('operation_id', '!=', $own))->exists()) {
                throw new ManagerDataException('Есть незавершённая отправка FBO. Сначала проверьте её журнал.', 409);
            }

            return $callback();
        } finally {
            $lock->release();
        }
    }

    public static function isMutation(string $endpoint): bool
    {
        return in_array($endpoint, ['/v1/draft/direct/create', '/v1/draft/crossdock/create', '/v1/draft/multi-cluster/create', '/v2/draft/supply/create',
            '/v1/supply-order/content/update', '/v1/fbp/draft/direct/timeslot/edit'], true);
    }

    public function legacy(?string $account, callable $callback, ?string $draft = null): mixed
    {
        if (! Schema::hasTable('manager_fbo_resource_holds')) {
            if (config('services.manager.ozon_fbo_enabled', false)) {
                throw new ManagerDataException('Журнал FBO не установлен.', 503);
            }

            return $callback();
        }
        if ($account === null || $account === '') {
            if (DB::table('manager_fbo_resource_holds')->exists() || config('services.manager.ozon_fbo_enabled', false)) {
                throw new ManagerDataException('Нельзя проверить продавца OAuth для операции FBO.', 409);
            }

            return $callback();
        }

        return $this->run($account, function () use ($account, $callback, $draft) {
            if ($draft !== null && DB::table('manager_fbo_resource_holds')->where('resource_key', self::key('draft', $account, $draft))->exists()) {
                throw new ManagerDataException('Этот черновик уже имеет исполнение в чате. Повтор запрещён.', 409);
            }

            return $callback();
        });
    }
}
