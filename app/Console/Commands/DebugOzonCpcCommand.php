<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\Ozon\OzonPerformanceApiService;
use App\Services\SellicoApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Диагностика per-SKU CPC (клики/CTR/ДРР) из Ozon Performance API.
 *
 * Зачем: в юнит-экономике рекламные клики/CTR на товар = 0, источник CPC = FALLBACK.
 * Причина — эндпоинт /api/client/statistics/campaign/product возвращает агрегаты
 * кампаний без товарного SKU. Эта команда снимает СЫРОЙ ответ Ozon (CSV + JSON +
 * асинхронный отчёт), чтобы по реальной форме данных понять корректный путь.
 *
 * Ничего не чинит и не пишет в БД — только дамп в консоль и storage/logs/ozon-cpc-debug.json.
 *
 * Запуск (боевые ключи проще всего передать опциями):
 *   php artisan ozon:debug-cpc 17 2026-05-04 2026-06-02 --client-id=XXX --client-secret=YYY
 *   php artisan ozon:debug-cpc 17 2026-05-04 2026-06-02          # креды из локальной интеграции/Sellico
 *   php artisan ozon:debug-cpc 17 2026-05-04 2026-06-02 --campaigns=3
 */
class DebugOzonCpcCommand extends Command
{
    protected $signature = 'ozon:debug-cpc
        {integrationId : ID интеграции Ozon}
        {dateFrom : Начало периода Y-m-d}
        {dateTo : Конец периода Y-m-d}
        {--client-id= : Performance client_id (переопределяет креды интеграции)}
        {--client-secret= : Performance client_secret (переопределяет креды интеграции)}
        {--campaigns=2 : Сколько кампаний из списка сэмплировать для сырого дампа}';

    protected $description = 'Снимает сырой ответ Ozon Performance API по per-SKU статистике (клики/CTR/ДРР) для диагностики FALLBACK';

    public function handle(OzonPerformanceApiService $performanceApi, SellicoApiService $sellicoApi): int
    {
        $integrationId = (int) $this->argument('integrationId');
        $dateFrom = (string) $this->argument('dateFrom');
        $dateTo = (string) $this->argument('dateTo');
        $campaignSample = max(1, (int) $this->option('campaigns'));

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $this->error('dateFrom и dateTo должны быть в формате Y-m-d (например 2026-05-04)');

            return self::INVALID;
        }

        $credentials = $this->resolveCredentials($integrationId, $sellicoApi);
        if ($credentials === null) {
            $this->error('Не удалось получить Performance-креды. Передай --client-id и --client-secret явно.');

            return self::FAILURE;
        }

        $this->info("Диагностика CPC: integration={$integrationId}, период {$dateFrom} — {$dateTo}, сэмпл кампаний={$campaignSample}");
        $this->line('Запрос к Ozon Performance API (auth → campaigns → sync CSV/JSON → async)…');

        $dump = $performanceApi->debugCampaignProductRaw($credentials, $dateFrom, $dateTo, $campaignSample);

