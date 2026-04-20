<?php

namespace App\Domains\Locality\Presentation\DTO;

use App\Models\LocalityRecommendation;

final readonly class RecommendationDto
{
    public function __construct(
        public int $id,
        public string $sku,
        public ?string $productName,
        public ?string $targetClusterId,
        public string $targetClusterName,
        public int $recommendedQtyUnits,
        public int $currentStockCluster,
        public int $inTransitCluster,
        public float $dailyDemandCluster,
        public ?float $expectedDaysOfCover,
        public float $expectedSavingsRub,
        public float $expectedLocalShareUpliftPp,
        public float $avgMarkupAmountRub,
        public string $confidence,
        public float $rankScore,
        public string $state,
        public ?string $reasoningText,
        public array $warnings,
        public ?int $linkedSupplyOrderId,
        public ?string $linkedDraftId,
        public string $explainUrl,
    ) {
    }

    public static function fromModel(LocalityRecommendation $m, ?string $productName, string $explainUrl): self
    {
        return new self(
            id: (int) $m->id,
            sku: (string) $m->sku,
            productName: $productName,
            targetClusterId: $m->target_cluster_id !== null ? (string) $m->target_cluster_id : null,
            targetClusterName: (string) $m->target_cluster_name,
            recommendedQtyUnits: (int) $m->recommended_qty_units,
            currentStockCluster: (int) $m->current_stock_cluster,
            inTransitCluster: (int) $m->in_transit_cluster,
            dailyDemandCluster: (float) $m->daily_demand_cluster,
            expectedDaysOfCover: $m->expected_days_of_cover !== null ? (float) $m->expected_days_of_cover : null,
            expectedSavingsRub: (float) $m->expected_savings_rub,
            expectedLocalShareUpliftPp: (float) $m->expected_local_share_uplift_pp,
            avgMarkupAmountRub: (float) $m->avg_markup_amount_rub,
            confidence: (string) $m->confidence,
            rankScore: (float) $m->rank_score,
            state: (string) $m->state,
            reasoningText: $m->reasoning_text,
            warnings: is_array($m->warnings) ? $m->warnings : [],
            linkedSupplyOrderId: $m->linked_supply_order_id !== null ? (int) $m->linked_supply_order_id : null,
            linkedDraftId: $m->linked_draft_id,
            explainUrl: $explainUrl,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'target_cluster_id' => $this->targetClusterId,
            'target_cluster_name' => $this->targetClusterName,
            'recommended_qty_units' => $this->recommendedQtyUnits,
            'current_stock_cluster' => $this->currentStockCluster,
            'in_transit_cluster' => $this->inTransitCluster,
            'daily_demand_cluster' => round($this->dailyDemandCluster, 3),
            'expected_days_of_cover' => $this->expectedDaysOfCover !== null ? round($this->expectedDaysOfCover, 2) : null,
            'expected_savings_rub' => round($this->expectedSavingsRub, 2),
            'expected_local_share_uplift_pp' => round($this->expectedLocalShareUpliftPp, 2),
            'avg_markup_amount_rub' => round($this->avgMarkupAmountRub, 2),
            'confidence' => $this->confidence,
            'rank_score' => round($this->rankScore, 2),
            'state' => $this->state,
            'reasoning_text' => $this->reasoningText,
            'warnings' => $this->warnings,
            'linked_supply_order_id' => $this->linkedSupplyOrderId,
            'linked_draft_id' => $this->linkedDraftId,
            'explain_url' => $this->explainUrl,
        ];
    }
}
