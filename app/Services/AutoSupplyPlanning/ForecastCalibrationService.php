<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\PlanLineEvaluation;

/**
 * Калибровка прогноза спроса на основе план-факта (этап 4).
 *
 * Из накопленных оценок (plan_line_evaluations) считает систематическое смещение
 * (median bias_pct) по (sku, cluster) и возвращает корректор-множитель к demand.
 * Это НЕ ML — честный статистический корректор смещения, с guardrails:
 *  - применяется только при достаточной выборке (min_sample);
 *  - ограничен диапазоном ±max_adjust;
 *  - демпфирован (damping);
 *  - управляется флагом config('autoplanning.calibration.enabled') — по умолчанию off.
 *
 * Корректор виден в explain (caller добавляет в explain_json), не «чёрный ящик».
 */
class ForecastCalibrationService
{
    public function enabled(): bool
    {
        return (bool) config('autoplanning.calibration.enabled', false);
    }

    /**
     * Корректор спроса для (integration, sku, cluster).
     * multiplier=1.0 и applied=false, если калибровка выключена или выборки мало.
     *
     * @return array{multiplier: float, applied: bool, sample: int, median_bias_pct: float|null, reason: string}
     */
    public function correctorFor(int $integrationId, string $sku, ?string $cluster = null): array
    {
        $neutral = static fn (string $reason, int $sample = 0, ?float $bias = null): array => [
            'multiplier' => 1.0,
            'applied' => false,
            'sample' => $sample,
            'median_bias_pct' => $bias,
            'reason' => $reason,
        ];

        if (! $this->enabled()) {
            return $neutral('Калибровка выключена');
        }

        $minSample = max(1, (int) config('autoplanning.calibration.min_sample', 3));
        $window = max($minSample, (int) config('autoplanning.calibration.window', 5));

        $query = PlanLineEvaluation::query()
            ->where('integration_id', $integrationId)
            ->where('sku', $sku)
            ->where('status', PlanLineEvaluation::STATUS_OK)
            ->whereNotNull('bias_pct');

        if ($cluster !== null && $cluster !== '') {
            $query->where('cluster_id', $cluster);
        }

        $biases = $query->orderByDesc('evaluated_at')
            ->limit($window)
            ->pluck('bias_pct')
            ->map(static fn ($b) => (float) $b)
            ->all();

        if (count($biases) < $minSample) {
            return $neutral('Недостаточно оценок для калибровки', count($biases));
        }

        $medianBias = $this->median($biases);

        $maxAdjust = (float) config('autoplanning.calibration.max_adjust', 0.25);
        $damping = (float) config('autoplanning.calibration.damping', 0.5);

        // Положительный bias = систематический перепрогноз → снижаем спрос.
        $raw = 1.0 - ($medianBias / 100.0) * $damping;
        $multiplier = round($this->clamp($raw, 1.0 - $maxAdjust, 1.0 + $maxAdjust), 4);

        return [
            'multiplier' => $multiplier,
            'applied' => true,
            'sample' => count($biases),
            'median_bias_pct' => round($medianBias, 2),
            'reason' => sprintf(
                'Корректор по план-факту: median bias %+.1f%% (n=%d) → ×%.3f',
                $medianBias,
                count($biases),
                $multiplier
            ),
        ];
    }

    /**
     * @param array<int,float> $values
     */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2
            : $values[$mid];
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
