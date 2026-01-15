<?php

namespace App\Http\Requests\UnitEconomics;

use App\Http\Requests\BaseFormRequest;

class IndexUnitEconomicsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'marketplace' => 'nullable|in:ozon,wildberries,yandex_market',
            'integration_id' => 'nullable|integer',
            // Ozon: FBO, FBS, RFBS, EXPRESS
            // WB: FBW, FBS, DBS, EDBS, DBW
            'fulfillment_type' => 'nullable|in:FBO,FBS,RFBS,EXPRESS,FBW,DBS,EDBS,DBW',
            'profitability' => 'nullable|in:all,profitable,unprofitable',
            'margin_min' => 'nullable|numeric',
            'margin_max' => 'nullable|numeric',
            'price_min' => 'nullable|numeric|min:0',
            'price_max' => 'nullable|numeric|min:0',
            'sort' => 'nullable|in:name,sku,profit,margin,price',
            'sort_order' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:200',
        ];
    }
}
