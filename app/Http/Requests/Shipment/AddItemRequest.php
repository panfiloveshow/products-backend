<?php

namespace App\Http\Requests\Shipment;

use Illuminate\Foundation\Http\FormRequest;

class AddItemRequest extends FormRequest
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
            'quantity' => 'required|integer|min:1',
            'cost_price' => 'nullable|numeric|min:0',
            'volume_per_unit' => 'nullable|numeric|min:0',
            'weight_per_unit' => 'nullable|numeric|min:0',
            'priority' => 'nullable|in:critical,medium,low',
            'marketplaces' => 'nullable|array',
            'marketplaces.*' => 'in:wildberries,ozon,yandex',
        ];
    }
}
