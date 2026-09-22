<?php

namespace App\Domains\Ozon\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Единая точка вызова POST /v1/analytics/data с ограничениями из спеки Ozon:
 * - не чаще 1 раза в минуту для всех продавцов — пауза ≥61 с между запросами
 *   одного Client-Id (метка в Cache, общая для процессов);
 * - без Premium Plus/Pro — 50 запросов в сутки, данные за последние 3 месяца,
 *   метрики только revenue и ordered_units;
 * - на 429 не ретраим (лимит минутный/суточный — повтор только сжигает квоту).
 *
 * Строки отдаются с метриками по именам: при откате на базовые метрики
 * порядок в `metrics[]` меняется, индексы врут.
 */
class AnalyticsDataClient
{
    public const ENDPOINT = '/v1/analytics/data';

    public const BASIC_METRICS = ['revenue', 'ordered_units'];

    private const MIN_INTERVAL_SECONDS = 61;

    private const PREMIUM_TTL_SECONDS = 86400;

    public function __construct(
        private OzonClient $client
    ) {}

    /**
     * Что известно о подписке по этому Client-Id: true / false / null (не проверяли за сутки).
     */
    public function premiumKnown(): ?bool
    {
        $state = Cache::get($this->premiumCacheKey());

        return is_bool($state) ? $state : null;
    }

    public function rememberPremium(bool $premium): void
    {
        Cache::put($this->premiumCacheKey(), $premium, self::PREMIUM_TTL_SECONDS);
    }

    /**
     * Запрос отчёта с пагинацией offset (не больше $maxPages страниц).
     *
     * @param  bool|null  $premium  подписка известна вызывающему (true/false); null — берём из Cache.
     *   Премиум-метрики уходят в запрос только при true.
     * @param  bool  $basicFallback  при отказе 400/403 на премиум-метриках повторить с базовыми.
     * @return array{rows: list<array{dimensions: array, metrics: array<string, float>}>, metrics: list<string>, status: string}
     *   status: ok | premium_required | rate_limited | error
     */
    public function fetch(array $body, int $maxPages = 1, ?bool $premium = null, bool $basicFallback = true): array
    {
        $premium ??= $this->premiumKnown();
        $metrics = array_values((array) ($body['metrics'] ?? []));
        if ($premium !== true) {
            $metrics = array_values(array_intersect($metrics, self::BASIC_METRICS));
            $body = $this->clampToThreeMonths($body);
        }
        if ($metrics === []) {
            return ['rows' => [], 'metrics' => [], 'status' => 'premium_required'];
        }

        $body = $this->withMetrics($body, $metrics);
        $limit = max(1, (int) ($body['limit'] ?? 1000));
        $body['offset'] = (int) ($body['offset'] ?? 0);
        $rows = [];

        for ($page = 0; $page < $maxPages; $page++) {
            if (! $this->throttle()) {
                // Веб-запрос: минутное окно Client-Id ещё не прошло — не держим HTTP до таймаута.
                return ['rows' => $rows, 'metrics' => $metrics, 'status' => 'rate_limited'];
            }
            $response = $this->client->post(self::ENDPOINT, $body, false, false);

            if (! is_array($response) || ! empty($response['_error'])) {
                $status = (int) ($response['_http_status'] ?? 0);
                $context = [
                    'status' => $status ?: null,
                    'metrics' => $metrics,
                    'offset' => $body['offset'],
                    'message' => $response['message'] ?? $response['error']['message'] ?? null,
                ];

                if ($status === 429) {
                    Log::warning('Ozon /v1/analytics/data: лимит запросов (429), выгрузку прекращаем', $context);

                    return ['rows' => $rows, 'metrics' => $metrics, 'status' => 'rate_limited'];
                }

                $premiumMetrics = array_diff($metrics, self::BASIC_METRICS);
                if (in_array($status, [400, 403], true) && $premiumMetrics !== []) {
                    // Премиум-метрики отклонены — подписки нет; сутки их не просим.
                    $this->rememberPremium(false);
                    Log::info('Ozon /v1/analytics/data: премиум-метрики недоступны', $context);

                    $basic = array_values(array_intersect($metrics, self::BASIC_METRICS));
                    if (! $basicFallback || $basic === [] || $rows !== []) {
                        return ['rows' => $rows, 'metrics' => $metrics, 'status' => 'premium_required'];
                    }
                    $metrics = $basic;
                    $body = $this->withMetrics($this->clampToThreeMonths($body), $metrics);
                    $page--;

                    continue;
                }

                Log::warning('Ozon /v1/analytics/data: ошибка', $context);

                return ['rows' => $rows, 'metrics' => $metrics, 'status' => 'error'];
            }

            $data = $response['result']['data'] ?? [];
            foreach ($data as $row) {
                $values = array_values((array) ($row['metrics'] ?? []));
                $named = [];
                foreach ($metrics as $i => $name) {
                    $named[$name] = (float) ($values[$i] ?? 0);
                }
                $rows[] = ['dimensions' => $row['dimensions'] ?? [], 'metrics' => $named];
            }

            if (count($data) < $limit) {
                break;
            }
            $body['offset'] += $limit;
        }

        return ['rows' => $rows, 'metrics' => $metrics, 'status' => 'ok'];
    }

    private function withMetrics(array $body, array $metrics): array
    {
        $body['metrics'] = $metrics;
        // Сортировка по выброшенной метрике — ошибка запроса.
        $body['sort'] = array_values(array_filter(
            (array) ($body['sort'] ?? []),
            fn ($sort) => in_array($sort['key'] ?? null, $metrics, true)
        ));
        if ($body['sort'] === []) {
            unset($body['sort']);
        }

        return $body;
    }

    /**
     * Без подписки Ozon отдаёт данные только за последние 3 месяца.
     */
    private function clampToThreeMonths(array $body): array
    {
        $minFrom = now()->subMonthsNoOverflow(3)->toDateString();
        if (isset($body['date_from']) && substr((string) $body['date_from'], 0, 10) < $minFrom) {
            $body['date_from'] = $minFrom;
        }

        return $body;
    }

    /**
     * Держит паузу ≥61 с между запросами Client-Id. В веб-запросе не спит: false,
     * если окно ещё не прошло (очереди и крон — консоль — ждут).
     */
    private function throttle(): bool
    {
        $key = 'ozon:analytics-data:last:'.$this->client->getClientCacheKey();
        $last = Cache::get($key);
        if (is_numeric($last)) {
            $wait = self::MIN_INTERVAL_SECONDS - (now()->getTimestamp() - (int) $last);
            if ($wait > 0) {
                if (! app()->runningInConsole()) {
                    return false;
                }
                Sleep::for(min($wait, self::MIN_INTERVAL_SECONDS))->seconds();
            }
        }
        Cache::put($key, now()->getTimestamp(), self::MIN_INTERVAL_SECONDS * 2);

        return true;
    }

    private function premiumCacheKey(): string
    {
        return 'ozon:analytics-data:premium:'.$this->client->getClientCacheKey();
    }
}
