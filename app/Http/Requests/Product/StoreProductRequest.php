<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => 'required|string|max:100|unique:products,sku',
            'name' => 'required|string|max:500',
            'barcode' => 'nullable|string|max:50',
            'price' => 'nullable|numeric|min:0',
            'old_price' => 'nullable|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'url',
            'category' => 'nullable|string|max:200',
            'brand' => 'nullable|string|max:200',
            'rating' => 'nullable|numeric|min:0|max:100',
            'reviews_count' => 'nullable|integer|min:0',
            'marketplace' => 'required|in:wildberries,ozon,yandex',
            'marketplace_id' => 'nullable|string|max:100',
            'url' => 'nullable|url',
            'wb_data' => 'nullable|array',
            'ozon_data' => 'nullable|array',
            'yandex_data' => 'nullable|array',
        ];
    }
}
