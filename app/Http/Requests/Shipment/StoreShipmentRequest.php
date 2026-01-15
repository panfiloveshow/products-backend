<?php

namespace App\Http\Requests\Shipment;

use Illuminate\Foundation\Http\FormRequest;

class StoreShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:200',
            'marketplace' => 'required|in:wildberries,ozon,yandex',
            'shipment_type' => 'nullable|in:fbo,fbs,dbs',
            'warehouse_id' => 'nullable|string|max:100',
            'warehouse_name' => 'nullable|string|max:200',
            'integration_id' => 'nullable|exists:integrations,id',
            'supplier_id' => 'nullable|uuid|exists:suppliers,id',
            'description' => 'nullable|string|max:1000',
            'truck_type' => 'nullable|string|max:50',
            'truck_capacity' => 'nullable|numeric|min:0',
            'items' => 'nullable|array',
            'items.*.sku' => 'required|string|max:100',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.cost_price' => 'nullable|numeric|min:0',
            'items.*.volume_per_unit' => 'nullable|numeric|min:0',
            'items.*.weight_per_unit' => 'nullable|numeric|min:0',
        ];
    }
}
