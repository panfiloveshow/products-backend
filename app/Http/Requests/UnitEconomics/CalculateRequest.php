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
            'volume_weight' => 'nullable|numeric|min:0',
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
            'route_key' => 'nullable|string|max:120',
            'route_label' => 'nullable|string|max:255',
            'shipping_cluster_id' => 'nullable|string|max:120',
            'shipping_cluster_name' => 'nullable|string|max:255',
            'destination_cluster_id' => 'nullable|string|max:120',
            'destination_cluster_name' => 'nullable|string|max:255',
            'is_local_sale' => 'nullable|boolean',
            'non_local_markup_percent' => 'nullable|numeric|min:0|max:100',
            'raw_non_local_markup_percent' => 'nullable|numeric|min:0|max:100',
            'fixation_applied' => 'nullable|boolean',
            'fixation_id' => 'nullable|integer|min:1',
            'fixation_base_date' => 'nullable|date',
            'fixed_until' => 'nullable|date',
            'order_date' => 'nullable|date',
            'tariff_version_used' => 'nullable|string|max:120',
            'markup_version_used' => 'nullable|string|max:120',
            'markup_applied' => 'nullable|boolean',
            'markup_reason_code' => 'nullable|string|max:120',
            'markup_reason_label' => 'nullable|string|max:500',
            'markup_exception_status' => 'nullable|in:confirmed,inferred,unavailable',
            'calculation_mode' => 'nullable|in:factual,preview,estimate',
            'marketplace_compensation' => 'nullable|numeric',
            'own_delivery_cost' => 'nullable|numeric|min:0',
            'own_return_cost' => 'nullable|numeric|min:0',
            'fulfillment_type' => 'nullable|in:FBO,FBS,RFBS,EXPRESS',
            
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
