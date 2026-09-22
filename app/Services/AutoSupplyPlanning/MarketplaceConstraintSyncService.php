<?php

namespace App\Services\AutoSupplyPlanning;

use App\Domains\Marketplace\MarketplaceFactory;
use App\Domains\Ozon\Api\SuppliesApi as OzonSuppliesApi;
use App\Domains\Wildberries\Api\SuppliesApi as WbSuppliesApi;
use App\Models\Integration;
use App\Models\MarketplaceConstraintSnapshot;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Авто-синхронизация ограничений маркетплейса (доступность складов, коэффициенты
 * приёмки/доставки/хранения) из API в MarketplaceConstraintSnapshot.
 *
 * Заменяет ручную загрузку файла ограничений: снапшот отдаёт «сырой формат записей»,
 * который потребляет MarketplaceConstraintService::normalizeConstraints (см.
 * docs/TZ_MARKETPLACE_LIMITS_AUTOSYNC.md, §6).
 *
 * Этап WB-1: Wildberries реализован end-to-end. Ozon — этап 3 (OZ-2).
 */
class MarketplaceConstraintSyncService
{
    /** Версия маппера — для отслеживания формата в summary. */
    private const SYNC_VERSION = 'mp-constraint-sync-1';

    /**
     * Маркетплейсы, для которых авто-синк ограничений уже реализован.
     *
     * @return list<string>
     */
    public function supportedMarketplaces(): array
    {
        return ['wildberries', 'ozon'];
    }

    /**
     * Синхронизировать ограничения одной интеграции из API маркетплейса.
     * Идемпотентно: перезаписывает снапшот по (integration_id, marketplace).
     * Не бросает исключений наружу — ошибки фиксируются в снапшоте со status=error.
     */
    public function syncIntegration(Integration $integration): MarketplaceConstraintSnapshot
    {
        $marketplace = (string) $integration->marketplace;
        $credentials = $integration->resolveCredentials();

        $missing = $this->missingCredentials($marketplace, $credentials);
        if ($missing !== null) {
            return $this->writeSnapshot($integration, [
                'cluster_constraints' => [],
                'warehouse_constraints' => [],
                'summary' => ['reason' => $missing],
                'sources' => [],
                'status' => 'error',
            ], $missing);
        }

        try {
            $built = $this->buildConstraints($integration, $credentials);
        } catch (Throwable $e) {
            Log::error('MarketplaceConstraintSync: build failed', [
                'integration_id' => $integration->id,
                'marketplace' => $marketplace,
                'error' => $e->getMessage(),
            ]);

            return $this->writeSnapshot($integration, [
                'cluster_constraints' => [],
                'warehouse_constraints' => [],
                'summary' => [],
                'sources' => [],
                'status' => 'error',
            ], $e->getMessage());
        }

        return $this->writeSnapshot($integration, $built, null);
    }

    /**
     * @param array<string,mixed> $credentials
     * @return array{cluster_constraints: array, warehouse_constraints: array, summary: array, sources: array, status: string}
     */
    private function buildConstraints(Integration $integration, array $credentials): array
    {
        $mp = MarketplaceFactory::create($integration->marketplace, $credentials, $integration);

        return match ($integration->marketplace) {
            'wildberries' => $this->buildWbConstraints($mp->supplies()),
            'ozon' => $this->buildOzonConstraints($mp->supplies()),
            default => $this->unsupportedYet((string) $integration->marketplace),
        };
    }

