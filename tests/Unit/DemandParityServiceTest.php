<?php

namespace Tests\Unit;

use App\Domains\Locality\Recommendation\DemandForecaster;
use App\Services\AutoSupplyPlanning\DemandParityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Тест парити-харнесса спроса (этап 3). Canonical-движок замокан (его SQL — pgsql-only),
 * legacy-источник (inventory_warehouses) проверяется на sqlite.
 */
class DemandParityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('inventory_warehouses');
        Schema::create('inventory_warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('sku')->nullable();
            $table->unsignedInteger('sales_28_days')->nullable();
            $table->unsignedInteger('sales_30_days')->nullable();
            $table->decimal('average_daily_sales', 10, 3)->nullable();
        });
    }

    private function stubForecaster(array $canned): DemandForecaster
    {
        return new class($canned) extends DemandForecaster {
            public function __construct(private array $canned) {}

            public function forIntegration(int $integrationId, int $windowDays = 28): array
            {
                return $this->canned;
            }
        };
    }

    public function test_compare_computes_divergence_metrics(): void
    {
        $forecaster = $this->stubForecaster([
            'ART1' => ['Москва' => ['daily_demand' => 2.0], 'СПб' => ['daily_demand' => 1.0]], // = 3.0
            'ART2' => ['Москва' => ['daily_demand' => 5.0]],                                    // = 5.0
            'ART3' => ['Москва' => ['daily_demand' => 1.0]],                                    // only canonical
        ]);

        DB::table('inventory_warehouses')->insert([
            ['integration_id' => 700, 'sku' => 'ART1', 'sales_28_days' => 84],  // 3.0 → APE 0
            ['integration_id' => 700, 'sku' => 'ART2', 'sales_28_days' => 112], // 4.0 → APE 25
            ['integration_id' => 700, 'sku' => 'ART4', 'sales_28_days' => 28],  // 1.0 only legacy → APE 100
        ]);

        $report = (new DemandParityService($forecaster))->compare(700, 28);
        $s = $report['summary'];

        $this->assertSame(4, $s['skus_total']);          // ART1..ART4
        $this->assertSame(2, $s['skus_both']);           // ART1, ART2
        $this->assertSame(1, $s['skus_only_canonical']); // ART3
        $this->assertSame(1, $s['skus_only_legacy']);    // ART4
        $this->assertSame(3, $s['comparable']);          // legacy>0: ART1, ART2, ART4
        $this->assertEquals(25.0, $s['median_ape']);     // median(0, 25, 100)
        $this->assertEquals(33.3, $s['pct_over_25']);    // 1 из 3 (ART4=100)

        $art1 = collect($report['rows'])->firstWhere('sku', 'ART1');
        $this->assertEquals(3.0, $art1['canonical_daily']);
        $this->assertEquals(3.0, $art1['legacy_daily']);
        $this->assertEquals(0.0, $art1['abs_pct_error']);
    }

    public function test_empty_when_no_data(): void
    {
        $report = (new DemandParityService($this->stubForecaster([])))->compare(999, 28);

        $this->assertSame(0, $report['summary']['skus_total']);
        $this->assertNull($report['summary']['median_ape']);
        $this->assertNull($report['summary']['pct_over_25']);
    }
}
