<?php

namespace App\Domains\Locality\Presentation\DTO;

final readonly class SkuExplanationDto
{
    /**
     * @param list<array<string,mixed>> $perClusterBreakdown
     * @param list<array<string,mixed>> $stockProfile
     * @param list<array<string,mixed>> $demandProfile
     * @param list<array<string,mixed>> $shippingRoutes
     * @param array<string,mixed> $attribution
     * @param array<string,mixed>|null $counterfactual
     * @param list<array<string,mixed>> $timeline
     * @param list<string> $warnings
     * @param array<string,mixed>|null $activeFixation
     * @param list<array<string,mixed>> $relatedRecommendations
     * @param array<string,mixed>|null $productProfile
     */
    public function __construct(
        public string $sku,
        public int $integrationId,
        public int $periodDays,
        public string $periodFrom,
        public string $periodTo,
        public string $calculatedAt,
        public string $dataConfidence,
        public array $warnings,
        public array $summary,
        public array $perClusterBreakdown,
        public array $stockProfile,
        public array $demandProfile,
        public array $shippingRoutes,
        public array $attribution,
        public ?array $counterfactual,
        public array $timeline,
        public ?array $activeFixation,
        public array $relatedRecommendations,
        public ?array $productProfile,
    ) {
    }

    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'integration_id' => $this->integrationId,
            'period' => [
                'from' => $this->periodFrom,
                'to' => $this->periodTo,
                'days' => $this->periodDays,
            ],
            'calculated_at' => $this->calculatedAt,
            'data_confidence' => $this->dataConfidence,
            'warnings' => $this->warnings,
            'summary' => $this->summary,
            'per_cluster_breakdown' => $this->perClusterBreakdown,
            'stock_profile' => $this->stockProfile,
            'demand_profile' => $this->demandProfile,
            'shipping_routes' => $this->shippingRoutes,
            'attribution' => $this->attribution,
            'counterfactual' => $this->counterfactual,
            'timeline' => $this->timeline,
            'active_fixation' => $this->activeFixation,
            'related_recommendations' => $this->relatedRecommendations,
            'product_profile' => $this->productProfile,
        ];
    }
}