    /**
     * Ozon: кластеры (getClusters) + загрузка складов (getWarehouseWorkload) →
     * cluster_constraints с доступностью. Ozon планирует по кластерам, а доступность
     * API даёт по складам, поэтому агрегируем: кластер доступен, если хоть один его
     * склад приёмки имеет ёмкость > 0. max_qty/коэффициенты Ozon API не отдаёт (null).
     *
     * @return array{cluster_constraints: array, warehouse_constraints: array, summary: array, sources: array, status: string}
     */
    private function buildOzonConstraints(OzonSuppliesApi $supplies): array
    {
        $sources = [];

        try {
            $clusters = $supplies->getClusters();
            $sources['clusters'] = ['ok' => true, 'count' => count($clusters)];
        } catch (Throwable $e) {
            $clusters = [];
            $sources['clusters'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $workload = $supplies->getWarehouseWorkload();
            $sources['warehouse_workload'] = ['ok' => true, 'count' => count($workload)];
        } catch (Throwable $e) {
            $workload = [];
            $sources['warehouse_workload'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        // Без обоих источников нельзя достоверно определить доступность кластеров —
        // не пишем потенциально ложно-блокирующие ограничения.
        if (! ($sources['clusters']['ok'] ?? false) || ! ($sources['warehouse_workload']['ok'] ?? false)) {
            return [
                'cluster_constraints' => [],
                'warehouse_constraints' => [],
                'summary' => ['reason' => 'Не удалось получить кластеры или загрузку складов Ozon'],
                'sources' => $sources,
                'status' => 'error',
            ];
        }

        $records = [];
        $available = 0;
        $blocked = 0;

        foreach ($clusters as $cluster) {
            $clusterId = (string) ($cluster['id'] ?? '');
            if ($clusterId === '') {
                continue;
            }

            $clusterAvailable = false;
            $maxCapacity = 0;
            $nearestDate = null;

            foreach (($cluster['warehouse_ids'] ?? []) as $warehouseId) {
                $w = $workload[(string) $warehouseId] ?? null;
                if ($w !== null && $w['capacity_per_day'] > 0) {
                    $clusterAvailable = true;
                    $maxCapacity = max($maxCapacity, (int) $w['capacity_per_day']);
                    $date = $w['nearest_date'] ?? null;
                    if ($date !== null && ($nearestDate === null || $date < $nearestDate)) {
                        $nearestDate = $date;
                    }
                }
            }

            $records[] = [
                'cluster_id' => $clusterId,
                'cluster_name' => $cluster['name'] ?? null,
                'sku' => null,
                'max_qty' => null,   // Ozon API не отдаёт жёсткий лимит штук
                'need_qty' => null,
                'is_available' => $clusterAvailable,
                'acceptance_coefficient' => null, // у Ozon нет коэффициента приёмки как у WB
                'delivery_coefficient' => null,
                'storage_coefficient' => null,
                'logistics_coefficient' => null,
                'reason' => $clusterAvailable
                    ? sprintf('Приёмка доступна (ёмкость ~%d тов./день%s)', $maxCapacity, $nearestDate ? ", с {$nearestDate}" : '')
                    : 'Нет доступных складов приёмки в кластере',
                'source_type' => 'marketplace_constraint',
            ];
            $clusterAvailable ? $available++ : $blocked++;
        }

        return [
            'cluster_constraints' => $records,
            'warehouse_constraints' => [],
            'summary' => [
                'clusters_total' => count($records),
                'clusters_available' => $available,
                'clusters_blocked' => $blocked,
                'parser_version' => self::SYNC_VERSION,
            ],
            'sources' => $sources,
            'status' => $records === [] ? 'error' : 'ok',
        ];
    }

    /**
     * Wildberries: коэффициенты складов → записи ограничений (§6 ТЗ).
     *
     * «Тарифы на поставку» (GET /api/tariffs/v1/acceptance/coefficients) WB временно
     * отключил с 15.08.2026 (RN-570), замены пока нет — не вызываем. Окна приёмки
     * проверить нечем, поэтому склады не блокируем; коэффициенты логистики и хранения
     * (множители, 1.0 = 100%) берём из тарифов коробов (/api/v1/tariffs/box).
     * Статус partial: снапшот пригоден для плана, а не error из-за отключения метода.
     *
     * @return array{cluster_constraints: array, warehouse_constraints: array, summary: array, sources: array, status: string}
     */
    private function buildWbConstraints(WbSuppliesApi $supplies): array
    {
        $sources = [
            'acceptance_coefficients' => ['ok' => false, 'disabled' => true, 'error' => WbSuppliesApi::DISABLED_REASON],
        ];

        try {
            $coefficients = $supplies->getAcceptanceCoefficients();
            $sources['box_tariffs'] = ['ok' => $coefficients !== [], 'count' => count($coefficients)];
        } catch (Throwable $e) {
            $coefficients = [];
            $sources['box_tariffs'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        $records = [];
        foreach ($coefficients as $row) {
            $records[] = [
                'warehouse_id' => null, // в тарифах коробов есть только имя склада
                'warehouse_name' => $row['warehouse_name'] ?? null,
                'sku' => null,
                'max_qty' => null,   // WB API не отдаёт жёсткий лимит штук
                'need_qty' => null,  // потребность — вход продавца, не из API
                'is_available' => true,
                'acceptance_coefficient' => null,
                'delivery_coefficient' => $this->numOrNull($row['delivery_coef'] ?? null),
                'storage_coefficient' => $this->numOrNull($row['storage_coef'] ?? null),
                'logistics_coefficient' => null,
                'reason' => 'Окна приёмки не проверены: '.WbSuppliesApi::DISABLED_REASON,
                'source_type' => 'marketplace_constraint',
            ];
        }

        // Пустые тарифы коробов — уже не следствие отключения метода, а реальный
        // сбой (ключ/лимит): тогда error, как и раньше при пустых данных.
        $gotData = $records !== [];

        return [
            'cluster_constraints' => [],
            'warehouse_constraints' => $records,
            'summary' => [
                'warehouses_total' => count($records),
                'warehouses_available' => count($records),
                'warehouses_blocked' => 0,
                'parser_version' => self::SYNC_VERSION,
                'reason' => $gotData
                    ? WbSuppliesApi::DISABLED_REASON.'. Доступность приёмки не проверяется, коэффициенты — из тарифов коробов.'
                    : WbSuppliesApi::DISABLED_REASON.'. Тарифы коробов WB не вернули данных (возможна ошибка ключа/доступа).',
            ],
            'sources' => $sources,
            'status' => $gotData ? 'partial' : 'error',
        ];
    }

    /**
     * @return array{cluster_constraints: array, warehouse_constraints: array, summary: array, sources: array, status: string}
     */
    private function unsupportedYet(string $marketplace): array
    {
        return [
            'cluster_constraints' => [],
            'warehouse_constraints' => [],
            'summary' => ['note' => "Авто-синк ограничений для {$marketplace} ещё не реализован"],
            'sources' => [],
            'status' => 'error',
        ];
    }

    /**
     * @param array<string,mixed> $credentials
     */
    private function missingCredentials(string $marketplace, array $credentials): ?string
    {
        if (empty($credentials['api_key'])) {
            return 'Нет api_key (после resolveCredentials с Sellico-фолбэком)';
        }
        if ($marketplace === 'ozon' && empty($credentials['client_id'])) {
            return 'Нет client_id для Ozon';
        }

        return null;
    }

    /**
     * @param array{cluster_constraints: array, warehouse_constraints: array, summary: array, sources: array, status: string} $built
     */
    private function writeSnapshot(Integration $integration, array $built, ?string $error): MarketplaceConstraintSnapshot
    {
        return MarketplaceConstraintSnapshot::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'marketplace' => $integration->marketplace,
            ],
            [
                'cluster_constraints_json' => ($built['cluster_constraints'] ?? []) ?: null,
                'warehouse_constraints_json' => ($built['warehouse_constraints'] ?? []) ?: null,
                'summary_json' => ($built['summary'] ?? []) ?: null,
                'sources_json' => ($built['sources'] ?? []) ?: null,
                'sync_status' => $built['status'] ?? 'error',
                'sync_error' => $error,
                'synced_at' => now(),
            ]
        );
    }

    private function numOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
