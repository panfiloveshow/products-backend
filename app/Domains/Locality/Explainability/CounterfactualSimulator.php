<?php

namespace App\Domains\Locality\Explainability;

use App\Domains\Locality\Ingestion\PostingEnrichmentReader;
use Carbon\Carbon;

/**
 * FIFO-симуляция: "если бы в кластере X было N единиц — сколько заказов стали бы локальными?"
 */
class CounterfactualSimulator
{
    /**
     * @return array{
     *     scenario: string,
     *     hypothetical_qty: int,
     *     target_cluster_id: string,
     *     consumed_orders: int,
     *     saved_rub: float,
     *     locality_uplift_pp: float,
     *     remaining_qty_at_period_end: int,
     *     first_missed_date: ?string,
     *     last_missed_date: ?string
     * }
     */
    public function simulate(
        int $integrationId,
        string $sku,
        Carbon $from,
        Carbon $to,
        string $targetClusterId,
        int $qty,
        PostingEnrichmentReader $reader,
    ): array {
        $items = $reader
            ->queryForPeriod($integrationId, $from, $to, $sku)
            ->where('destination_cluster_id', $targetClusterId)
            ->where('markup_applied', true)
            ->orderBy('order_date')
            ->get();

        $totalAllOrders = $reader->queryForPeriod($integrationId, $from, $to, $sku)->count();

        $remaining = $qty;
        $consumed = 0;
        $saved = 0.0;
        $firstMissed = null;
        $lastMissed = null;

        foreach ($items as $item) {
            if ($remaining <= 0) {
                $day = $item->order_date !== null ? Carbon::parse($item->order_date)->toDateString() : null;
                if ($firstMissed === null && $day !== null) {
                    $firstMissed = $day;
                }
                if ($day !== null) {
                    $lastMissed = $day;
                }
                continue;
            }

            $remaining--;
            $consumed++;
            $saved += (float) $item->non_local_markup_amount;
        }

        $uplift = $totalAllOrders > 0 ? round(($consumed / $totalAllOrders) * 100, 2) : 0.0;

        $targetClusterName = $items->pluck('destination_cluster_name')->filter()->first() ?? 'cluster';

        return [
            'scenario' => sprintf('%d units in %s during period', $qty, $targetClusterName),
            'hypothetical_qty' => $qty,
            'target_cluster_id' => $targetClusterId,
            'consumed_orders' => $consumed,
            'saved_rub' => round($saved, 2),
            'locality_uplift_pp' => $uplift,
            'remaining_qty_at_period_end' => $remaining,
            'first_missed_date' => $firstMissed,
            'last_missed_date' => $lastMissed,
        ];
    }
}
