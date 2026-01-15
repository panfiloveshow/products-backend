<?php

namespace App\Http\Requests\Shipment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => 'sometimes|integer|min:1',
            'cost_price' => 'nullable|numeric|min:0',
            'volume_per_unit' => 'nullable|numeric|min:0',
            'weight_per_unit' => 'nullable|numeric|min:0',
            'priority' => 'nullable|in:critical,medium,low',
        ];
    }
}
