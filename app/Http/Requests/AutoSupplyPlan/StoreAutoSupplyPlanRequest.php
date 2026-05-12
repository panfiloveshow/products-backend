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
            'mode' => 'nullable|string|in:anti_oos,balanced,cash_safe',
            'horizon_days' => 'nullable|integer|in:14,30,60,90',
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
            'cluster_ids.*' => 'integer',

            // Ozon-анкер для рекомендуемого количества (internal/ozon/min/max/average)
            'ozon_qty_anchor' => 'nullable|string|in:internal,ozon,min,max,average',
            'demand_seasonality_multiplier' => 'nullable|numeric|min:0.1|max:5',
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
