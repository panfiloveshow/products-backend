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
        ];
    }
}
