<?php

namespace App\Http\Requests\AutoSupplyPlan;

use Illuminate\Foundation\Http\FormRequest;

class StoreAutoSupplyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'integration_id' => 'required|integer',
            'mode' => 'nullable|string|in:anti_oos,balanced,cash_safe,protect_oos,improve_locality,max_profit,post_promo_careful',
            'planning_mode' => 'nullable|string|in:anti_oos,balanced,cash_safe,protect_oos,improve_locality,max_profit,post_promo_careful',
            'analysis_period_days' => 'nullable|integer|in:7,14,28,30,56,60,90',
            'horizon_days' => 'nullable|integer|in:7,14,28,30,56,60,90',
            'min_cover_days' => 'nullable|integer|min:1|max:90',
            'target_cover_days' => 'nullable|integer|min:1|max:120',
            'max_cover_days' => 'nullable|integer|min:1|max:180',
            'safety_stock_days' => 'nullable|integer|min:0|max:30',
            'turnover_limit_days' => 'nullable|integer|min:1|max:365',
            'budget_limit' => 'nullable|numeric|min:0',
            'lead_time_days' => 'nullable|integer|min:0|max:30',
            'ewma_alpha' => 'nullable|numeric|min:0.1|max:0.9',
            'warehouse_ids' => 'nullable|array',
            'warehouse_ids.*' => 'string',
            'cluster_ids' => 'nullable|array',
            'cluster_ids.*' => 'integer|min:1',
            'warehouse_constraints' => 'nullable|array',
            'cluster_constraints' => 'nullable|array',
            'constraint_file_id' => 'nullable|integer',
            'use_latest_constraint_file' => 'nullable|boolean',
            'constraint_metadata' => 'nullable|array',
            'target_ktr' => 'nullable|numeric|min:1|max:100',
            'baseline_ktr' => 'nullable|numeric|min:0|max:100',
            'draft_supply_method' => 'nullable|string|in:direct,crossdock,cross_dock,cross-dock',
            'supply_method' => 'nullable|string|in:direct,crossdock,cross_dock,cross-dock',
            'drop_off_point_warehouse_id' => 'nullable|integer|min:1',
            'crossdock_drop_off_point_warehouse_id' => 'nullable|integer|min:1',

            // Ozon-анкер для рекомендуемого количества (internal/ozon/min/max/average)
            'ozon_qty_anchor' => 'nullable|string|in:internal,ozon,min,max,average',
            'demand_seasonality_multiplier' => 'nullable|numeric|min:0.1|max:5',
            'seasonality_multiplier' => 'nullable|numeric|min:0.1|max:5',
            'trend_multiplier' => 'nullable|numeric|min:0.1|max:5',
            'promo_mode' => 'nullable|string|in:none,cautious,promo,post_promo',
            'performance_report_uuid' => 'nullable|string|max:120',
            'ozon_performance_report_uuid' => 'nullable|string|max:120',
            'performance_period' => 'nullable|array',
            'performance_period.date_from' => 'nullable|date_format:Y-m-d',
            'performance_period.date_to' => 'nullable|date_format:Y-m-d|after_or_equal:performance_period.date_from',
            'include_in_transit' => 'nullable|boolean',
            'skip_negative_profit' => 'nullable|boolean',
            'include_wb_supplies_api_in_transit' => 'nullable|boolean',

            // Locality integration
            'split_by_cluster' => 'nullable|boolean',
            'minimum_locality_confidence' => 'nullable|string|in:low,medium,high',
            'include_locality_recommendations' => 'nullable|boolean',
            'locality_distribution_strategy' => 'nullable|string|in:recommendations,demand_weighted,proportional',
        ];
    }
}
