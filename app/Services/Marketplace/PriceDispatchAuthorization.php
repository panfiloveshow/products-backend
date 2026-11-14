<?php

namespace App\Services\Marketplace;

use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Internal dispatch barrier only. Never a personal financial approval. */
final class PriceDispatchAuthorization
{
    public static function isWbPriceHost(string $baseUrl): bool
    {
        return strtolower((string) parse_url($baseUrl, PHP_URL_HOST)) === 'discounts-prices-api.wildberries.ru';
    }

    public static function isWbMutation(string $baseUrl, string $endpoint): bool
    {
        if (! self::isWbPriceHost($baseUrl)) return false;
        $path = rawurldecode((string) parse_url($endpoint, PHP_URL_PATH));
        return $path !== '/api/v2/list/goods/filter';
    }

    public static function isOzonMutation(string $endpoint): bool
    {
        $path = rawurldecode((string) parse_url($endpoint, PHP_URL_PATH));
        return (bool) preg_match('~^/v[0-9]+/(?:product/import(?:/prices)?|actions/(?:products|hotsales)/(?:activate|deactivate))/*$~iD', $path);
    }

    public static function wbAccount(#[\SensitiveParameter] string $token): string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || strlen($parts[1]) > 32768 || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $parts[1])) {
            throw new DomainException('Не подтверждена привязка ключа WB для отправки цены.');
        }
        $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);
        $sid = is_array($payload) ? ($payload['sid'] ?? null) : null;
        if (! is_string($sid) || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $sid)) {
            throw new DomainException('Не подтверждена привязка ключа WB для отправки цены.');
        }
        return 'wb:'.hash('sha256', "wb\0".$sid);
    }

    public static function ozonAccount(string $clientId, #[\SensitiveParameter] string $apiKey, #[\SensitiveParameter] string $accessToken): string
    {
        if (trim($clientId) === '' || $apiKey === '' || $accessToken !== '') {
            throw new DomainException('Для отправки цены требуется проверяемая привязка Ozon Seller Client ID и API-ключа.');
        }
        return 'ozon:'.hash('sha256', 'ozon-seller-price:'.trim($clientId));
    }

    public static function encode(array|object $body): string
    {
        return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The caller starts its one native request immediately after this returns. */
    public function authorize(string $market, string $account, string $endpoint, string $nativeBody): array
    {
        // Retired senders record no admission: they must not create a new
        // account-wide hold in the shared executor. No network request at all.
        if (config('services.price_dispatch.legacy_enabled', false) !== true) {
            throw PriceDispatchDenied::fromServiceError('price_dispatch_retired');
        }
        $url = (string) config('services.price_dispatch.url', '');
        $secret = (string) config('services.price_dispatch.secret', '');
        $parts = parse_url($url);
        if (strlen($secret) < 32 || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '/internal/v1/prices/dispatch-authorizations') {
            throw new DomainException('Не настроена проверка общей остановки отправки цен.');
        }
        $request = ['version' => 'legacy_price_dispatch_v1', 'origin' => 'products', 'command_id' => (string) Str::uuid(),
            'sent_at' => time(), 'marketplace' => $market, 'method' => 'POST', 'account_key' => $account,
            'endpoint' => $endpoint, 'payload_sha256' => hash('sha256', $nativeBody)];
        $wire = self::encode($request);
        $signature = 'v1='.hash_hmac('sha256', "legacy-price-dispatch-v1\n".$wire, $secret);
        $started = hrtime(true);
        $wallStarted = microtime(true);
        try {
            $response = Http::acceptJson()->connectTimeout(1)->timeout(3)->withOptions(['allow_redirects' => false])
                ->withHeaders(['X-Sellico-Price-Authorization' => $signature])
                ->withBody($wire, 'application/json')->post($url);
            $grant = $response->json('data');
        } catch (\Throwable) {
            throw new DomainException('Разрешение на отправку цены не получено. Запрос к площадке не отправлен.');
        }
        if (! $response->successful()) {
            if ($response->status() === 409) {
                $denial = PriceDispatchDenied::fromServiceError($response->json('error'));
                if ($denial !== null) throw $denial;
            }
            throw new DomainException($response->status() === 409
                ? 'Отправка через прежний исполнитель остановлена или требует нового личного согласования цены.'
                : 'Проверка общей остановки цены недоступна. Запрос к площадке не отправлен.');
        }
        if (! is_array($grant) || ($grant['version'] ?? null) !== 'legacy_price_dispatch_grant_v1'
            || ($grant['authorized'] ?? null) !== true || ($grant['origin'] ?? null) !== 'products'
            || ($grant['scope'] ?? null) !== 'one_legacy_price_request' || ($grant['control_version'] ?? null) !== 0
            || ($grant['valid_for_ms'] ?? null) !== 5000
            || ($grant['command_id'] ?? null) !== $request['command_id'] || ($grant['marketplace'] ?? null) !== $market
            || ($grant['method'] ?? null) !== 'POST' || ($grant['endpoint'] ?? null) !== $endpoint
            || ($grant['payload_sha256'] ?? null) !== $request['payload_sha256']
            || ! $this->validLifetime($grant['issued_at'] ?? null, $grant['expires_at'] ?? null)
            || (hrtime(true) - $started) >= 5000000000) {
            throw new DomainException('Не подтверждено точное и действующее разрешение отправки цены.');
        }
        $grant['_local_deadline_ns'] = $started + 5000000000;
        $grant['_local_deadline_wall'] = $wallStarted + 5.0;
        return $grant;
    }

    public static function assertFresh(array $grant): void
    {
        $wall = microtime(true);
        if (! is_int($grant['_local_deadline_ns'] ?? null) || hrtime(true) >= $grant['_local_deadline_ns']
            || ! is_float($grant['_local_deadline_wall'] ?? null) || ! is_finite($grant['_local_deadline_wall'])
            || $wall >= $grant['_local_deadline_wall'] || $wall < $grant['_local_deadline_wall'] - 5.001) {
            throw new DomainException('Разрешение отправки цены истекло до начала запроса. Цена не отправлена.');
        }
    }

    private function validLifetime(mixed $issued, mixed $expires): bool
    {
        $values = [];
        foreach ([$issued, $expires] as $value) {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return false;
            try { $date = new DateTimeImmutable($value); } catch (\Throwable) { return false; }
            if (DateTimeImmutable::getLastErrors() !== false) return false;
            $values[] = (float) $date->format('U.u');
        }
        return abs($values[1] - $values[0] - 5.0) < 0.001;
    }
}
