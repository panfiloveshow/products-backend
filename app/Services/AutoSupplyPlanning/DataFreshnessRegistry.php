<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\Integration;
use App\Services\Ozon\OzonCredentialHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Единый реестр фактов, от которых зависит автоплан.
 *
 * Возвращает одинаковый контракт для экрана здоровья данных, snapshot расчёта
 * и pre-flight валидации. Все запросы жёстко ограничены integration_id.
 */
class DataFreshnessRegistry
{
    /**
     * @return array<string, mixed>
     */
    public function inspect(Integration $integration): array
    {
        $integrationId = (int) $integration->id;
        $marketplace = (string) $integration->marketplace;
        $productCount = (int) DB::table('products')
            ->where('integration_id', $integrationId)
            ->count();
        $productUpdatedAt = DB::table('products')
            ->where('integration_id', $integrationId)
            ->max('updated_at');

        $inventoryQuery = DB::table('inventory_warehouses')
            ->where('integration_id', $integrationId)
            ->where('marketplace', $marketplace);
        $inventorySkuCount = (int) (clone $inventoryQuery)->distinct()->count('sku');
        $inventoryUpdatedAt = (clone $inventoryQuery)->max('last_updated');
        $inventoryDemandSkuCount = (int) (clone $inventoryQuery)
            ->where(function ($query) {
                $query->where('average_daily_sales', '>', 0)
                    ->orWhere('effective_daily_sales', '>', 0)
                    ->orWhere('sales_30_days', '>', 0);
            })
            ->distinct()
            ->count('sku');

        $postingsQuery = DB::table('postings')
            ->where('integration_id', (string) $integrationId)
            ->where('marketplace', $marketplace);
        $postingCount = (int) (clone $postingsQuery)->count();
        $postingUpdatedAt = (clone $postingsQuery)->max(
            DB::raw('COALESCE(synced_at, updated_at)')
        );

        $economicsQuery = DB::table('unit_economics')
            ->where('integration_id', $integrationId)
            ->where('marketplace', $marketplace);
        $economicsSkuCount = (int) (clone $economicsQuery)->distinct()->count('sku');
        $economicsUpdatedAt = (clone $economicsQuery)->max('updated_at');

        $constraint = DB::table('marketplace_constraint_snapshots')
            ->where('integration_id', $integrationId)
            ->where('marketplace', $marketplace)
            ->orderByDesc('synced_at')
            ->first();

        $supplyUpdatedAt = DB::table('supplies')
            ->where('integration_id', $integrationId)
            ->max('updated_at');
        $activeSupplies = (int) DB::table('supplies')
            ->where('integration_id', $integrationId)
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->count();

        $credentialHealth = $marketplace === 'ozon'
            ? app(OzonCredentialHealthService::class)->assess($integration)
            : [
                'status' => 'valid',
                'health' => 'healthy',
                'usable' => true,
                'severity' => 'none',
                'message' => 'Credentials готовы.',
            ];

        $cards = [
            'credentials' => $this->credentialCard($integration, $credentialHealth),
            'products' => $this->card(
                'products',
                $productUpdatedAt,
                $productCount,
                $productCount > 0 ? 100.0 : 0.0,
                true,
                $integrationId,
                ['items' => $productCount]
            ),
            'inventory' => $this->card(
                'inventory',
                $inventoryUpdatedAt,
                $inventorySkuCount,
                $this->coverage($inventorySkuCount, $productCount),
                true,
                $integrationId,
                ['sku_count' => $inventorySkuCount, 'product_count' => $productCount]
            ),
            'demand' => $this->card(
                'demand',
                $this->latest($postingUpdatedAt, $inventoryUpdatedAt),
                $postingCount + $inventoryDemandSkuCount,
                $this->coverage($inventoryDemandSkuCount, $productCount),
                true,
                $integrationId,
                [
                    'postings' => $postingCount,
                    'inventory_skus_with_demand' => $inventoryDemandSkuCount,
                    'sources' => array_values(array_filter([
                        $postingCount > 0 ? 'postings_fbo' : null,
                        $inventoryDemandSkuCount > 0 ? 'inventory_sales_windows' : null,
                    ])),
                ]
            ),
            'economics' => $this->card(
                'economics',
                $economicsUpdatedAt,
                $economicsSkuCount,
                $this->coverage($economicsSkuCount, $productCount),
                false,
                $integrationId,
                ['sku_count' => $economicsSkuCount, 'product_count' => $productCount]
            ),
            'constraints' => $this->card(
                'constraints',
                $constraint?->synced_at,
                $constraint === null ? 0 : 1,
                $constraint === null ? 0.0 : 100.0,
                false,
                $integrationId,
                [
                    'sync_status' => $constraint?->sync_status,
                    'last_error' => $constraint?->sync_error,
                ]
            ),
            'supplies' => $this->card(
                'supplies',
                $supplyUpdatedAt,
                $activeSupplies,
                100.0,
                false,
                $integrationId,
                ['active_supplies' => $activeSupplies]
            ),
        ];

        $blocking = collect($cards)->filter(
            static fn (array $card): bool => $card['blocking']
        );
        $blockingErrors = $blocking
            ->filter(static fn (array $card): bool => $card['status'] !== 'ready')
            ->map(static fn (array $card, string $source): array => [
                'source' => $source,
                'status' => $card['status'],
                'message' => $card['message'],
            ])
            ->values()
            ->all();
        $warnings = collect($cards)
            ->reject(static fn (array $card): bool => $card['blocking'])
            ->filter(static fn (array $card): bool => $card['status'] !== 'ready')
            ->map(static fn (array $card, string $source): array => [
                'source' => $source,
                'status' => $card['status'],
                'message' => $card['message'],
            ])
            ->values()
            ->all();

        $capturedAt = now()->toIso8601String();
        $constraintSummary = [];
        if ($constraint !== null && ! empty($constraint->summary_json)) {
            $decodedConstraintSummary = is_string($constraint->summary_json)
                ? json_decode($constraint->summary_json, true)
                : (array) $constraint->summary_json;
            $constraintSummary = is_array($decodedConstraintSummary) ? $decodedConstraintSummary : [];
        }
        $hashPayload = [
            'integration_id' => $integrationId,
            'marketplace' => $marketplace,
            'sources' => collect($cards)->map(static fn (array $card): array => [
                'fetched_at' => $card['fetched_at'],
                'items' => $card['items'],
                'coverage_percent' => $card['coverage_percent'],
                'status' => $card['status'],
            ])->all(),
        ];

        return [
            'integration_id' => $integrationId,
            'marketplace' => $marketplace,
            'captured_at' => $capturedAt,
            'status' => $blockingErrors === [] ? 'ready' : 'blocked',
            'can_calculate' => $blockingErrors === [],
            'can_approve' => $blockingErrors === [],
            'blocking_errors' => $blockingErrors,
            'warnings' => $warnings,
            'sources' => $cards,
            'sync_progress' => $this->syncProgress($integration, $cards),
            // Совместимый компактный контракт для существующего readiness UI.
            'marketplace_constraints' => [
                'available' => $constraint !== null,
                'source' => $constraint !== null ? 'api_sync' : null,
                'sync_status' => $constraint?->sync_status,
                'synced_at' => $constraint?->synced_at,
                'is_fresh' => ($cards['constraints']['status'] ?? null) === 'ready',
                'last_error' => $constraint?->sync_error,
                'warehouses_total' => (int) ($constraintSummary['warehouses_total'] ?? 0),
                'warehouses_available' => (int) ($constraintSummary['warehouses_available'] ?? 0),
                'warehouses_blocked' => (int) ($constraintSummary['warehouses_blocked'] ?? 0),
                'summary' => $constraintSummary,
            ],
            'hash' => hash('sha256', json_encode(
                $hashPayload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )),
        ];
    }

