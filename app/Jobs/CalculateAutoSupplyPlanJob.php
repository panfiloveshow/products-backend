<?php

namespace App\Jobs;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalculateAutoSupplyPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    private const EPS = 0.1;
    private const MIN_SALES_DAYS = 14;
    private const LEAD_TIME_DEFAULT = 7;

    public function __construct(
        private string $planId
    ) {}

    public function handle(): void
    {
        $plan = AutoSupplyPlan::find($this->planId);

        if (!$plan) {
            Log::error('CalculateAutoSupplyPlanJob: plan not found', ['id' => $this->planId]);
            return;
        }

        $plan->markCalculating();

        try {
            $this->calculate($plan);
        } catch (\Throwable $e) {
            Log::error('CalculateAutoSupplyPlanJob failed', [
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $plan->markError($e->getMessage());
        }
    }

    private function calculate(AutoSupplyPlan $plan): void
    {
        $ewmaAlpha = 0.35;
        $targetCoverDays = $plan->target_cover_days ?: 21;
        $minCoverDays = $plan->min_cover_days ?: 7;
        $maxCoverDays = $plan->max_cover_days ?: 42;
        $safetyStockDays = $plan->safety_stock_days ?: 5;
        $turnoverLimitDays = $plan->turnover_limit_days;
        $horizonDays = $plan->horizon_days ?: 28;

        $integrationId = $plan->integration_id;
        $marketplace = $plan->marketplace;

        // Получаем все записи остатков для интеграции
        $warehouses = InventoryWarehouse::where('integration_id', $integrationId)
            ->where('marketplace', $marketplace)
            ->get();

        if ($warehouses->isEmpty()) {
            $plan->markReady(0, 0, 0, $this->emptyQualityJson());
            return;
        }

        // Загружаем продукты для метаданных (barcode, name, pack_multiple)
        $skus = $warehouses->pluck('sku')->unique()->toArray();
        $products = Product::where('integration_id', $integrationId)
            ->where('marketplace', $marketplace)
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy('sku');

        $totalLines = 0;
        $totalQty = 0;
        $lines = [];

        // Data quality counters
        $qStocksCoverage = 0;
        $qSalesHistory = 0;
        $qInTransit = 0;
        $qDestination = 0;
        $qBarcode = 0;
        $totalSkus = $warehouses->count();

        foreach ($warehouses as $wh) {
            $product = $products->get($wh->sku);

            // --- 3.1 Forecast (EWMA alpha=0.35 по sales_daily за 28 дней) ---
            $sales7 = $wh->sales_7_days ?? 0;
            $sales14 = $wh->sales_14_days ?? 0;
            $sales30 = $wh->sales_30_days ?? 0;
            $shortAvg = $sales7 > 0 ? $sales7 / 7 : 0;
            $longAvg = $sales30 > 0 ? $sales30 / 30 : 0;

            $needsManualReview = false;
            if ($sales30 <= 0 && $sales14 <= 0) {
                // Менее 14 дней продаж → demand=0, needs_manual_review
                $dailyDemand = 0;
                $needsManualReview = true;
            } elseif ($shortAvg > 0 && $longAvg > 0) {
                $dailyDemand = $ewmaAlpha * $shortAvg + (1 - $ewmaAlpha) * $longAvg;
            } elseif ($shortAvg > 0) {
                $dailyDemand = $shortAvg;
            } elseif ($longAvg > 0) {
                $dailyDemand = $longAvg;
            } else {
                $dailyDemand = 0;
            }

            $currentStock = $wh->quantity ?? 0;
            $inTransit = $wh->in_transit ?? 0;
            $avgDailySales = $wh->average_daily_sales ?? 0;

            // --- 3.2 Базовые формулы ---
            $coverBefore = ($currentStock + $inTransit) / max($dailyDemand, self::EPS);
            $safetyStock = $dailyDemand * $safetyStockDays;
            $targetStock = $dailyDemand * $targetCoverDays;
            $neededBeforeCaps = max(0, $targetStock - ($currentStock + $inTransit));

            // --- 3.3 Max cover cap ---
            $capStock = $dailyDemand * $maxCoverDays;
            $capNeeded = max(0, $capStock - ($currentStock + $inTransit));
            $needed = min($neededBeforeCaps, $capNeeded);

            // --- 3.4 Turnover limit ---
            $capsApplied = [];
            if ($needed !== $neededBeforeCaps) {
                $capsApplied[] = 'max_cover_days';
            }
            if ($turnoverLimitDays !== null && $dailyDemand > self::EPS) {
                $turnoverAfter = ($currentStock + $inTransit + $needed) / max($dailyDemand, self::EPS);
                if ($turnoverAfter > $turnoverLimitDays) {
                    $maxByTurnover = max(0, $dailyDemand * $turnoverLimitDays - ($currentStock + $inTransit));
                    $needed = min($needed, $maxByTurnover);
                    $capsApplied[] = 'turnover_limit';
                }
            }

            // --- 3.5 Destination ---
            $destinationId = $wh->warehouse_id ?? null;
            $destinationType = $destinationId ? 'warehouse' : 'all';

            // --- 3.6 Округление qty ---
            $packMultiple = 1; // MVP: pack_multiple из sku_meta если доступен
            if ($product && isset($product->ozon_data['pack_multiple'])) {
                $packMultiple = max(1, (int) $product->ozon_data['pack_multiple']);
            }
            $qtyRounded = (int) (ceil($needed / max($packMultiple, 1)) * $packMultiple);
            $qtyRounded = max(0, $qtyRounded);

            // --- 3.7 Симуляция и риск ---
            $simulation = $this->buildSimulation(
                $currentStock,
                $inTransit,
                $dailyDemand,
                $qtyRounded,
                $horizonDays
            );

            $oosDate = $this->findOosDate($simulation);
            $coverAfter = ($currentStock + $inTransit + $qtyRounded) / max($dailyDemand, self::EPS);
            $surplusDays = $coverAfter > $targetCoverDays ? (int) ($coverAfter - $targetCoverDays) : null;

            // Risk level по спеке
            $today = Carbon::today();
            if ($oosDate !== null && Carbon::parse($oosDate)->lte($today->copy()->addDays(7))) {
                $riskLevel = 'high';
            } elseif ($coverBefore < $minCoverDays) {
                $riskLevel = 'med';
            } else {
                $riskLevel = 'low';
            }

            // --- 3.8 Explain ---
            $explainJson = [
                'inputs' => [
                    'stock_now' => $currentStock,
                    'in_transit' => $inTransit,
                    'daily_demand' => round($dailyDemand, 4),
                    'ewma_alpha' => $ewmaAlpha,
                    'sales_7d' => $sales7,
                    'sales_14d' => $sales14,
                    'sales_30d' => $sales30,
                    'short_avg' => round($shortAvg, 4),
                    'long_avg' => round($longAvg, 4),
                    'target_cover_days' => $targetCoverDays,
                    'min_cover_days' => $minCoverDays,
                    'max_cover_days' => $maxCoverDays,
                    'safety_stock_days' => $safetyStockDays,
                    'turnover_limit_days' => $turnoverLimitDays,
                    'pack_multiple' => $packMultiple,
                ],
                'math' => [
                    'target_stock' => round($targetStock, 2),
                    'safety_stock' => round($safetyStock, 2),
                    'needed_before_caps' => round($neededBeforeCaps, 2),
                    'cap_stock' => round($capStock, 2),
                    'cap_needed' => round($capNeeded, 2),
                    'caps_applied' => $capsApplied,
                    'needed_after_caps' => round($needed, 2),
                    'qty_rounded' => $qtyRounded,
                    'cover_before' => round($coverBefore, 2),
                    'cover_after' => round($coverAfter, 2),
                ],
                'confidence' => [
                    'needs_manual_review' => $needsManualReview,
                    'missing_sources' => $this->detectMissingSources($wh, $product, $marketplace),
                    'fallbacks' => $dailyDemand === 0 ? ['no_sales_data'] : [],
                ],
                'simulation_summary' => [
                    'oos_date' => $oosDate,
                    'min_stock' => $this->findMinStock($simulation),
                ],
            ];

            // Определяем offer_id и barcode
            $offerId = $wh->sku;
            $barcode = $product?->barcode;

            // --- 4. Data quality counters ---
            if ($currentStock > 0 || $inTransit > 0) $qStocksCoverage++;
            if ($sales30 > 0) $qSalesHistory++;
            if ($inTransit > 0) $qInTransit++;
            if ($destinationId) $qDestination++;
            if ($marketplace === 'wildberries' && !empty($barcode)) $qBarcode++;

            // Пропускаем строки с нулевым количеством
            if ($qtyRounded <= 0) {
                continue;
            }

            $lines[] = [
                'auto_supply_plan_id' => $plan->id,
                'tenant_id' => $plan->tenant_id,
                'sku' => $wh->sku,
                'offer_id' => $offerId,
                'product_name' => $product?->name,
                'barcode' => $barcode,
                'warehouse_id' => $wh->warehouse_id,
                'warehouse_name' => $wh->warehouse_name,
                'destination' => $wh->warehouse_name,
                'destination_id' => $destinationId,
                'destination_type' => $destinationType,
                'qty_recommended' => round($needed, 2),
                'qty_rounded' => $qtyRounded,
                'current_stock' => $currentStock,
                'in_transit' => $inTransit,
                'avg_daily_sales' => round($avgDailySales, 4),
                'ewma_daily_sales' => round($dailyDemand, 4),
                'demand_daily' => round($dailyDemand, 4),
                'cover_days_before' => round($coverBefore, 2),
                'cover_days_after' => round($coverAfter, 2),
                'oos_date' => $oosDate,
                'surplus_days' => $surplusDays,
                'explain_json' => json_encode($explainJson),
                'risk_level' => $riskLevel,
                'simulation_json' => json_encode($simulation),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $totalQty += $qtyRounded;
            $totalLines++;
        }

        // Bulk insert lines
        if (!empty($lines)) {
            foreach (array_chunk($lines, 500) as $chunk) {
                AutoSupplyPlanLine::insert($chunk);
            }
        }

        // --- 4. Data quality score (фиксированная шкала) ---
        $qualityJson = $this->calculateDataQuality(
            $totalSkus, $qStocksCoverage, $qSalesHistory,
            $qInTransit, $qDestination, $qBarcode, $marketplace
        );
        $qualityScore = $qualityJson['total'];

        $plan->markReady($qualityScore, $totalLines, $totalQty, $qualityJson);

        Log::info('CalculateAutoSupplyPlanJob completed', [
            'plan_id' => $plan->id,
            'total_lines' => $totalLines,
            'total_qty' => $totalQty,
            'quality_score' => $qualityScore,
        ]);
    }

    /**
     * Симуляция остатков по дням на horizon_days
     * in_transit считаем доступным через lead_time_default=7 (MVP упрощение)
     */
    private function buildSimulation(
        int $currentStock,
        int $inTransit,
        float $dailyDemand,
        int $supplyQty,
        int $horizonDays
    ): array {
        $simulation = [];
        $stock = (float) $currentStock;
        $transitArrived = false;
        $supplyArrived = false;

        for ($day = 1; $day <= $horizonDays; $day++) {
            // in_transit приходит через lead_time_default
            if (!$transitArrived && $day === self::LEAD_TIME_DEFAULT && $inTransit > 0) {
                $stock += $inTransit;
                $transitArrived = true;
            }

            // Рекомендованная поставка приходит через lead_time_default + 3 (время на сборку)
            if (!$supplyArrived && $day === self::LEAD_TIME_DEFAULT + 3 && $supplyQty > 0) {
                $stock += $supplyQty;
                $supplyArrived = true;
            }

            $stock = max(0, $stock - $dailyDemand);

            $simulation[] = [
                'day' => $day,
                'stock' => round($stock, 1),
                'sales_forecast' => round($dailyDemand, 2),
                'transit_arrived' => (!$transitArrived && $day === self::LEAD_TIME_DEFAULT) ? $inTransit : 0,
                'supply_arrived' => (!$supplyArrived && $day === self::LEAD_TIME_DEFAULT + 3) ? $supplyQty : 0,
            ];
        }

        return $simulation;
    }

    private function findOosDate(array $simulation): ?string
    {
        $today = Carbon::today();
        foreach ($simulation as $point) {
            if ($point['stock'] <= 0) {
                return $today->copy()->addDays($point['day'])->toDateString();
            }
        }
        return null;
    }

    private function findMinStock(array $simulation): float
    {
        $min = PHP_FLOAT_MAX;
        foreach ($simulation as $point) {
            if ($point['stock'] < $min) {
                $min = $point['stock'];
            }
        }
        return $min === PHP_FLOAT_MAX ? 0 : $min;
    }

    private function detectMissingSources($wh, $product, string $marketplace): array
    {
        $missing = [];
        if (($wh->sales_30_days ?? 0) <= 0) $missing[] = 'sales_daily';
        if (($wh->in_transit ?? 0) <= 0 && ($wh->quantity ?? 0) <= 0) $missing[] = 'stocks';
        if ($marketplace === 'wildberries' && empty($product?->barcode)) $missing[] = 'wb_barcode_map';
        return $missing;
    }

    /**
     * Data quality score по фиксированной шкале (0..100)
     */
    private function calculateDataQuality(
        int $total, int $stocks, int $sales, int $transit,
        int $destination, int $barcode, string $marketplace
    ): array {
        if ($total === 0) return $this->emptyQualityJson();

        $stocksScore = round(($stocks / $total) * 30, 1);       // 0..30
        $salesScore = round(($sales / $total) * 25, 1);          // 0..25
        $transitScore = round(($transit / $total) * 20, 1);      // 0..20
        $destScore = round(($destination / $total) * 15, 1);     // 0..15
        $barcodeScore = $marketplace === 'wildberries'
            ? round(($barcode / $total) * 10, 1)                 // 0..10
            : 10.0; // Ozon не требует barcode

        $totalScore = round($stocksScore + $salesScore + $transitScore + $destScore + $barcodeScore, 2);

        return [
            'total' => min(100, $totalScore),
            'breakdown' => [
                'stocks_coverage' => $stocksScore,
                'sales_history' => $salesScore,
                'in_transit_availability' => $transitScore,
                'destination_granularity' => $destScore,
                'wb_barcode_availability' => $barcodeScore,
            ],
            'skus_analyzed' => $total,
        ];
    }

    private function emptyQualityJson(): array
    {
        return [
            'total' => 0,
            'breakdown' => [
                'stocks_coverage' => 0,
                'sales_history' => 0,
                'in_transit_availability' => 0,
                'destination_granularity' => 0,
                'wb_barcode_availability' => 0,
            ],
            'skus_analyzed' => 0,
        ];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CalculateAutoSupplyPlanJob failed permanently', [
            'plan_id' => $this->planId,
            'error' => $exception->getMessage(),
        ]);

        $plan = AutoSupplyPlan::find($this->planId);
        $plan?->markError('Job failed: ' . $exception->getMessage());
    }
}
