<?php

namespace App\Services\Ozon;

use App\Domains\Ozon\Api\OzonClient;
use App\Models\Integration;

/**
 * Каноническая проверка credentials Ozon для UI, readiness и фоновых синков.
 */
class OzonCredentialHealthService
{
    /**
     * Локальная оценка без внешнего запроса и без изменения БД.
     *
     * @return array<string, mixed>
     */
    public function assess(Integration $integration): array
    {
        if ($integration->marketplace !== 'ozon') {
            return [
                'status' => 'not_applicable',
                'health' => 'not_applicable',
                'usable' => true,
                'severity' => 'none',
                'message' => 'Проверка credentials Ozon не требуется.',
            ];
        }

        $credentials = $integration->resolveCredentials();
        $oauthToken = $credentials['oauth_access_token'] ?? $credentials['access_token'] ?? null;
        $hasOAuth = is_string($oauthToken) && trim($oauthToken) !== '';
        $hasApiKey = trim((string) ($credentials['client_id'] ?? '')) !== ''
            && trim((string) ($credentials['api_key'] ?? '')) !== '';

        if (! $hasOAuth && ! $hasApiKey) {
            return [
                'status' => 'missing',
                'health' => 'missing',
                'usable' => false,
                'severity' => 'critical',
                'credential_type' => $integration->credential_type,
                'message' => 'Не настроены OAuth token или пара Client-Id/API-Key Ozon.',
            ];
        }

        $expiresAt = $integration->credentials_expires_at;
        $daysUntilExpiry = $expiresAt ? now()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false) : null;

        if ($expiresAt && $expiresAt->isPast()) {
            return [
                'status' => 'expired',
                'health' => 'expired',
                'usable' => false,
                'severity' => 'critical',
                'credential_type' => $hasOAuth ? 'oauth' : 'api_key',
                'expires_at' => $expiresAt->toIso8601String(),
                'days_until_expiry' => $daysUntilExpiry,
                'message' => 'Срок действия credentials Ozon истёк. Переподключите кабинет.',
            ];
        }

        if ($daysUntilExpiry !== null && $daysUntilExpiry <= 30) {
            $threshold = collect([1, 7, 14, 30])
                ->first(static fn (int $value): bool => $daysUntilExpiry <= $value) ?? 30;

            return [
                'status' => 'expiring',
                'health' => "expiring_{$threshold}d",
                'usable' => true,
                'severity' => $threshold <= 7 ? 'warning' : 'notice',
                'credential_type' => $hasOAuth ? 'oauth' : 'api_key',
                'expires_at' => $expiresAt?->toIso8601String(),
                'days_until_expiry' => $daysUntilExpiry,
                'notification_threshold_days' => $threshold,
                'message' => "Credentials Ozon истекают через {$daysUntilExpiry} дн.",
            ];
        }

        $persistedHealth = (string) ($integration->credential_health ?: 'unknown');
        $persistedInvalid = in_array($persistedHealth, ['invalid', 'expired', 'missing'], true);

        return [
            'status' => $persistedInvalid ? $persistedHealth : 'valid',
            'health' => $persistedInvalid ? $persistedHealth : ($persistedHealth === 'unknown' ? 'unknown' : 'healthy'),
            'usable' => ! $persistedInvalid,
            'severity' => $persistedInvalid ? 'critical' : ($persistedHealth === 'unknown' ? 'notice' : 'none'),
            'credential_type' => $hasOAuth ? 'oauth' : 'api_key',
            'expires_at' => $expiresAt?->toIso8601String(),
            'days_until_expiry' => $daysUntilExpiry,
            'message' => $persistedInvalid
                ? 'Последняя проверка Ozon отклонила credentials.'
                : ($persistedHealth === 'unknown' ? 'Credentials ещё не проверялись живым запросом.' : 'Credentials Ozon готовы.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function check(Integration $integration, bool $probe = true): array
    {
        $result = $this->assess($integration);
        $checkedAt = now();

        // Пробуем живой запрос всегда, когда есть чем: раньше условие требовало
        // usable=true, а сохранённый invalid как раз даёт usable=false — статус
        // залипал навсегда, потому что переспросить Ozon было уже некому.
        // Пропускаем только случаи, когда пробовать нечем.
        $nothingToProbeWith = in_array($result['status'] ?? null, ['missing', 'expired'], true);

        if ($probe && $integration->marketplace === 'ozon' && ! $nothingToProbeWith) {
            try {
                // /v3/product/list принимает фильтр видимости и не требует
                // идентификаторов товара. Родственный /v3/product/info/list
                // отвечает 400 «use either offer_id or product_id or sku»,
                // и раньше это принималось за отзыв ключей.
                $response = OzonClient::fromIntegration($integration)->post(
                    '/v3/product/list',
                    ['filter' => ['visibility' => 'ALL'], 'limit' => 1]
                );

                $httpStatus = (int) ($response['_http_status'] ?? 0);
                $rejectedByOzon = in_array($httpStatus, [401, 403], true);
                $probeFailed = $response === null || ($response['_error'] ?? false);

                if ($rejectedByOzon) {
                    $result = array_merge($result, [
                        'status' => 'invalid',
                        'health' => 'invalid',
                        'usable' => false,
                        'severity' => 'critical',
                        'message' => (string) (
                            $response['message']
                            ?? data_get($response, 'error.message')
                            ?? 'Ozon отклонил credentials.'
                        ),
                    ]);
                } elseif ($probeFailed) {
                    // Любая другая ошибка — проблема запроса или самого Ozon.
                    // Считать её отзывом ключей нельзя: это глушит весь синк
                    // фактов и блокирует утверждение планов.
                    $result = array_merge($result, [
                        'status' => 'unavailable',
                        'health' => 'unavailable',
                        'usable' => (bool) ($result['usable'] ?? false),
                        'severity' => 'warning',
                        'message' => 'Проверка Ozon не удалась: '.(string) (
                            $response['message']
                            ?? data_get($response, 'error.message')
                            ?? 'неизвестная ошибка'
                        ),
                    ]);
                } elseif (($result['status'] ?? null) !== 'expiring') {
                    $result = array_merge($result, [
                        'status' => 'valid',
                        'health' => 'healthy',
                        'usable' => true,
                        'severity' => 'none',
                        'message' => 'Credentials Ozon проверены живым запросом.',
                    ]);
                }
            } catch (\Throwable $e) {
                // Ошибка сети не равна невалидному ключу: не заставляем пользователя
                // переподключать кабинет из-за временной недоступности Ozon.
                $result = array_merge($result, [
                    'status' => 'unavailable',
                    'health' => 'unavailable',
                    'usable' => (bool) ($result['usable'] ?? false),
                    'severity' => 'warning',
                    'message' => 'Ozon временно недоступен: '.$e->getMessage(),
                ]);
            }
        }

        $integration->forceFill([
            'last_validation_at' => $checkedAt,
            'last_validation_status' => $result['status'],
            'last_validation_error' => ($result['status'] ?? null) === 'valid'
                ? null
                : $result['message'],
            'credential_health' => $result['health'],
            'credential_last_checked_at' => $checkedAt,
        ])->save();

        return array_merge($result, [
            'integration_id' => (int) $integration->id,
            'checked_at' => $checkedAt->toIso8601String(),
        ]);
    }
}
