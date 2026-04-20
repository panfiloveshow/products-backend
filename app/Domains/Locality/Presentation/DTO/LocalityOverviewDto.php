<?php

namespace App\Domains\Locality\Presentation\DTO;

final readonly class LocalityOverviewDto
{
    /**
     * @param list<array<string,mixed>> $dominantDestinationClusters
     * @param list<array<string,mixed>> $topOffenderSkus
     * @param list<array<string,mixed>> $topOffenderClusters
     */
    public function __construct(
        public int $integrationId,
        public string $asOf,
        public int $periodDays,
        public int $ordersCount,
        public float $localSharePercent,
        public float $overpaymentTotal,
        public float $lostMarginTotal,
        public float $revenueTotal,
        public float $overpaymentToRevenuePercent,
        public array $dominantDestinationClusters,
        public string $calculationConfidence,
        public float $factualOrdersPercent,
        public array $topOffenderSkus,
        public array $topOffenderClusters,
    ) {
    }

    public function toArray(): array
    {
        return [
            'integration_id' => $this->integrationId,
            'as_of' => $this->asOf,
            'period_days' => $this->periodDays,
            'orders_count' => $this->ordersCount,
            'local_share_percent' => round($this->localSharePercent, 2),
            'overpayment_total' => round($this->overpaymentTotal, 2),
            'lost_margin_total' => round($this->lostMarginTotal, 2),
            'revenue_total' => round($this->revenueTotal, 2),
            'overpayment_to_revenue_percent' => round($this->overpaymentToRevenuePercent, 2),
            'dominant_destination_clusters' => $this->dominantDestinationClusters,
            'calculation_confidence' => $this->calculationConfidence,
            'factual_orders_percent' => round($this->factualOrdersPercent, 2),
            'top_offender_skus' => $this->topOffenderSkus,
            'top_offender_clusters' => $this->topOffenderClusters,
        ];
    }
}
