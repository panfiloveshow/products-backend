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
                'client_id' => 'nullable|string|required_with:api_key',
                'api_key' => 'nullable|string|required_without_all:oauth_access_token,access_token',
                'oauth_access_token' => 'nullable|string|required_without_all:api_key,access_token',
                'access_token' => 'nullable|string',
            ],
            'yandex_market' => [
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
            'oauth_access_token.required_without_all' => 'Укажите OAuth access token или API key Ozon',
            'token.required' => 'Токен обязателен',
            'campaign_id.required' => 'Campaign ID обязателен',
        ];
    }
}
