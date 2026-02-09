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
            'integration_id' => 'required|integer|exists:integrations,id',
            'target_days' => 'nullable|integer|min:7|max:90',
            'safety_days' => 'nullable|integer|min:0|max:30',
            'lead_time_days' => 'nullable|integer|min:0|max:30',
            'ewma_alpha' => 'nullable|numeric|min:0.1|max:0.9',
        ];
    }
}
