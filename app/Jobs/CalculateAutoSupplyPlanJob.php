<?php

namespace App\Jobs;

use App\Models\AutoSupplyPlan;
use App\Models\AutoSupplyPlanLine;
use App\Models\InventoryWarehouse;
use App\Models\Product;
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
            ]);
            $plan->markError($e->getMessage());
        }
    }

    private function calculate(AutoSupplyPlan $plan): void
    {
        $params = $plan->params ?? [];
        $targetDays = $params['target_days'] ?? 30;
        $safetyDays = $params['safety_days'] ?? 7;
        $leadTimeDays = $params['lead_time_days'] ?? 5;
        $ewmaAlpha = $params['ewma_alpha'] ?? 0.35;

        $integrationId = $plan->integration_id;
        $marketplace = $plan->marketplace;

        // Получаем все записи остатков для интеграции
        $warehouses = InventoryWarehouse::where('integration_id', $integrationId)
            ->where('marketplace', $marketplace)
            ->get();

        if ($warehouses->isEmpty()) {
            $plan->markReady(0, 0, 0);
            return;
        }

        // Загружаем продукты для метаданных
        $skus = $warehouses->pluck('sku')->unique()->toArray();
        $products = Product::where('integration_id', $integrationId)
            ->where('marketplace', $marketplace)
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy('sku');

        $totalLines = 0;
        $totalQty = 0;
        $linesWithSalesData = 0;
        $lines = [];

        foreach ($warehouses as $wh) {
            $product = $products->get($wh->sku);

            // EWMA расчёт
            $sales7 = $wh->sales_7_days ?? 0;
            $sales30 = $wh->sales_30_days ?? 0;
            $shortAvg = $sales7 > 0 ? $sales7 / 7 : 0;
            $longAvg = $sales30 > 0 ? $sales30 / 30 : 0;

            // Если есть только одно окно данных — используем его
            if ($shortAvg > 0 && $longAvg > 0) {
                $ewmaDaily = $ewmaAlpha * $shortAvg + (1 - $ewmaAlpha) * $longAvg;
            } elseif ($shortAvg > 0) {
                $ewmaDaily = $shortAvg;
            } elseif ($longAvg > 0) {
                $ewmaDaily = $longAvg;
            } else {
                $ewmaDaily = 0;
            }

            $currentStock = $wh->quantity ?? 0;
            $inTransit = $wh->in_transit ?? 0;
            $avgDailySales = $wh->average_daily_sales ?? 0;

            // Demand и qty
            $demand = $ewmaDaily * ($targetDays + $safetyDays + $leadTimeDays);
            $qtyRaw = $demand - $currentStock - $inTransit;
            $qtyRounded = max(0, (int) ceil($qtyRaw));

            // Risk level
            $daysOfStock = $ewmaDaily > 0
                ? (int) floor($currentStock / $ewmaDaily)
                : ($currentStock > 0 ? 999 : 0);

            $riskLevel = match (true) {
                $daysOfStock <= 3 => 'critical',
                $daysOfStock <= 7 => 'high',
                $daysOfStock <= 14 => 'medium',
                default => 'low',
            };

            // Explain JSON
            $explainJson = [
                'formula' => 'demand = ewma_daily × (target_days + safety_days + lead_time_days); qty = demand - stock - in_transit',
                'ewma_alpha' => $ewmaAlpha,
                'sales_7d' => $sales7,
                'sales_30d' => $sales30,
                'short_avg' => round($shortAvg, 4),
                'long_avg' => round($longAvg, 4),
                'ewma_daily' => round($ewmaDaily, 4),
                'target_days' => $targetDays,
                'safety_days' => $safetyDays,
                'lead_time_days' => $leadTimeDays,
                'demand' => round($demand, 2),
                'current_stock' => $currentStock,
                'in_transit' => $inTransit,
                'qty_raw' => round($qtyRaw, 2),
                'qty_rounded' => $qtyRounded,
                'days_of_stock' => $daysOfStock,
                'risk_level' => $riskLevel,
            ];

            // Simulation: day-by-day stock projection
            $simulation = $this->buildSimulation(
                $currentStock + $inTransit,
                $ewmaDaily,
                $qtyRounded,
                $leadTimeDays,
                $targetDays
            );

            // Определяем offer_id и barcode
            $offerId = $wh->sku;
            $barcode = $product?->barcode;

            if ($sales30 > 0) {
                $linesWithSalesData++;
            }

            // Пропускаем строки с нулевым количеством
            if ($qtyRounded <= 0) {
                continue;
            }

            $lines[] = [
                'auto_supply_plan_id' => $plan->id,
                'sku' => $wh->sku,
                'offer_id' => $offerId,
                'product_name' => $product?->name,
                'barcode' => $barcode,
                'warehouse_id' => $wh->warehouse_id,
                'warehouse_name' => $wh->warehouse_name,
                'destination' => $wh->warehouse_name,
                'qty_raw' => round($qtyRaw, 2),
                'qty_rounded' => $qtyRounded,
                'current_stock' => $currentStock,
                'in_transit' => $inTransit,
                'avg_daily_sales' => round($avgDailySales, 4),
                'ewma_daily_sales' => round($ewmaDaily, 4),
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

        // Data quality score
        $totalWarehouseRows = $warehouses->count();
        $qualityScore = $totalWarehouseRows > 0
            ? round(($linesWithSalesData / $totalWarehouseRows) * 100, 2)
            : 0;

        $plan->markReady($qualityScore, $totalLines, $totalQty);

        Log::info('CalculateAutoSupplyPlanJob completed', [
            'plan_id' => $plan->id,
            'total_lines' => $totalLines,
            'total_qty' => $totalQty,
            'quality_score' => $qualityScore,
        ]);
    }

    /**
     * Симуляция остатков по дням
     */
    private function buildSimulation(
        int $initialStock,
        float $ewmaDaily,
        int $supplyQty,
        int $leadTimeDays,
        int $totalDays
    ): array {
        $simulation = [];
        $stock = (float) $initialStock;
        $supplyArrived = false;

        for ($day = 1; $day <= $totalDays; $day++) {
            // Поставка приходит после lead_time_days
            if (!$supplyArrived && $day > $leadTimeDays && $supplyQty > 0) {
                $stock += $supplyQty;
                $supplyArrived = true;
            }

            // Продажи за день
            $dailySales = $ewmaDaily;
            $stock = max(0, $stock - $dailySales);

            $simulation[] = [
                'day' => $day,
                'stock' => round($stock, 1),
                'sales_forecast' => round($dailySales, 2),
                'supply_arrived' => (!$supplyArrived && $day === $leadTimeDays + 1) ? $supplyQty : 0,
            ];
        }

        return $simulation;
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
