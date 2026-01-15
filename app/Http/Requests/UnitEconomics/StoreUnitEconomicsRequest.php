<?php

namespace App\Http\Requests\UnitEconomics;

use Illuminate\Foundation\Http\FormRequest;

class StoreUnitEconomicsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => 'required|string|max:100',
            'product_name' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
            'sales_count' => 'nullable|integer|min:0',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'marketplace_data' => 'nullable|array',
        ];
    }
}
