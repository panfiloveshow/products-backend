<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoSupplyPlanLine extends Model
{
    use HasFactory;

    protected $appends = [
        'reason',
        'reason_label',
        'confidence',
        'confidence_label',
        'confidence_reasons',
        'confidence_reason_labels',
        'quantity_explanation',
        'demand_source',
        'demand_source_label',
        'stock_source',
        'stock_source_label',
        'economics_source',
        'economics_source_label',
        'deficit_qty',
        'surplus_qty',
        'in_transit_qty',
        'not_recommended_reason',
        'not_recommended_reason_label',
        'planning_decision',
    ];

    protected $fillable = [
        'auto_supply_plan_id',
        'tenant_id',
        'sku',
        'offer_id',
        'product_name',
        'barcode',
        'price',
        'cost_price',
        'warehouse_id',
        'warehouse_name',
        'cluster_id',
        'cluster_name',
        'region',
        'own_stock',
        'own_stock_reserved',
        'deficit',
        'destination',
        'destination_id',
        'destination_type',
        'qty_recommended',
        'qty_rounded',
        'current_stock',
        'in_transit',
        'sales_7_days',
        'sales_14_days',
        'sales_30_days',
        'avg_daily_sales',
        'ewma_daily_sales',
        'demand_daily',
        'sales_trend',
        'sales_trend_percent',
        'cover_days_before',
        'cover_days_after',
        'oos_date',
        'surplus_days',
        'storage_cost_daily',
        'storage_cost_monthly',
        'lost_revenue_daily',
        'supply_cost_estimate',
        'expected_revenue',
        'expected_profit',
        'roi_percent',
        'priority_score',
        'priority',
        'turnover_days',
        'explain_json',
        'risk_level',
        'simulation_json',
        // Locality integration
        'local_share_percent',
        'potential_overpayment_rub',
        'lost_margin_rub',
        'expected_local_share_after_pp',
        'expected_savings_rub',
        'locality_confidence',
        'cluster_split_json',
        'linked_locality_recommendation_ids',
        'parent_line_key',
        'is_cluster_split',
        'aggregated_qty_rounded',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'qty_recommended' => 'decimal:2',
        'qty_rounded' => 'integer',
        'current_stock' => 'integer',
        'in_transit' => 'integer',
        'sales_7_days' => 'integer',
        'sales_14_days' => 'integer',
        'sales_30_days' => 'integer',
        'avg_daily_sales' => 'decimal:4',
        'ewma_daily_sales' => 'decimal:4',
        'demand_daily' => 'decimal:4',
        'sales_trend_percent' => 'decimal:2',
        'cover_days_before' => 'decimal:2',
        'cover_days_after' => 'decimal:2',
        'surplus_days' => 'integer',
        'storage_cost_daily' => 'decimal:2',
        'storage_cost_monthly' => 'decimal:2',
        'lost_revenue_daily' => 'decimal:2',
        'supply_cost_estimate' => 'decimal:2',
        'expected_revenue' => 'decimal:2',
        'expected_profit' => 'decimal:2',
        'roi_percent' => 'decimal:2',
        'priority_score' => 'decimal:2',
        'turnover_days' => 'decimal:1',
        'explain_json' => 'array',
        'simulation_json' => 'array',
        // Locality integration
        'local_share_percent' => 'decimal:2',
        'potential_overpayment_rub' => 'decimal:2',
        'lost_margin_rub' => 'decimal:2',
        'expected_local_share_after_pp' => 'decimal:2',
        'expected_savings_rub' => 'decimal:2',
        'cluster_split_json' => 'array',
        'linked_locality_recommendation_ids' => 'array',
        'is_cluster_split' => 'boolean',
        'aggregated_qty_rounded' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AutoSupplyPlan::class, 'auto_supply_plan_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'sku');
    }

    public function isHighRisk(): bool
    {
        return $this->risk_level === 'high';
    }

    public function getReasonAttribute(): ?string
    {
        return $this->explain_json['reason']
            ?? $this->explain_json['inputs']['supply_type']
            ?? null;
    }

    public function getReasonLabelAttribute(): ?string
    {
        return $this->labelForReason($this->reason);
    }

    public function getConfidenceAttribute(): ?string
    {
        return $this->explain_json['confidence']['confidence_level'] ?? null;
    }

    public function getConfidenceLabelAttribute(): ?string
    {
        return match ($this->confidence) {
            'good' => 'Высокая достоверность',
            'warning' => 'Нужна проверка',
            'low' => 'Низкая достоверность',
            'bad' => 'Недостаточно данных',
            default => $this->confidence,
        };
    }

    public function getConfidenceReasonsAttribute(): array
    {
        return $this->explain_json['confidence']['confidence_reasons'] ?? [];
    }

    public function getConfidenceReasonLabelsAttribute(): array
    {
        return array_values(array_map(
            fn (string $reason): string => $this->labelForReason($reason),
            $this->confidence_reasons
        ));
    }

    public function getQuantityExplanationAttribute(): array
    {
        return [
            'daily_demand' => $this->explain_json['inputs']['daily_demand'] ?? $this->demand_daily,
            'target_cover_days' => $this->explain_json['inputs']['target_cover_days'] ?? null,
            'safety_stock' => $this->explain_json['math']['safety_stock'] ?? null,
            'stock_now' => $this->current_stock,
            'in_transit' => $this->in_transit,
            'needed_before_caps' => $this->explain_json['math']['needed_before_caps'] ?? null,
            'needed_after_caps' => $this->explain_json['math']['needed_after_caps'] ?? null,
            'qty_rounded' => $this->qty_rounded,
            'caps_applied' => $this->explain_json['math']['caps_applied'] ?? [],
        ];
    }

    public function getDemandSourceAttribute(): ?string
    {
        return $this->explain_json['inputs']['demand_source']
            ?? $this->explain_json['confidence']['sources']['demand']
            ?? null;
    }

    public function getDemandSourceLabelAttribute(): ?string
    {
        return $this->labelForSource($this->demand_source);
    }

    public function getStockSourceAttribute(): ?string
    {
        return $this->explain_json['confidence']['sources']['stock'] ?? null;
    }

    public function getStockSourceLabelAttribute(): ?string
    {
        return $this->labelForSource($this->stock_source);
    }

    public function getEconomicsSourceAttribute(): ?string
    {
        return isset($this->expected_profit) ? 'unit_economics' : null;
    }

    public function getEconomicsSourceLabelAttribute(): ?string
    {
        return $this->labelForSource($this->economics_source);
    }

    public function getDeficitQtyAttribute(): int
    {
        $minCoverDays = (float) ($this->explain_json['inputs']['min_cover_days'] ?? 0);
        $dailyDemand = (float) ($this->explain_json['inputs']['daily_demand'] ?? $this->demand_daily ?? 0);
        $available = (int) $this->current_stock + (int) $this->in_transit;

        return $dailyDemand > 0 && $minCoverDays > 0
            ? max(0, (int) ceil($minCoverDays * $dailyDemand - $available))
            : 0;
    }

    public function getSurplusQtyAttribute(): int
    {
        $targetCoverDays = (float) ($this->explain_json['inputs']['target_cover_days'] ?? 0);
        $dailyDemand = (float) ($this->explain_json['inputs']['daily_demand'] ?? $this->demand_daily ?? 0);
        $available = (int) $this->current_stock + (int) $this->in_transit;

        return $dailyDemand > 0 && $targetCoverDays > 0
            ? max(0, (int) floor($available - $targetCoverDays * $dailyDemand))
            : 0;
    }

    public function getInTransitQtyAttribute(): int
    {
        return (int) $this->in_transit;
    }

    public function getNotRecommendedReasonAttribute(): ?string
    {
        if ($this->qty_rounded > 0) {
            return null;
        }

        return $this->explain_json['confidence']['fallbacks'][0]
            ?? $this->explain_json['confidence']['confidence_reasons'][0]
            ?? null;
    }

    public function getNotRecommendedReasonLabelAttribute(): ?string
    {
        return $this->not_recommended_reason
            ? $this->labelForReason($this->not_recommended_reason)
            : null;
    }

    public function getPlanningDecisionAttribute(): array
    {
        $decision = $this->explain_json['planning_decision'] ?? [];
        $scoreBasis = $decision['score_basis'] ?? [];

        return [
            'selected' => (bool) ($decision['selected'] ?? ($this->qty_rounded > 0)),
            'mode' => $decision['mode'] ?? null,
            'score' => $decision['score'] ?? null,
            'score_basis' => $scoreBasis,
            'score_basis_labels' => array_values(array_map(
                fn (string $reason): string => $this->labelForReason($reason),
                is_array($scoreBasis) ? $scoreBasis : []
            )),
        ];
    }

    private function labelForReason(?string $reason): ?string
    {
        return match ($reason) {
            null, '' => null,
            'replenishment' => 'Плановое пополнение',
            'oos_risk', 'high_oos_risk' => 'Риск отсутствия товара',
            'locality_improvement' => 'Улучшение локальности',
            'marketplace_need', 'marketplace_need_accounted' => 'Потребность маркетплейса',
            'test_cluster', 'new_warehouse' => 'Тестовая поставка',
            'not_recommended_negative_profit', 'negative_expected_profit', 'negative_profit' => 'Отрицательная прибыль',
            'not_recommended_low_confidence', 'low_confidence', 'low_confidence_trial' => 'Низкая достоверность спроса',
            'post_promo_cooldown', 'promo_spike_suspected', 'suspected_spike',
            'promo_spike_peak_share', 'promo_spike_peak_vs_median', 'recent_spike_vs_period' => 'Возможный всплеск после акции',
            'external_sources_capped_by_spike_guard' => 'Старый высокий спрос ограничен защитой от всплеска',
            'low_posting_volume' => 'Мало заказов для уверенного прогноза',
            'few_active_sales_days' => 'Продажи были слишком мало дней',
            'aggregate_sales_no_postings' => 'Нет детальных заказов Ozon по кластерам',
            'aggregate_recent_decline' => 'Спрос после всплеска снизился',
            'no_recent_sales_after_30d_spike' => 'После всплеска нет свежих продаж',
            'cluster_posting_demand_missing' => 'Нет спроса по выбранному кластеру',
            'performance_ads_driven_demand' => 'Спрос поддержан рекламой',
            'performance_high_ad_cost' => 'Высокий ДРР',
            'performance_ad_disabled_after_sales' => 'Реклама выключена после рекламных продаж',
            'performance_ad_spend_without_orders' => 'Есть расход рекламы без заказов',
            'fallback_long' => 'Расчёт по длинному резервному периоду',
            'no_sales_data' => 'Нет данных о продажах',
            'positive_roi' => 'Положительная окупаемость',
            'has_deficit' => 'Есть дефицит',
            'in_transit_accounted' => 'Учтены товары в пути',
            'in_transit_covers_need' => 'Потребность уже частично закрыта товарами в пути',
            'surplus_penalty_accounted' => 'Учтён риск лишнего запаса',
            'abc_priority_accounted' => 'Учтён ABC-приоритет товара',
            'territorial_score_accounted' => 'Учтён территориальный приоритет',
            'budget_limit' => 'Ограничение бюджета',
            default => $reason,
        };
    }

    private function labelForSource(?string $source): ?string
    {
        return match ($source) {
            null, '' => null,
            'posting_fbo_v3' => 'Заказы FBO из API Ozon',
            'ozon_order_report' => 'Отчёт заказов Ozon',
            'ozon_aggregate_robust' => 'Сглаженный спрос Ozon',
            'ozon_aggregate_robust_low_confidence_trial' => 'Пробная поставка по сглаженному спросу Ozon',
            'analytics_stocks' => 'Аналитика остатков маркетплейса',
            'product_info_stocks' => 'Текущие остатки товаров',
            'inventory_warehouses' => 'Синхронизированные остатки по складам',
            'turnover_stocks' => 'Оборачиваемость из API маркетплейса',
            'supply_orders' => 'Заявки поставки и товары в пути',
            'marketplace_inventory' => 'Товары в пути из остатков маркетплейса',
            'unit_economics' => 'Юнит-экономика',
            'new_warehouse_trial' => 'Пробный спрос для нового склада',
            default => $source,
        };
    }
}
