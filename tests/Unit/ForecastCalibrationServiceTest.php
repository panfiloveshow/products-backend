<?php

namespace Tests\Unit;

use App\Models\PlanLineEvaluation;
use App\Services\AutoSupplyPlanning\ForecastCalibrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ForecastCalibrationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('plan_line_evaluations');
        Schema::create('plan_line_evaluations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('sku');
            $table->string('cluster_id')->nullable();
            $table->string('status', 20)->default('ok');
            $table->decimal('bias_pct', 8, 2)->nullable();
            $table->timestamp('evaluated_at')->nullable();
        });

        // По умолчанию включаем калибровку в тестах (в проде флаг off).
        Config::set('autoplanning.calibration.enabled', true);
        Config::set('autoplanning.calibration.min_sample', 3);
        Config::set('autoplanning.calibration.max_adjust', 0.25);
        Config::set('autoplanning.calibration.damping', 0.5);
        Config::set('autoplanning.calibration.window', 5);
    }

    private function seedEvaluations(int $integrationId, string $sku, array $biases, string $status = 'ok'): void
    {
        foreach ($biases as $i => $bias) {
            DB::table('plan_line_evaluations')->insert([
                'integration_id' => $integrationId,
                'sku' => $sku,
                'status' => $status,
                'bias_pct' => $bias,
                'evaluated_at' => now()->subDays(count($biases) - $i),
            ]);
        }
    }

    public function test_disabled_returns_neutral(): void
    {
        Config::set('autoplanning.calibration.enabled', false);
        $this->seedEvaluations(700, 'ART1', [20, 20, 20]);

        $result = (new ForecastCalibrationService())->correctorFor(700, 'ART1');

        $this->assertFalse($result['applied']);
        $this->assertSame(1.0, $result['multiplier']);
    }

    public function test_insufficient_sample_returns_neutral(): void
    {
        $this->seedEvaluations(700, 'ART1', [20, 20]); // < min_sample(3)

        $result = (new ForecastCalibrationService())->correctorFor(700, 'ART1');

        $this->assertFalse($result['applied']);
        $this->assertSame(1.0, $result['multiplier']);
        $this->assertSame(2, $result['sample']);
    }

    public function test_corrects_systematic_over_forecast(): void
    {
        // median bias +20% → multiplier = 1 - 0.20*0.5 = 0.90
        $this->seedEvaluations(700, 'ART1', [18, 20, 22]);

        $result = (new ForecastCalibrationService())->correctorFor(700, 'ART1');

        $this->assertTrue($result['applied']);
        $this->assertEquals(20.0, $result['median_bias_pct']);
        $this->assertEquals(0.90, $result['multiplier']);
    }

    public function test_under_forecast_raises_demand(): void
    {
        // median bias -20% → multiplier = 1 - (-0.20)*0.5 = 1.10
        $this->seedEvaluations(700, 'ART1', [-20, -20, -20]);

        $result = (new ForecastCalibrationService())->correctorFor(700, 'ART1');

        $this->assertEquals(1.10, $result['multiplier']);
    }

    public function test_clamps_to_max_adjust(): void
    {
        // Огромный bias +200% → raw = 1 - 2.0*0.5 = 0 → clamp до 0.75
        $this->seedEvaluations(700, 'ART1', [200, 200, 200]);

        $result = (new ForecastCalibrationService())->correctorFor(700, 'ART1');

        $this->assertEquals(0.75, $result['multiplier']);
    }

    public function test_ignores_insufficient_status_rows(): void
    {
        $this->seedEvaluations(700, 'ART1', [20], 'insufficient_data');
        $this->seedEvaluations(700, 'ART1', [20, 20], 'ok');

        $result = (new ForecastCalibrationService())->correctorFor(700, 'ART1');

        // только 2 ok-строки → недостаточно
        $this->assertFalse($result['applied']);
        $this->assertSame(2, $result['sample']);
    }
}
