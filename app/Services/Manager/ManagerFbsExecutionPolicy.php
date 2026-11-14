<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Services\Marketplace\PriceDispatchAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Physical seller scope, immutable prepare version and one SQL serialization with control decisions. */
final class ManagerFbsExecutionPolicy
{
    public const TABLE = 'manager_fbs_execution_controls';

    public function active(): bool
    {
        $enabled = config('services.manager.fbs_execution_controls_enabled', false) === true;
        if (! Schema::hasTable(self::TABLE)) {
            if ($enabled) {
                throw new ManagerDataException('Журнал ограничения FBS не установлен.', 503);
            }

            return false;
        }

        // Turning off the new UI does not remove an already persisted restriction.
        return $enabled || DB::table(self::TABLE)->where('version', '>', 0)->exists();
    }

    public function accountKey(ManagerStore $store): string
    {
        return $this->credentialsAccount($store->marketplace, $store->apiKey, $store->clientId);
    }

    private function credentialsAccount(string $market, #[\SensitiveParameter] string $key, ?string $client): string
    {
        try {
            if ($market === 'wildberries') {
                return PriceDispatchAuthorization::wbAccount($key);
            }
            if ($market === 'ozon' && is_string($client) && preg_match('/^[1-9][0-9]{0,18}$/D', $client)
                && filter_var($client, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false && $key !== '') {
                return 'ozon:'.hash('sha256', 'ozon-seller-fbs:'.$client);
            }
        } catch (\Throwable) {
        }
        throw new ManagerDataException('Не подтверждена физическая привязка магазина для ограничения FBS.', 503);
    }

    public function locked(ManagerStore $store, callable $callback): mixed
    {
        return $this->lockedAccount($store->marketplace, $this->accountKey($store), $callback);
    }

    private function lockedAccount(string $market, string $key, callable $callback): mixed
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new ManagerDataException('Журнал ограничения FBS не установлен.', 503);
        }

        // Never retry this transaction: its dispatch callback may already have reached the marketplace.
        return DB::transaction(function () use ($market, $key, $callback) {
            DB::table(self::TABLE)->insertOrIgnore(['account_key' => $key, 'marketplace' => $market, 'mode' => 'legacy', 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table(self::TABLE)->where('account_key', $key)->lockForUpdate()->first();
            if (! $row || $row->marketplace !== $market || ! in_array($row->mode, ['legacy', 'stopped', 'manual_only'], true)
                || filter_var($row->version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
                || ($row->mode === 'legacy') !== ((int) $row->version === 0)) {
                throw new ManagerDataException('Сохранённое ограничение FBS не подтверждено.', 503);
            }

            return $callback($row);
        }, 1);
    }

    public function snapshot(ManagerStore $store): ?array
    {
        if (! $this->active()) {
            return null;
        }

        return $this->locked($store, fn ($row) => ['account_key' => $row->account_key, 'version' => (string) $row->version]);
    }

    public function decorate(ManagerStore $store, array $plan): array
    {
        $snapshot = $this->snapshot($store);

        return $snapshot === null ? $plan : [...$plan, '_execution_control' => $snapshot];
    }

    public function dispatch(ManagerStore $store, ?array $snapshot, callable $callback): mixed
    {
        return $this->dispatchAccount($store->marketplace, fn () => $this->accountKey($store), $snapshot, $callback);
    }

    public function legacy(string $market, #[\SensitiveParameter] string $key, ?string $client, callable $callback): mixed
    {
        // Legacy transports supply their real credentials; no workspace/user/cabinet is invented.
        return $this->dispatchAccount($market, fn () => $this->credentialsAccount($market, $key, $client), null, $callback);
    }

    private function dispatchAccount(string $market, callable $account, ?array $snapshot, callable $callback): mixed
    {
        if (! config('services.manager.fbs_writes_enabled', true)) {
            throw new ManagerFbsExecutionStopped('Изменения FBS остановлены администратором. Запрос на площадку не отправлен.', 503);
        }
        if (! $this->active() && $snapshot === null) {
            return $callback();
        }

        return $this->lockedAccount($market, $account(), function ($row) use ($snapshot, $callback) {
            $same = $snapshot !== null && count($snapshot) === 2
                && is_string($snapshot['account_key'] ?? null) && hash_equals($row->account_key, $snapshot['account_key'])
                && ($snapshot['version'] ?? null) === (string) $row->version;
            if ($row->mode === 'stopped' || $snapshot !== null && ! $same || $row->mode === 'manual_only' && ! $same) {
                throw new ManagerFbsExecutionStopped('Новая отправка FBS остановлена или требует нового личного согласования. Запрос на площадку не отправлен.', 409);
            }

            return $callback();
        });
    }

    public function publicControl(object $row): array
    {
        return ['mode' => $row->mode, 'version' => (string) $row->version,
            'changed_at' => (int) $row->version === 0 ? null : CarbonImmutable::parse($row->updated_at, 'UTC')->utc()->toRfc3339String()];
    }

    /** Existing read/document routes remain available; unknown mutations in these native families are stopped. */
    public static function isMutation(string $market, string $method, string $base, string $endpoint): bool
    {
        if (strtoupper($method) === 'GET') {
            return false;
        }
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $path = strtolower(rawurldecode((string) parse_url($endpoint, PHP_URL_PATH)));
        if ($market === 'wildberries' && $host === 'marketplace-api.wildberries.ru') {
            return (bool) preg_match('~^/api/(?:marketplace/)?v[0-9]+/(?:fbs/)?(?:orders|supplies)(?:/|$)~D', $path)
                && ! in_array($path, ['/api/v3/orders/status', '/api/v3/orders/stickers', '/api/v3/orders/status/history'], true);
        }
        if ($market !== 'ozon' || $host !== 'api-seller.ozon.ru') {
            return false;
        }
        if (! preg_match('~^/v[0-9]+/(?:posting/fbs|posting/carriage|carriage)(?:/|-|$)~D', $path)) {
            return false;
        }

        return ! preg_match('~^/v[0-9]+/(?:posting/fbs/(?:get|list|unfulfilled/list|cancel-reason(?:/list)?|package-label(?:/create|/get)?|act/(?:list|get-pdf|check-status))|posting/carriage-available/list|carriage/(?:get|available/list|delivery/list))/?$~D', $path);
    }
}
