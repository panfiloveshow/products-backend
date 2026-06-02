<?php

namespace Tests\Unit\AutoSupplyPlanning;

use App\Services\AutoSupplyPlanning\PlanningFactSnapshotService;
use Tests\TestCase;

class PlanningFactSnapshotServiceTest extends TestCase
{
    public function test_planning_sources_explicitly_record_constraint_file_and_marketplace_needs(): void
    {
        $sources = (new PlanningFactSnapshotService())->withConstraintSources([
            'demand' => 'posting_fbo_v3',
            'stock' => 'analytics_stocks',
        ], [
            'source_kind' => 'constraint_file',
            'source_status' => 'applied_as_marketplace_needs',
            'source_file' => 'ozon-limits.csv',
            'parser_version' => 'marketplace-constraints-2',
            'total_file_marketplace_need_qty' => 42,
            'planning_source' => [
                'used_as_constraints' => true,
                'used_as_marketplace_needs' => true,
                'used_as_coefficients' => false,
                'used_for_quantity_caps' => true,
                'has_unmatched_marketplace_needs' => false,
                'requires_review' => false,
            ],
        ]);

        $this->assertSame('posting_fbo_v3', $sources['demand']);
        $this->assertSame('analytics_stocks', $sources['stock']);
        $this->assertSame('constraint_file', $sources['constraints']);
        $this->assertSame('applied_as_marketplace_needs', $sources['constraints_status']);
        $this->assertSame('ozon-limits.csv', $sources['constraint_source_file']);
        $this->assertSame('marketplace-constraints-2', $sources['constraint_parser_version']);
        $this->assertSame('constraint_file', $sources['marketplace_needs']);
        $this->assertSame('applied_as_marketplace_needs', $sources['marketplace_needs_status']);
        $this->assertSame(42, $sources['marketplace_need_qty']);
        $this->assertTrue($sources['constraints_used_as_constraints']);
        $this->assertTrue($sources['constraints_used_as_marketplace_needs']);
        $this->assertTrue($sources['constraints_used_for_quantity_caps']);
        $this->assertFalse($sources['constraints_has_unmatched_marketplace_needs']);
    }
}
