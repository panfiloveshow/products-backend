<?php

namespace App\Domains\Locality\Recommendation;

/**
 * Confidence scoring для рекомендации.
 *
 * +30 factual ≥70%
 * +20 orders_28d ≥ 30
 * +20 cluster известен
 * +15 cost_price задан
 * +15 tariff_source=official
 *
 * ≥70 = high, ≥40 = medium, else low.
 */
class RecommendationScorer
{
    public function score(array $signals): array
    {
        $score = 0;
        if (($signals['factual_ratio'] ?? 0.0) >= 0.7) {
            $score += 30;
        }
        if (($signals['orders_28d'] ?? 0) >= 30) {
            $score += 20;
        }
        if (($signals['cluster_known'] ?? false) === true) {
            $score += 20;
        }
        if (($signals['cost_price_known'] ?? false) === true) {
            $score += 15;
        }
        if (($signals['tariff_official'] ?? false) === true) {
            $score += 15;
        }

        $confidence = match (true) {
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            default => 'low',
        };

        return ['score' => $score, 'confidence' => $confidence];
    }
}
