<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\BaseFormRequest;

class UpdateCostPriceBulkRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1|max:1000',
            'items.*.sku' => 'required|string',
            'items.*.cost_price' => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Список товаров обязателен',
            'items.array' => 'Список товаров должен быть массивом',
            'items.min' => 'Список товаров не может быть пустым',
            'items.max' => 'Максимум 1000 товаров за один запрос',
            'items.*.sku.required' => 'SKU обязателен для каждого товара',
            'items.*.cost_price.required' => 'Себестоимость обязательна для каждого товара',
            'items.*.cost_price.min' => 'Себестоимость не может быть отрицательной',
        ];
    }
}
