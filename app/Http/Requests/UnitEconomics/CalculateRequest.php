<?php

namespace App\Http\Requests\UnitEconomics;

use Illuminate\Foundation\Http\FormRequest;

class CalculateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
            'sales_count' => 'nullable|integer|min:0',
            
            // WB specific
            'wb_commission_percent' => 'nullable|numeric|min:0|max:100',
            'volume_liters' => 'nullable|numeric|min:0',
            'storage_tariff' => 'nullable|numeric|min:0',
            'storage_days' => 'nullable|integer|min:0',
            'logistics_cost' => 'nullable|numeric|min:0',
            'acceptance_cost' => 'nullable|numeric|min:0',
            'penalty_cost' => 'nullable|numeric|min:0',
            'return_logistics_cost' => 'nullable|numeric|min:0',
            'advertising_cost' => 'nullable|numeric|min:0',
            'spp_percent' => 'nullable|numeric|min:0|max:100',
            'ks_percent' => 'nullable|numeric|min:0|max:100',
            
            // Ozon specific
            'fbo_commission_percent' => 'nullable|numeric|min:0|max:100',
            'fbs_commission_percent' => 'nullable|numeric|min:0|max:100',
            'last_mile_cost' => 'nullable|numeric|min:0',
            'return_cost' => 'nullable|numeric|min:0',
            'storage_cost' => 'nullable|numeric|min:0',
            'acquiring_percent' => 'nullable|numeric|min:0|max:100',
            'packaging_cost' => 'nullable|numeric|min:0',
            'fulfillment_type' => 'nullable|in:FBO,FBS',
            
            // Yandex specific
            'referral_fee_percent' => 'nullable|numeric|min:0|max:100',
            'fby_placement' => 'nullable|numeric|min:0',
            'fby_pickup_transfer' => 'nullable|numeric|min:0',
            'fby_delivery' => 'nullable|numeric|min:0',
            'fby_middle_mile' => 'nullable|numeric|min:0',
            'fbs_placement' => 'nullable|numeric|min:0',
            'fbs_pickup_transfer' => 'nullable|numeric|min:0',
            'fbs_delivery' => 'nullable|numeric|min:0',
            'fbs_middle_mile' => 'nullable|numeric|min:0',
        ];
    }
}
