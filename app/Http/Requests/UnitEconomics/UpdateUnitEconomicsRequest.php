<?php

namespace App\Http\Requests\UnitEconomics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUnitEconomicsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'price' => 'sometimes|numeric|min:0',
            'cost_price' => 'sometimes|numeric|min:0',
            'sales_count' => 'nullable|integer|min:0',
            'marketplace_data' => 'nullable|array',
            // Ручные поля (проценты)
            'drr_percent' => 'sometimes|numeric|min:0|max:100',
            'our_share_percent' => 'sometimes|numeric|min:0|max:100',
            'tax_percent' => 'sometimes|numeric|min:0|max:100',
            'vat_percent' => 'sometimes|numeric|min:0|max:100',
            // Процент выкупа (влияет на ожидаемые возвраты)
            'redemption_rate' => 'sometimes|numeric|min:0|max:100',
        ];
    }
}
