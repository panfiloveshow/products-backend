<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\Supply;
use App\Models\SupplyAnalytics;
use App\Models\SupplyRecommendation;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalculateSupplyAnalyticsCommand extends Command
{
    protected $signature = 'supplies:analytics 
                            {--integration= : ID интеграции (опционально)}
                            {--period=30 : Период в днях для расчёта}';

    protected $description = 'Рассчитать аналитику поставок (OOS rate, forecast accuracy, lead time)';

    public function handle(): int
    {
        $integrationId = $this->option('integration');
        $period = (int) $this->option('period');

        $query = Integration::where('marketplace', 'ozon')->where('is_active', true);
        
        if ($integrationId) {
            $query->where('id', $integrationId);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->warn('Нет активных Ozon интеграций');
            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            $this->info("Расчёт аналитики для интеграции #{$integration->id}...");
            
            try {
                $this->calculateAnalytics($integration, $period);
                $this->info("✓ Аналитика рассчитана для интеграции #{$integration->id}");
            } catch (\Throwable $e) {
                $this->error("✗ Ошибка для интеграции #{$integration->id}: {$e->getMessage()}");
                Log::error('Supply analytics calculation failed', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function calculateAnalytics(Integration $integration, int $period): void
    {
        $startDate = Carbon::now()->subDays($period)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        // 1. OOS Rate (Out of Stock Rate)
        $oosRate = $this->calculateOosRate($integration, $startDate, $endDate);

        // 2. Forecast Accuracy (точность прогноза)
        $forecastAccuracy = $this->calculateForecastAccuracy($integration, $startDate, $endDate);

        // 3. Lead Time Stats (время выполнения поставки)
        $leadTimeStats = $this->calculateLeadTimeStats($integration, $startDate, $endDate);

        // 4. Supply Stats (статистика поставок)
        $supplyStats = $this->calculateSupplyStats($integration, $startDate, $endDate);

        // 5. Acceptance Rate (процент приёмки)
        $acceptanceRate = $this->calculateAcceptanceRate($integration, $startDate, $endDate);

        // Сохраняем аналитику
        SupplyAnalytics::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'period_start' => $startDate->toDateString(),
                'period_end' => $endDate->toDateString(),
            ],
            [
                'oos_rate' => $oosRate['rate'],
                'oos_days' => $oosRate['days'],
                'oos_skus' => $oosRate['skus'],
                'forecast_accuracy' => $forecastAccuracy['accuracy'],
                'forecast_mape' => $forecastAccuracy['mape'],
                'forecast_bias' => $forecastAccuracy['bias'],
                'avg_lead_time_hours' => $leadTimeStats['avg_hours'],
                'min_lead_time_hours' => $leadTimeStats['min_hours'],
                'max_lead_time_hours' => $leadTimeStats['max_hours'],
                'total_supplies' => $supplyStats['total'],
                'completed_supplies' => $supplyStats['completed'],
                'cancelled_supplies' => $supplyStats['cancelled'],
                'error_supplies' => $supplyStats['errors'],
                'total_items' => $supplyStats['total_items'],
                'total_quantity' => $supplyStats['total_quantity'],
                'acceptance_rate' => $acceptanceRate['rate'],
                'partial_acceptance_count' => $acceptanceRate['partial_count'],
                'rejection_rate' => $acceptanceRate['rejection_rate'],
                'metrics' => [
                    'oos_details' => $oosRate,
                    'forecast_details' => $forecastAccuracy,
                    'lead_time_details' => $leadTimeStats,
                    'supply_details' => $supplyStats,
                    'acceptance_details' => $acceptanceRate,
                ],
                'calculated_at' => now(),
            ]
        );

        $this->table(
            ['Метрика', 'Значение'],
            [
                ['OOS Rate', number_format($oosRate['rate'], 2) . '%'],
                ['Forecast Accuracy', number_format($forecastAccuracy['accuracy'], 2) . '%'],
                ['MAPE', number_format($forecastAccuracy['mape'], 2) . '%'],
                ['Avg Lead Time', number_format($leadTimeStats['avg_hours'], 1) . ' ч'],
                ['Total Supplies', $supplyStats['total']],
                ['Completed', $supplyStats['completed']],
                ['Acceptance Rate', number_format($acceptanceRate['rate'], 2) . '%'],
            ]
        );
    }

    /**
     * OOS Rate - процент дней/SKU с нулевым остатком
     */
    private function calculateOosRate(Integration $integration, Carbon $startDate, Carbon $endDate): array
    {
        // Получаем историю остатков из inventory_history
        $oosData = DB::table('inventory_history as ih')
            ->join('products as p', 'ih.product_id', '=', 'p.id')
            ->where('p.integration_id', $integration->id)
            ->whereBetween('ih.snapshot_date', [$startDate, $endDate])
            ->select([
                DB::raw('COUNT(DISTINCT ih.snapshot_date) as total_days'),
                DB::raw('COUNT(DISTINCT CASE WHEN ih.quantity = 0 THEN ih.snapshot_date END) as oos_days'),
                DB::raw('COUNT(DISTINCT ih.product_id) as total_skus'),
                DB::raw('COUNT(DISTINCT CASE WHEN ih.quantity = 0 THEN ih.product_id END) as oos_skus'),
                DB::raw('SUM(CASE WHEN ih.quantity = 0 THEN 1 ELSE 0 END) as oos_records'),
                DB::raw('COUNT(*) as total_records'),
            ])
            ->first();

        $totalDays = $oosData->total_days ?? 1;
        $oosDays = $oosData->oos_days ?? 0;
        $totalSkus = $oosData->total_skus ?? 1;
        $oosSkus = $oosData->oos_skus ?? 0;
        $oosRecords = $oosData->oos_records ?? 0;
        $totalRecords = $oosData->total_records ?? 1;

        // OOS Rate = (OOS records / Total records) * 100
        $rate = $totalRecords > 0 ? ($oosRecords / $totalRecords) * 100 : 0;

        return [
            'rate' => round($rate, 2),
            'days' => $oosDays,
            'skus' => $oosSkus,
            'total_days' => $totalDays,
            'total_skus' => $totalSkus,
            'oos_records' => $oosRecords,
            'total_records' => $totalRecords,
        ];
    }

    /**
     * Forecast Accuracy - точность прогноза продаж
     * Сравниваем рекомендованное количество с фактическими продажами
     */
    private function calculateForecastAccuracy(Integration $integration, Carbon $startDate, Carbon $endDate): array
    {
        // Получаем выполненные рекомендации с фактическими данными
        $recommendations = SupplyRecommendation::where('integration_id', $integration->id)
            ->where('state', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('recommended_qty')
            ->where('recommended_qty', '>', 0)
            ->get();

        if ($recommendations->isEmpty()) {
            return [
                'accuracy' => 100,
                'mape' => 0,
                'bias' => 0,
                'samples' => 0,
            ];
        }

        $totalAbsError = 0;
        $totalError = 0;
        $totalActual = 0;
        $count = 0;

        foreach ($recommendations as $rec) {
            // Прогноз = demand (рассчитанный спрос)
            $forecast = $rec->demand ?? $rec->recommended_qty;
            
            // Фактические продажи за период target_days после создания рекомендации
            $actualSales = $this->getActualSales(
                $rec->sku,
                $integration->id,
                $rec->created_at,
                $rec->target_days ?? 14
            );

            if ($actualSales > 0) {
                $error = $forecast - $actualSales;
                $absError = abs($error);
                
                $totalAbsError += $absError / $actualSales;
                $totalError += $error / $actualSales;
                $totalActual += $actualSales;
                $count++;
            }
        }

        if ($count === 0) {
            return [
                'accuracy' => 100,
                'mape' => 0,
                'bias' => 0,
                'samples' => 0,
            ];
        }

        // MAPE (Mean Absolute Percentage Error)
        $mape = ($totalAbsError / $count) * 100;
        
        // Bias (систематическая ошибка: положительная = переоценка, отрицательная = недооценка)
        $bias = ($totalError / $count) * 100;
        
        // Accuracy = 100 - MAPE (ограничиваем 0-100)
        $accuracy = max(0, min(100, 100 - $mape));

        return [
            'accuracy' => round($accuracy, 2),
            'mape' => round($mape, 2),
            'bias' => round($bias, 2),
            'samples' => $count,
        ];
    }

    /**
     * Получить фактические продажи SKU за период
     */
    private function getActualSales(string $sku, int $integrationId, Carbon $startDate, int $days): float
    {
        $endDate = $startDate->copy()->addDays($days);

        // Пытаемся получить из order_items или sales_history
        $sales = DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->join('products as p', 'oi.product_id', '=', 'p.id')
            ->where('p.sku', $sku)
            ->where('p.integration_id', $integrationId)
            ->whereBetween('o.created_at', [$startDate, $endDate])
            ->whereIn('o.status', ['delivered', 'completed', 'shipped'])
            ->sum('oi.quantity');

        return (float) $sales;
    }

    /**
     * Lead Time Stats - статистика времени выполнения поставки
     */
    private function calculateLeadTimeStats(Integration $integration, Carbon $startDate, Carbon $endDate): array
    {
        $supplies = Supply::where('integration_id', $integration->id)
            ->whereIn('status', ['accepted_full', 'accepted_partial', 'closed'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('timeslot_to')
            ->get();

        if ($supplies->isEmpty()) {
            return [
                'avg_hours' => 0,
                'min_hours' => 0,
                'max_hours' => 0,
                'samples' => 0,
            ];
        }

        $leadTimes = [];

        foreach ($supplies as $supply) {
            // Lead time = от создания до приёмки на складе
            $createdAt = Carbon::parse($supply->created_at);
            $acceptedAt = $supply->updated_at; // Примерно время приёмки
            
            $hours = $createdAt->diffInHours($acceptedAt);
            $leadTimes[] = $hours;
        }

        return [
            'avg_hours' => round(array_sum($leadTimes) / count($leadTimes), 1),
            'min_hours' => min($leadTimes),
            'max_hours' => max($leadTimes),
            'samples' => count($leadTimes),
        ];
    }

    /**
     * Supply Stats - общая статистика поставок
     */
    private function calculateSupplyStats(Integration $integration, Carbon $startDate, Carbon $endDate): array
    {
        $stats = Supply::where('integration_id', $integration->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select([
                DB::raw('COUNT(*) as total'),
                DB::raw("COUNT(CASE WHEN status IN ('accepted_full', 'accepted_partial', 'closed') THEN 1 END) as completed"),
                DB::raw("COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled"),
                DB::raw("COUNT(CASE WHEN status = 'error' THEN 1 END) as errors"),
                DB::raw('SUM(items_count) as total_items'),
                DB::raw('SUM(total_quantity) as total_quantity'),
            ])
            ->first();

        return [
            'total' => $stats->total ?? 0,
            'completed' => $stats->completed ?? 0,
            'cancelled' => $stats->cancelled ?? 0,
            'errors' => $stats->errors ?? 0,
            'total_items' => $stats->total_items ?? 0,
            'total_quantity' => $stats->total_quantity ?? 0,
        ];
    }

    /**
     * Acceptance Rate - процент приёмки товаров
     */
    private function calculateAcceptanceRate(Integration $integration, Carbon $startDate, Carbon $endDate): array
    {
        $supplies = Supply::where('integration_id', $integration->id)
            ->whereIn('status', ['accepted_full', 'accepted_partial', 'closed'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with('items')
            ->get();

        if ($supplies->isEmpty()) {
            return [
                'rate' => 100,
                'partial_count' => 0,
                'rejection_rate' => 0,
                'total_planned' => 0,
                'total_accepted' => 0,
                'total_rejected' => 0,
            ];
        }

        $totalPlanned = 0;
        $totalAccepted = 0;
        $totalRejected = 0;
        $partialCount = 0;

        foreach ($supplies as $supply) {
            foreach ($supply->items as $item) {
                $planned = $item->planned_qty ?? 0;
                $accepted = $item->accepted_qty ?? $planned;
                $rejected = $item->rejected_qty ?? 0;

                $totalPlanned += $planned;
                $totalAccepted += $accepted;
                $totalRejected += $rejected;
            }

            if ($supply->status === 'accepted_partial') {
                $partialCount++;
            }
        }

        $acceptanceRate = $totalPlanned > 0 ? ($totalAccepted / $totalPlanned) * 100 : 100;
        $rejectionRate = $totalPlanned > 0 ? ($totalRejected / $totalPlanned) * 100 : 0;

        return [
            'rate' => round($acceptanceRate, 2),
            'partial_count' => $partialCount,
            'rejection_rate' => round($rejectionRate, 2),
            'total_planned' => $totalPlanned,
            'total_accepted' => $totalAccepted,
            'total_rejected' => $totalRejected,
        ];
    }
}
