<?php

namespace App\Http\Requests\Integration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $marketplace = $this->input('marketplace');
        
        $rules = [
            'name' => 'sometimes|string|max:255',
            'marketplace' => 'sometimes|string|in:wildberries,ozon,yandex_market',
            'credentials' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
            'auto_sync_enabled' => 'sometimes|boolean',
            'sync_interval_hours' => 'sometimes|integer|min:1|max:168',
        ];

        // Валидация credentials в зависимости от маркетплейса (опционально при обновлении)
        if ($marketplace && $this->has('credentials')) {
            $credentialsRules = match ($marketplace) {
                'wildberries' => [
                    'credentials.api_key' => 'sometimes|string',
                ],
                'ozon' => [
                    'credentials.client_id' => 'sometimes|string',
                    'credentials.api_key' => 'sometimes|string',
                ],
                'yandex_market' => [
                    'credentials.token' => 'sometimes|string',
                    'credentials.campaign_id' => 'sometimes|string',
                    'credentials.business_id' => 'nullable|string',
                    'credentials.scheme' => 'nullable|string|in:FBY,FBS,DBS,EXPRESS',
                ],
                default => [],
            };
            $rules = array_merge($rules, $credentialsRules);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'marketplace.in' => 'Неподдерживаемый маркетплейс',
            'sync_interval_hours.min' => 'Интервал синхронизации должен быть не менее 1 часа',
            'sync_interval_hours.max' => 'Интервал синхронизации должен быть не более 168 часов (7 дней)',
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
