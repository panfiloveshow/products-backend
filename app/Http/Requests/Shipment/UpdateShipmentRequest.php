<?php

namespace App\Http\Requests\Shipment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:200',
            'warehouse_name' => 'nullable|string|max:200',
            'truck_type' => 'nullable|string|max:50',
            'truck_capacity' => 'nullable|numeric|min:0',
            'packaging' => 'nullable|array',
            'packaging.boxes_count' => 'nullable|integer|min:0',
            'packaging.total_weight' => 'nullable|numeric|min:0',
            'packaging.total_volume' => 'nullable|numeric|min:0',
        ];
    }
}
