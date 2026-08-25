<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\Posting;
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

        $query = Integration::syncable()->where('marketplace', 'ozon');
        
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

        // Сохраняем аналитику.
        // Колонки сопоставлены со схемой миграции 2026_01_22_140600_create_supply_analytics_table:
        // период хранится как (date, period_type), а не period_start/period_end.
        // Для MAPE/min/max lead time и детального metrics-блоба отдельных колонок в таблице нет —
        // bias кладём в demand_vs_actual, остальное не персистим (см. docs/TZ_PLAN_FACT_LOOP.md,
        // где этот срез метрик планируется перенести в plan_line_evaluations).
        SupplyAnalytics::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'date' => $endDate->toDateString(),
                'period_type' => $this->resolvePeriodType($period),
            ],
            [
                'oos_rate' => $oosRate['rate'],
                'oos_skus_count' => $oosRate['skus'],
                // null при status=insufficient_data — больше не пишем фейковые 100%.
                'forecast_accuracy' => $forecastAccuracy['accuracy'],
                'demand_vs_actual' => $forecastAccuracy['bias'],
                'avg_lead_time_days' => round($leadTimeStats['avg_hours'] / 24, 2),
                'supplies_created' => $supplyStats['total'],
                'supplies_completed' => $supplyStats['completed'],
                'supplies_cancelled' => $supplyStats['cancelled'],
                'supplies_with_errors' => $supplyStats['errors'],
                'items_shipped' => $acceptanceRate['total_planned'],
                'items_accepted' => $acceptanceRate['total_accepted'],
                'items_rejected' => $acceptanceRate['total_rejected'],
                // null при status=insufficient_data (нет поставок / нет планового кол-ва) — не пишем фейковые 100%.
                'acceptance_rate' => $acceptanceRate['rate'],
            ]
        );

        $hasForecast = ($forecastAccuracy['status'] ?? null) === 'ok';

        $this->table(
            ['Метрика', 'Значение'],
            [
                ['OOS Rate', number_format($oosRate['rate'], 2) . '%'],
                ['Forecast Accuracy', $hasForecast ? number_format($forecastAccuracy['accuracy'], 2) . '%' : 'н/д (нет факта)'],
                ['MAPE', $hasForecast ? number_format($forecastAccuracy['mape'], 2) . '%' : 'н/д'],
                ['Forecast samples', $forecastAccuracy['samples']],
                ['Avg Lead Time', number_format($leadTimeStats['avg_hours'], 1) . ' ч'],
                ['Total Supplies', $supplyStats['total']],
                ['Completed', $supplyStats['completed']],
                ['Acceptance Rate', ($acceptanceRate['status'] ?? null) === 'ok'
                    ? number_format($acceptanceRate['rate'], 2) . '%'
                    : 'н/д (нет поставок)'],
            ]
        );
    }

    /**
     * Тип периода для строки аналитики, исходя из глубины расчёта в днях.
     */
    private function resolvePeriodType(int $period): string
    {
        return match (true) {
            $period <= 1 => 'daily',
            $period <= 7 => 'weekly',
            default => 'monthly',
        };
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
            // Нет завершённых рекомендаций для сверки — точность неизвестна, а не "идеальна".
            return [
                'accuracy' => null,
                'mape' => null,
                'bias' => null,
                'samples' => 0,
                'status' => 'insufficient_data',
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
            // Рекомендации есть, но фактических продаж по ним не нашлось — сверять не с чем.
            return [
                'accuracy' => null,
                'mape' => null,
                'bias' => null,
                'samples' => 0,
                'status' => 'insufficient_data',
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
            'status' => 'ok',
        ];
    }

    /**
     * Получить фактические продажи SKU за период.
     *
     * Источник факта — Ozon FBO postings/posting_items (таблиц orders/order_items в БД нет).
     * Эталон источника — App\Domains\Locality\Recommendation\DemandForecaster: JOIN postings p /
     * posting_items pi с группировкой по offer_id. SupplyRecommendation.sku в разных генераторах
     * содержит либо внутренний sku, либо offer_id продавца (см. LegacySupplyRecommendationService),
     * поэтому матчим posting_items по обоим полям. Отменённые отправления исключаем.
     */
    private function getActualSales(string $sku, int $integrationId, Carbon $startDate, int $days): float
    {
        $endDate = $startDate->copy()->addDays($days);

        $sales = DB::table('posting_items as pi')
            ->join('postings as p', 'pi.posting_id', '=', 'p.id')
            ->where('p.integration_id', (string) $integrationId)
            ->where(function ($q) use ($sku) {
                $q->where('pi.offer_id', $sku)
                    ->orWhere('pi.sku', $sku);
            })
            ->whereBetween('p.created_at', [$startDate, $endDate])
            ->where('p.status', '!=', Posting::STATUS_CANCELLED)
            ->whereNull('p.cancelled_at')
            ->sum('pi.quantity');

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
            // Нет завершённых поставок за период — приёмку измерять не на чем, а не "идеальна".
            return [
                'rate' => null,
                'partial_count' => 0,
                'rejection_rate' => 0,
                'total_planned' => 0,
                'total_accepted' => 0,
                'total_rejected' => 0,
                'status' => 'insufficient_data',
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

        if ($totalPlanned <= 0) {
            // Поставки есть, но планового количества по позициям нет — делить не на что.
            return [
                'rate' => null,
                'partial_count' => $partialCount,
                'rejection_rate' => 0,
                'total_planned' => 0,
                'total_accepted' => $totalAccepted,
                'total_rejected' => $totalRejected,
                'status' => 'insufficient_data',
            ];
        }

        $acceptanceRate = ($totalAccepted / $totalPlanned) * 100;
        $rejectionRate = ($totalRejected / $totalPlanned) * 100;

        return [
            'rate' => round($acceptanceRate, 2),
            'partial_count' => $partialCount,
            'rejection_rate' => round($rejectionRate, 2),
            'total_planned' => $totalPlanned,
            'total_accepted' => $totalAccepted,
            'total_rejected' => $totalRejected,
            'status' => 'ok',
        ];
    }
}
