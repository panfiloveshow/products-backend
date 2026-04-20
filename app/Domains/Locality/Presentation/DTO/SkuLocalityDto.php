<?php

namespace App\Domains\Locality\Presentation\DTO;

final readonly class SkuLocalityDto
{
    public function __construct(
        public string $sku,
        public ?string $productName,
        public int $ordersCount,
        public ?float $localSharePercent,
        public float $overpaymentAmount,
        public float $lostMarginAmount,
        public float $avgBaseLogisticsRub,
        public float $avgMarkupPercent,
        public string $confidence,
        public ?string $dominantDestinationCluster,
        public ?string $dominantShippingCluster,
        public ?float $volumeLiters,
        public ?float $volumeWeight,
        public ?float $chargeableVolumeLiters,
        public ?float $lengthMm,
        public ?float $widthMm,
        public ?float $heightMm,
        public ?float $weightG,
    ) {
    }

    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'orders_count' => $this->ordersCount,
            'local_share_percent' => $this->localSharePercent !== null ? round($this->localSharePercent, 2) : null,
            'overpayment_amount' => round($this->overpaymentAmount, 2),
            'lost_margin_amount' => round($this->lostMarginAmount, 2),
            'avg_base_logistics_rub' => round($this->avgBaseLogisticsRub, 2),
            'avg_markup_percent' => round($this->avgMarkupPercent, 2),
            'confidence' => $this->confidence,
            'dominant_destination_cluster' => $this->dominantDestinationCluster,
            'dominant_shipping_cluster' => $this->dominantShippingCluster,
            'volume_liters' => $this->volumeLiters !== null ? round($this->volumeLiters, 4) : null,
            'volume_weight' => $this->volumeWeight !== null ? round($this->volumeWeight, 4) : null,
            'chargeable_volume_liters' => $this->chargeableVolumeLiters !== null ? round($this->chargeableVolumeLiters, 4) : null,
            'length_mm' => $this->lengthMm !== null ? round($this->lengthMm, 2) : null,
            'width_mm' => $this->widthMm !== null ? round($this->widthMm, 2) : null,
            'height_mm' => $this->heightMm !== null ? round($this->heightMm, 2) : null,
            'weight_g' => $this->weightG !== null ? round($this->weightG, 2) : null,
        ];
    }
}
