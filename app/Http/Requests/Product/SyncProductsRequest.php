<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\BaseFormRequest;

class SyncProductsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $marketplace = $this->route('marketplace');
        
        // Если передан integration_id, credentials не нужны
        if ($this->has('integration_id')) {
            return [
                'integration_id' => 'required|integer|exists:integrations,id',
            ];
        }

        // Иначе требуем credentials
        return match ($marketplace) {
            'wildberries' => [
                'api_key' => 'required|string',
            ],
            'ozon' => [
                'client_id' => 'required|string',
                'api_key' => 'required|string',
            ],
            'yandex' => [
                'token' => 'required|string',
                'campaign_id' => 'required|string',
                'business_id' => 'nullable|string',
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'integration_id.exists' => 'Интеграция не найдена',
            'api_key.required' => 'API ключ обязателен',
            'client_id.required' => 'Client ID обязателен',
            'token.required' => 'Токен обязателен',
            'campaign_id.required' => 'Campaign ID обязателен',
        ];
    }
}
