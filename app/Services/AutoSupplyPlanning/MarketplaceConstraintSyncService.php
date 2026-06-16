<?php

namespace App\Services\AutoSupplyPlanning;

use App\Domains\Marketplace\MarketplaceFactory;
use App\Domains\Ozon\Api\SuppliesApi as OzonSuppliesApi;
use App\Domains\Wildberries\Api\SuppliesApi as WbSuppliesApi;
use App\Models\Integration;
use App\Models\MarketplaceConstraintSnapshot;
use Illuminate\Support\Carbon;
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
    /** Горизонт оценки доступности склада, дней (RESEARCH-1: согласовать с horizon_days плана). */
    private const HORIZON_DAYS = 14;

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
     * Wildberries: склады + коэффициенты приёмки → записи ограничений (§6 ТЗ).
     *
     * @return array{cluster_constraints: array, warehouse_constraints: array, summary: array, sources: array, status: string}
     */
    private function buildWbConstraints(WbSuppliesApi $supplies): array
    {
        $sources = [];
        $today = now()->startOfDay();
        $horizonEnd = $today->copy()->addDays(self::HORIZON_DAYS);

        // Коэффициенты приёмки (датированные) — основной и достаточный источник:
        // несут и доступность, и коэффициенты, и имя склада (warehouse_name).
        // Отдельный getAvailableWarehouses не используем (имена есть здесь).
        $slots = [];
        try {
            $slots = $supplies->getAvailableAcceptanceSlots();
            $sources['acceptance_coefficients'] = ['ok' => true, 'count' => count($slots)];
        } catch (Throwable $e) {
            $sources['acceptance_coefficients'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        // Агрегируем слоты по складу в пределах горизонта.
        $byWarehouse = [];
        foreach ($slots as $slot) {
            $wid = isset($slot['warehouse_id']) ? (string) $slot['warehouse_id'] : '';
            $date = $slot['date'] ?? null;
            if ($wid === '' || ! $date) {
                continue;
            }
            $parsed = $this->parseDate((string) $date);
            if ($parsed === null || $parsed->lt($today) || $parsed->gt($horizonEnd)) {
                continue;
            }
            $byWarehouse[$wid]['name'] ??= ($slot['warehouse_name'] ?? null);
            $byWarehouse[$wid]['slots'][] = $slot;
        }

        $records = [];
        $available = 0;
        $blocked = 0;
        foreach ($byWarehouse as $wid => $data) {
            $record = $this->normalizeWbWarehouse($wid, $data['name'] ?? null, $data['slots'] ?? []);
            $records[] = $record;
            $record['is_available'] ? $available++ : $blocked++;
        }

        // getAvailableAcceptanceSlots глотает сбой API в пустой массив, поэтому
        // признак ошибки — отсутствие данных приёмки (нечего планировать).
        $gotData = ! empty($slots);
        $status = $gotData ? 'ok' : 'error';

        $summary = [
            'warehouses_total' => count($records),
            'warehouses_available' => $available,
            'warehouses_blocked' => $blocked,
            'horizon_days' => self::HORIZON_DAYS,
            'parser_version' => self::SYNC_VERSION,
        ];
        if (! $gotData) {
            $summary['reason'] = 'API приёмки WB не вернул данных (возможна ошибка ключа/доступа)';
        }

        return [
            'cluster_constraints' => [],
            'warehouse_constraints' => $records,
            'summary' => $summary,
            'sources' => $sources,
            'status' => $status,
        ];
    }

    /**
     * Свернуть датированные слоты одного склада в одну запись ограничения (§6).
     *
     * @param array<int,array<string,mixed>> $slots
     * @return array<string,mixed>
     */
    private function normalizeWbWarehouse(string $warehouseId, ?string $name, array $slots): array
    {
        $hasAvailable = false;
        $minStorage = null;
        $minDelivery = null;

        foreach ($slots as $slot) {
            if (! empty($slot['is_available'])) {
                $hasAvailable = true;
            }
            $minStorage = $this->minNullable($minStorage, $this->numOrNull($slot['storage_coefficient'] ?? null));
            $minDelivery = $this->minNullable($minDelivery, $this->numOrNull($slot['delivery_coefficient'] ?? null));
        }

        [$acceptanceCoefficient, $reason] = $this->normalizeWbAcceptance($hasAvailable);

        return [
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $name,
            'sku' => null,
            'max_qty' => null,   // WB API не отдаёт жёсткий лимит штук
            'need_qty' => null,  // потребность — вход продавца, не из API
            'is_available' => $hasAvailable,
            'acceptance_coefficient' => $acceptanceCoefficient,
            'delivery_coefficient' => $minDelivery,
            'storage_coefficient' => $minStorage,
            'logistics_coefficient' => null,
            'reason' => $reason,
            'source_type' => 'marketplace_constraint',
        ];
    }

    /**
     * Маппинг доступности WB → acceptance_coefficient формата плана.
     *
     * ВАЖНО (RESEARCH-1): должно быть согласовано с costScore в TerritorialPlanningService
     * (costScore = 100 / max(1, max(storage, acceptance)), где 1.0 = нейтрально).
     * WB: coefficient 0 = бесплатная приёмка, 1 = базовая, >1 = платный множитель,
     * нет слота = недоступно. Текущая реализация: есть бесплатное окно в горизонте → 1.0
     * (нейтрально); иначе is_available=false. Платные коэффициенты (>1) пока не повышают
     * стоимость направления — отдельная задача калибровки (см. ТЗ §6).
     *
     * @return array{0: float|null, 1: string}
     */
    private function normalizeWbAcceptance(bool $hasAvailable): array
    {
        if ($hasAvailable) {
            return [1.0, 'Есть бесплатные окна приёмки в горизонте'];
        }

        return [null, 'Нет бесплатных окон приёмки в ближайшие ' . self::HORIZON_DAYS . ' дн.'];
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

    private function parseDate(string $date): ?Carbon
    {
        try {
            return Carbon::parse($date)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function numOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function minNullable(?float $current, ?float $candidate): ?float
    {
        if ($candidate === null) {
            return $current;
        }
        if ($current === null) {
            return $candidate;
        }

        return min($current, $candidate);
    }
}