        $path = storage_path('logs/ozon-cpc-debug.json');
        file_put_contents(
            $path,
            json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->renderSummary($dump);
        $this->newLine();
        $this->info("Полный сырой дамп: {$path}");
        $this->line('Пришли этот файл — по нему добьём корректный per-SKU путь и фикс маппинга.');

        return ($dump['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCredentials(int $integrationId, SellicoApiService $sellicoApi): ?array
    {
        $clientId = trim((string) ($this->option('client-id') ?? ''));
        $clientSecret = trim((string) ($this->option('client-secret') ?? ''));
        if ($clientId !== '' && $clientSecret !== '') {
            $this->line('Креды: из опций командной строки.');

            return [
                'performance_api_key' => $clientId,
                'performance_client_secret' => $clientSecret,
            ];
        }

        $integration = Integration::find($integrationId);
        if ($integration === null) {
            $this->warn("Интеграция {$integrationId} не найдена локально.");
        } else {
            $local = $integration->getCredentials();
            if (! empty($local['performance_api_key']) && ! empty($local['performance_client_secret'])) {
                $this->line('Креды: из локальной интеграции (encrypted credentials).');

                return [
                    'performance_api_key' => $local['performance_api_key'],
                    'performance_client_secret' => $local['performance_client_secret'],
                ];
            }
        }

        // Фолбэк: Sellico по кэшированному workspace-токену (как в SyncUnitEconomicsCommand).
        $workspaceId = $integration?->work_space_id;
        if ($workspaceId) {
            $cachedToken = Cache::get("workspace_user_token:{$workspaceId}");
            if ($cachedToken) {
                $sellicoApi->setAccessToken($cachedToken);
            }
        }

        $remote = $sellicoApi->getIntegrationById($integrationId, $workspaceId ? (int) $workspaceId : null);
        if (($remote['success'] ?? false) && ! empty($remote['credentials']['performance_api_key'])) {
            $this->line('Креды: из Sellico (getIntegrationById).');

            return $remote['credentials'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $dump
     */
    private function renderSummary(array $dump): void
    {
        $this->newLine();
        if (! ($dump['success'] ?? false)) {
            $this->error('Дамп не успешен на стадии: ' . ($dump['stage'] ?? 'unknown'));
            $this->line(json_encode($dump['detail'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return;
        }

        $campaigns = $dump['campaigns'] ?? [];
        $this->info('Кампании: всего=' . ($campaigns['total'] ?? '?') . ', загружено=' . ($campaigns['loaded'] ?? '?'));
        $this->line('  типы (advObjectType): ' . json_encode($campaigns['types'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->line('  статусы (state): ' . json_encode($campaigns['states'] ?? [], JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->info('Синхронный /statistics/campaign/product (CSV + JSON):');
        foreach ($dump['sync_campaign_product'] ?? [] as $entry) {
            $csv = $entry['csv'] ?? [];
            $json = $entry['json'] ?? [];
            $this->line('  кампания ' . ($entry['campaign_id'] ?? '?') . ':');
            $this->line(sprintf(
                '    CSV  → HTTP %s, len=%s, товарный заголовок=%s',
                $csv['http_status'] ?? '?',
                $csv['body_length'] ?? '?',
                ($csv['looks_like_product_header'] ?? false) ? 'ДА' : 'нет'
            ));
            $this->line(sprintf(
                '    JSON → HTTP %s, rows=%s, SKU в первой строке=%s',
                $json['http_status'] ?? '?',
                $json['extracted_rows_count'] ?? '?',
                ($json['first_row_has_sku'] ?? false) ? 'ДА' : 'нет'
            ));
            if (! empty($json['first_row_keys'])) {
                $this->line('    JSON ключи строки: ' . implode(', ', $json['first_row_keys']));
            }
        }

        $async = $dump['async_statistics'] ?? null;
        $this->newLine();
        $this->info('Асинхронный POST /statistics (groupBy=NO):');
        if (! ($async['attempted'] ?? false)) {
            $this->line('  не пробовался: ' . ($async['reason'] ?? '—'));

            return;
        }
        $gen = $async['generate'] ?? [];
        $this->line('  generate → HTTP ' . ($gen['http_status'] ?? '?') . ', uuid=' . ($gen['uuid'] ?? '—'));
        $download = $async['download'] ?? null;
        if (is_array($download)) {
            $this->line(sprintf(
                '  download → HTTP %s, len=%s, zip=%s, товарный заголовок=%s',
                $download['http_status'] ?? '?',
                $download['body_length'] ?? '?',
                ($download['looks_like_zip'] ?? false) ? 'ДА' : 'нет',
                ($download['looks_like_product_header'] ?? false) ? 'ДА' : 'нет'
            ));
        } else {
            $lastState = '—';
            foreach ($async['poll'] ?? [] as $poll) {
                $lastState = $poll['state'] ?? $lastState;
            }
            $this->line('  download → не готов, последнее состояние: ' . $lastState);
        }
    }
}