    /**
     * Прогресс последнего полного refresh без отдельной polling-таблицы:
     * stage считается завершённым, когда его fetched_at не старше requested_at.
     *
     * @param array<string, array<string, mixed>> $cards
     * @return array<string, mixed>|null
     */
    private function syncProgress(Integration $integration, array $cards): ?array
    {
        $settings = is_array($integration->settings) ? $integration->settings : [];
        $refresh = is_array($settings['autoplanning_fact_refresh'] ?? null)
            ? $settings['autoplanning_fact_refresh']
            : null;
        if ($refresh === null || empty($refresh['requested_at'])) {
            return null;
        }

        $requestedAt = CarbonImmutable::parse($refresh['requested_at']);
        $stageSources = [
            'products' => ['products'],
            'inventory_and_sales' => ['inventory', 'demand'],
            'unit_economics' => ['economics'],
            'postings' => ['demand'],
            'supplies' => ['supplies'],
            'constraints' => ['constraints'],
            'credential_health' => ['credentials'],
        ];
        $stages = [];
        foreach ($stageSources as $stage => $sources) {
            $completed = true;
            $latest = null;
            foreach ($sources as $source) {
                $fetchedAt = $cards[$source]['fetched_at'] ?? null;
                $timestamp = $fetchedAt ? CarbonImmutable::parse($fetchedAt) : null;
                $completed = $completed && $timestamp !== null && $timestamp->greaterThanOrEqualTo($requestedAt);
                if ($timestamp !== null && ($latest === null || $timestamp->greaterThan($latest))) {
                    $latest = $timestamp;
                }
            }
            if (in_array($stage, ['postings', 'supplies', 'constraints', 'credential_health'], true)) {
                $completed = ($refresh['operational_status'] ?? null) === 'completed';
            }
            $stages[] = [
                'key' => $stage,
                'status' => $completed ? 'completed' : 'pending',
                'updated_at' => $latest?->toIso8601String(),
            ];
        }

        $completedCount = count(array_filter(
            $stages,
            static fn (array $stage): bool => $stage['status'] === 'completed'
        ));
        $failed = ($refresh['operational_status'] ?? null) === 'failed'
            || $integration->last_sync_status === 'failed';
        $status = $failed
            ? 'failed'
            : ($completedCount === count($stages) ? 'completed' : 'running');

        return [
            'id' => $refresh['id'] ?? null,
            'status' => $status,
            'requested_at' => $requestedAt->toIso8601String(),
            'completed_stages' => $completedCount,
            'total_stages' => count($stages),
            'progress_percent' => (int) round($completedCount / max(1, count($stages)) * 100),
            'stages' => $stages,
            'error' => $refresh['operational_error'] ?? $integration->last_sync_error,
        ];
    }

