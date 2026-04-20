<?php

namespace Tests\Unit\Locality;

use App\Domains\Locality\Recommendation\RecommendationScorer;
use PHPUnit\Framework\TestCase;

class RecommendationScorerTest extends TestCase
{
    public function test_full_signals_give_high_confidence(): void
    {
        $scorer = new RecommendationScorer();
        $result = $scorer->score([
            'factual_ratio' => 0.9,
            'orders_28d' => 50,
            'cluster_known' => true,
            'cost_price_known' => true,
            'tariff_official' => true,
        ]);

        $this->assertSame(100, $result['score']);
        $this->assertSame('high', $result['confidence']);
    }

    public function test_moderate_signals_give_medium(): void
    {
        $scorer = new RecommendationScorer();
        $result = $scorer->score([
            'factual_ratio' => 0.3,
            'orders_28d' => 35,
            'cluster_known' => true,
            'cost_price_known' => false,
            'tariff_official' => false,
        ]);

        // +20 (orders_28d ≥ 30) + 20 (cluster_known) = 40 → medium
        $this->assertSame(40, $result['score']);
        $this->assertSame('medium', $result['confidence']);
    }

    public function test_no_signals_give_low(): void
    {
        $result = (new RecommendationScorer())->score([]);
        $this->assertSame(0, $result['score']);
        $this->assertSame('low', $result['confidence']);
    }
}
