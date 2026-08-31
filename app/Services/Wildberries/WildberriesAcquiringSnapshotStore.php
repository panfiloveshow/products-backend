<?php

namespace App\Services\Wildberries;

use App\Models\Integration;
use App\Models\UnitEconomics;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Сохраняет последний успешный снимок эквайринга WB между storage job и
 * unit-economics sync. Это позволяет обоим этапам использовать один Finance
 * запрос и не выбивать друг друга из жёсткого лимита отчётов.
 */
class WildberriesAcquiringSnapshotStore
{
    private const FRESH_HOURS = 36;

    /**
     * @param  array{by_sku?: array<string,float|int>, avg?: float|int, observed_at?: ?string}  $snapshot
     */
    public function persist(int $integrationId, array $snapshot): int
    {
        $observedAt = $snapshot['observed_at'] ?? null;
        if (! is_string($observedAt) || $observedAt === '') {
            return 0;
        }

        $byRate = [];
        foreach (($snapshot['by_sku'] ?? []) as $sku => $percent) {
            if ($sku === '' || ! is_numeric($percent)) {
                continue;
            }
            $normalized = round(max(0.0, min(10.0, (float) $percent)), 2);
            $byRate[number_format($normalized, 2, '.', '')][] = (string) $sku;
        }

        $updated = 0;
        foreach ($byRate as $rate => $skus) {
            foreach (array_chunk(array_values(array_unique($skus)), 500) as $chunk) {
                $updated += UnitEconomics::query()
                    ->where('integration_id', $integrationId)
                    ->where('marketplace', 'wildberries')
                    ->whereIn('sku', $chunk)
                    ->update([
                        'acquiring_percent' => (float) $rate,
                        'acquiring_observed_at' => $observedAt,
                        'updated_at' => now(),
                    ]);
            }
        }

        $integration = Integration::find($integrationId);
        if ($integration) {
            $settings = is_array($integration->settings) ? $integration->settings : [];
            $settings['wb_acquiring_avg'] = round((float) ($snapshot['avg'] ?? 0), 2);
            $settings['wb_acquiring_observed_at'] = $observedAt;
            $settings['wb_acquiring_source'] = 'finance_sales_report';
            $integration->update(['settings' => $settings]);
        }

        Log::info('WB acquiring snapshot persisted', [
            'integration_id' => $integrationId,
            'updated_rows' => $updated,
            'source_keys' => count($snapshot['by_sku'] ?? []),
            'avg_percent' => round((float) ($snapshot['avg'] ?? 0), 2),
            'observed_at' => $observedAt,
        ]);

        return $updated;
    }

    /**
     * @return array{is_fresh: bool, by_sku: array<string,float>, avg: float, observed_at: ?string}
     */
    public function loadFresh(int $integrationId): array
    {
        $integration = Integration::find($integrationId);
        $settings = is_array($integration?->settings) ? $integration->settings : [];
        $observedAt = $settings['wb_acquiring_observed_at'] ?? null;

        try {
            $fresh = is_string($observedAt)
                && $observedAt !== ''
                && Carbon::parse($observedAt)->gte(now()->subHours(self::FRESH_HOURS));
        } catch (\Throwable) {
            $fresh = false;
        }

        if (! $fresh) {
            return [
                'is_fresh' => false,
                'by_sku' => [],
                'avg' => 0.0,
                'observed_at' => is_string($observedAt) ? $observedAt : null,
            ];
        }

        $bySku = [];
        UnitEconomics::query()
            ->where('integration_id', $integrationId)
            ->where('marketplace', 'wildberries')
            ->where('acquiring_observed_at', '>=', now()->subHours(self::FRESH_HOURS))
            ->whereNotNull('acquiring_percent')
            ->orderByDesc('acquiring_observed_at')
            ->get(['sku', 'acquiring_percent'])
            ->each(function (UnitEconomics $row) use (&$bySku): void {
                $sku = (string) $row->sku;
                if ($sku !== '' && ! array_key_exists($sku, $bySku)) {
                    $bySku[$sku] = (float) $row->acquiring_percent;
                }
            });

        return [
            'is_fresh' => true,
            'by_sku' => $bySku,
            'avg' => round((float) ($settings['wb_acquiring_avg'] ?? 0), 2),
            'observed_at' => $observedAt,
        ];
    }
}
