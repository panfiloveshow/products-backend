<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class IndexInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:255',
            'marketplace' => 'nullable|string',
            'low_stock' => 'nullable|boolean',
            'out_of_stock' => 'nullable|boolean',
            'category' => 'nullable|string|max:200',
            'sort' => 'nullable|in:sku,name,internal_stock,marketplace_stock,sales_28_days,days_of_stock',
            'sort_order' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:200',
        ];
    }
}
