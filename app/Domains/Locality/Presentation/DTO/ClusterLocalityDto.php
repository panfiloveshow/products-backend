<?php

namespace App\Domains\Locality\Presentation\DTO;

final readonly class ClusterLocalityDto
{
    /**
     * @param list<array{sku:string,overpayment:float,orders_count:int}> $topSkusByLoss
     * @param array<string,int> $shippingClusterBreakdown
     */
    public function __construct(
        public ?string $destinationClusterId,
        public string $destinationClusterName,
        public int $ordersCount,
        public int $localOrdersCount,
        public ?float $localSharePercent,
        public float $totalRevenue,
        public float $totalOverpayment,
        public float $lostMarginAmount,
        public int $distinctSkusAffected,
        public array $topSkusByLoss,
        public array $shippingClusterBreakdown,
    ) {
    }

    public function toArray(): array
    {
        return [
            'destination_cluster_id' => $this->destinationClusterId,
            'destination_cluster_name' => $this->destinationClusterName,
            'orders_count' => $this->ordersCount,
            'local_orders_count' => $this->localOrdersCount,
            'local_share_percent' => $this->localSharePercent !== null ? round($this->localSharePercent, 2) : null,
            'total_revenue' => round($this->totalRevenue, 2),
            'total_overpayment' => round($this->totalOverpayment, 2),
            'lost_margin_amount' => round($this->lostMarginAmount, 2),
            'distinct_skus_affected' => $this->distinctSkusAffected,
            'top_skus_by_loss' => $this->topSkusByLoss,
            'shipping_cluster_breakdown' => $this->shippingClusterBreakdown,
        ];
    }
}
