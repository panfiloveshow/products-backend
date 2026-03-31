<?php

namespace App\Http\Requests\Integration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $marketplace = $this->input('marketplace');
        
        $rules = [
            'name' => 'required|string|max:255',
            'marketplace' => 'required|string|in:wildberries,ozon,yandex_market',
            'credentials' => 'required|array',
            'is_active' => 'boolean',
            'auto_sync_enabled' => 'boolean',
            'sync_interval_hours' => 'integer|min:1|max:168',
        ];

        // Валидация credentials в зависимости от маркетплейса
        $credentialsRules = match ($marketplace) {
            'wildberries' => [
                'credentials.api_key' => 'required|string',
            ],
            'ozon' => [
                'credentials.client_id' => 'required|string',
                'credentials.api_key' => 'required|string',
            ],
            'yandex_market' => [
                'credentials.token' => 'required|string',
                'credentials.campaign_id' => 'required|string',
                'credentials.business_id' => 'nullable|string',
                'credentials.scheme' => 'nullable|string|in:FBY,FBS,DBS,EXPRESS',
            ],
            default => ['credentials' => 'required|array'],
        };

        return array_merge($rules, $credentialsRules);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название интеграции обязательно',
            'marketplace.required' => 'Маркетплейс обязателен',
            'marketplace.in' => 'Неподдерживаемый маркетплейс',
            'credentials.required' => 'Credentials обязательны',
            'credentials.api_key.required' => 'API ключ обязателен',
            'credentials.client_id.required' => 'Client ID обязателен',
            'credentials.token.required' => 'Токен обязателен',
            'credentials.campaign_id.required' => 'Campaign ID обязателен',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Ошибка валидации',
            'errors' => $validator->errors(),
        ], 422));
    }
}
