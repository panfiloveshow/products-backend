<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Исключение для ошибок бизнес-логики
 * 
 * Используется когда операция не может быть выполнена
 * из-за нарушения бизнес-правил (не технической ошибки).
 */
class BusinessLogicException extends Exception
{
    protected array $errors = [];

    public function __construct(string $message = '', array $errors = [], int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    /**
     * Получить массив ошибок
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Рендер исключения в HTTP ответ
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ], $this->getCode());
    }
}
