<?php

namespace App\Http\Requests\Uzum;

use App\Http\Requests\BaseFormRequest;

/**
 * Валидация payload расширения Uzum (ТЗ §15, §17.1).
 * Backend-аналог Zod-схемы на стороне расширения.
 */
class IngestExtensionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'marketplace' => 'sometimes|in:uzum',
            'shop_id' => 'nullable|string|max:100',
            'payload_type' => 'required|in:products,moderation,stocks,orders,finance,ads',
            'collected_at' => 'nullable|date',
            'items' => 'required|array',
            'extension' => 'nullable|array',
            'extension.version' => 'nullable|string|max:30',
            'extractor_version' => 'nullable|string|max:30',
        ];
    }
}
