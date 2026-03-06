<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class IndexProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'marketplace' => 'nullable|in:wildberries,ozon,yandex',
            'integration_id' => 'required|string',
            'category' => 'nullable|string|max:200',
            'brand' => 'nullable|string|max:200',
            'price_from' => 'nullable|numeric|min:0',
            'price_to' => 'nullable|numeric|min:0',
            'in_stock' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:200',
            'sort' => 'nullable|in:name,price,stock,rating,created_at',
            'sort_order' => 'nullable|in:asc,desc',
        ];
    }
}