    /**
     * @param array<string, mixed> $health
     * @return array<string, mixed>
     */
    private function credentialCard(Integration $integration, array $health): array
    {
        $usable = (bool) ($health['usable'] ?? false);
        $healthStatus = (string) ($health['status'] ?? 'unknown');
        $status = match (true) {
            ! $usable => $healthStatus === 'expired' ? 'expired' : 'missing',
            in_array($healthStatus, ['expiring', 'unavailable'], true) => 'warning',
            ($health['health'] ?? null) === 'unknown' => 'warning',
            default => 'ready',
        };

        return [
            'status' => $status,
            'blocking' => ! $usable,
            'fetched_at' => $integration->credential_last_checked_at?->toIso8601String(),
            'age_minutes' => $integration->credential_last_checked_at?->diffInMinutes(now()),
            'sla_hours' => null,
            'items' => $usable ? 1 : 0,
            'coverage_percent' => $usable ? 100.0 : 0.0,
            'last_error' => $integration->last_validation_error,
            'message' => (string) ($health['message'] ?? 'Статус credentials неизвестен.'),
            'meta' => [
                'credential_type' => $health['credential_type'] ?? $integration->credential_type,
                'credential_health' => $health['health'] ?? $integration->credential_health,
                'expires_at' => $health['expires_at'] ?? $integration->credentials_expires_at?->toIso8601String(),
                'days_until_expiry' => $health['days_until_expiry'] ?? null,
                'notification_threshold_days' => $health['notification_threshold_days'] ?? null,
                'severity' => $health['severity'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function card(
        string $source,
        mixed $fetchedAt,
        int $items,
        float $coverage,
        bool $blocking,
        int $integrationId,
        array $meta
    ): array {
        $timestamp = $fetchedAt ? CarbonImmutable::parse($fetchedAt) : null;
        $ageMinutes = $timestamp?->diffInMinutes(now());
        $slaHours = max(1, (int) config("autoplanning.freshness_sla_hours.{$source}", 24));
        $lastError = $this->lastSyncError($integrationId, $source);

        $status = match (true) {
            $items <= 0 || $timestamp === null => 'missing',
            $ageMinutes > ($slaHours * 60) => 'stale',
            default => 'ready',
        };

        $message = match ($status) {
            'missing' => "Источник {$source} не содержит данных.",
            'stale' => "Источник {$source} старше SLA {$slaHours} ч.",
            default => 'Данные готовы.',
        };

        return [
            'status' => $status,
            'blocking' => $blocking,
            'fetched_at' => $timestamp?->toIso8601String(),
            'age_minutes' => $ageMinutes,
            'sla_hours' => $slaHours,
            'items' => $items,
            'coverage_percent' => round(max(0, min(100, $coverage)), 2),
            'last_error' => $lastError,
            'message' => $message,
            'meta' => $meta,
        ];
    }

    private function lastSyncError(int $integrationId, string $source): ?string
    {
        $types = match ($source) {
            'products' => ['products'],
            'inventory' => ['inventory'],
            'demand' => ['sales', 'postings'],
            'economics' => ['commissions', 'unit_economics'],
            default => [],
        };

        if ($types === []) {
            return null;
        }

        $row = DB::table('sync_logs')
            ->where('integration_id', $integrationId)
            ->whereIn('sync_type', $types)
            ->where('status', 'failed')
            ->orderByDesc('created_at')
            ->first();

        return $row?->error_message;
    }

    private function coverage(int $covered, int $total): float
    {
        return $total > 0 ? ($covered / $total) * 100 : 0.0;
    }

    private function latest(mixed ...$timestamps): mixed
    {
        return collect($timestamps)->filter()->sortDesc()->first();
    }
}
