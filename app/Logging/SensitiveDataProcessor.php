<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog-processor, маскирующий чувствительные поля в context / extra.
 *
 * Закрывает класс утечек, найденных security-аудитом:
 * - Log::info(['token' => ...])
 * - Log::info($httpResponse) с access_token в body
 * - Log::info('body' => $response->body()) с Client-Id / Api-Key
 *
 * Алгоритм:
 *   1. Для массивов context/extra рекурсивно маскируем значения полей
 *      с «опасными» именами (token, password, api_key, authorization и т.п.)
 *      и значения-URL с query-string `token=`/`api_key=`/`password=`.
 *   2. Для строковых значений сканируем типовые Bearer/apikey-паттерны.
 *
 * Не трогаем сам message — там логируются только формулировки.
 */
class SensitiveDataProcessor implements ProcessorInterface
{
    private const SENSITIVE_KEYS = [
        'token',
        'tokens',
        'access_token',
        'refresh_token',
        'bearer',
        'bearer_token',
        'authorization',
        'api_key',
        'api-key',
        'apikey',
        'client_secret',
        'client-secret',
        'secret',
        'password',
        'pwd',
        'passwd',
        'x-api-key',
        'x-sellico-token',
        'x-token',
        'service_token',
        'sellico_token',
        '_sellico_token',
        'credentials',
        'cookie',
        'set-cookie',
    ];

    private const MASK = '<REDACTED>';

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->scrubArray($record->context);
        $extra = $this->scrubArray($record->extra);

        return $record->with(context: $context, extra: $extra);
    }

    private function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            $lowerKey = is_string($key) ? strtolower($key) : '';

            if ($lowerKey !== '' && $this->isSensitiveKey($lowerKey)) {
                $data[$key] = self::MASK;
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->scrubArray($value);
                continue;
            }

            if (is_string($value)) {
                $data[$key] = $this->scrubString($value);
                continue;
            }

            if (is_object($value) && method_exists($value, 'toArray')) {
                try {
                    $asArray = $value->toArray();
                    if (is_array($asArray)) {
                        $data[$key] = $this->scrubArray($asArray);
                        continue;
                    }
                } catch (\Throwable) {
                    // оставляем как есть
                }
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $lowerKey): bool
    {
        if (in_array($lowerKey, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($lowerKey, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function scrubString(string $value): string
    {
        // Laravel Sanctum / Bearer tokens
        $value = preg_replace('/\b[0-9]+\|[a-zA-Z0-9]{30,}\b/', self::MASK, $value) ?? $value;
        // query-параметры с токенами/паролями
        $value = preg_replace('/((?:token|api_key|access_token|password|secret)=)[^&\s"\']+/i', '$1' . self::MASK, $value) ?? $value;
        // Authorization: Bearer <…>
        $value = preg_replace('/(Authorization:\s*Bearer\s+)[^\s"\']+/i', '$1' . self::MASK, $value) ?? $value;

        return $value;
    }
}
