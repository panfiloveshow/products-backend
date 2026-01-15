<?php

namespace App\Http\Requests\Shipment;

use Illuminate\Foundation\Http\FormRequest;

class IndexShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'nullable|array',
            'status.*' => 'in:draft,pending_logistics,approved,sent,in_transit,delivered,rejected',
            'supplier_id' => 'nullable|uuid',
            'marketplace' => 'nullable|in:wildberries,ozon,yandex',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'search' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:200',
        ];
    }
}
