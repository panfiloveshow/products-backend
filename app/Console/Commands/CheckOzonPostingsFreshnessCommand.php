<?php

namespace App\Console\Commands;

use App\Domains\Ozon\OzonMarketplace;
use App\Models\Integration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сторожок свежести постингов Ozon.
 *
 * Зачем: Ozon умеет отключать эндпоинты «тихо» — /v3/posting/fbo/list с ~29.07.2026
 * отвечал HTTP 200 с пустым result, постинги замирали, водяной знак уезжал вперёд,
 * и % выкупа неделями считался по протухшим заказам (см. фикс 5ff872b). HTTP-ошибки
 * ловятся исключением, а «валидную пустоту» может поймать только сверка с продажами.
 *
 * Логика: если у интеграции нет НИ ОДНОГО нового постинга за N дней, а аналитика
 * Ozon (/v1/analytics/data, ordered_units) за тот же период показывает заказы —
 * это алерт: постинги замерли при живых продажах.
 */
class CheckOzonPostingsFreshnessCommand extends Command
{
    protected $signature = 'ozon:postings-freshness
        {--days=3 : Сколько дней тишины в постингах считать подозрительными}';

    protected $description = 'Алерт, если постинги Ozon замерли при живых продажах в аналитике (тихая смерть эндпоинта)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $threshold = now()->subDays($days);
        $alerts = 0;
        $checked = 0;

        foreach (Integration::query()->syncable()->where('marketplace', 'ozon')->get() as $integration) {
            $lastPostingAt = DB::table('postings')
                ->where('marketplace', 'ozon')
                ->where('integration_id', $integration->id)
                ->max('in_process_at');

            // Без истории постингов — новая/пустая интеграция, сторожить нечего.
            if ($lastPostingAt === null) {
                continue;
            }

            $checked++;
            if ($lastPostingAt >= $threshold->toDateTimeString()) {
                continue; // постинги свежие
            }

            // Постинги молчат — есть ли продажи по аналитике за тот же период?
            $orderedUnits = $this->orderedUnitsSince($integration, $days);
            if ($orderedUnits === null || $orderedUnits <= 0) {
                continue; // продаж нет (или аналитика недоступна) — тишина легитимна
            }

            $alerts++;
            $context = [
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
                'last_posting_at' => (string) $lastPostingAt,
                'silent_days' => $days,
                'analytics_ordered_units' => $orderedUnits,
            ];
            Log::error('Ozon postings freshness: постинги замерли при живых продажах — проверить эндпоинты /vX/posting/*/list', $context);
            $this->error("ALERT int={$integration->id} ({$integration->name}): последний постинг {$lastPostingAt}, но аналитика показывает {$orderedUnits} заказов за {$days}д");
        }

        $this->info("Проверено интеграций: {$checked}, алертов: {$alerts}");

        return $alerts > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Заказы по аналитике Ozon за последние N дней. null — аналитика недоступна
     * (битые креды/лимит): не алертим, у протухших кредов свой пробник.
     */
    private function orderedUnitsSince(Integration $integration, int $days): ?int
    {
        try {
            $creds = $integration->resolveCredentials();
            if (empty($creds['client_id']) || empty($creds['api_key'])) {
                return null;
            }

            $marketplace = new OzonMarketplace([
                'client_id' => $creds['client_id'],
                'api_key' => $creds['api_key'],
            ], $integration);

            $response = $marketplace->getClient()->post('/v1/analytics/data', [
                'date_from' => now()->subDays($days)->format('Y-m-d'),
                'date_to' => now()->format('Y-m-d'),
                'metrics' => ['ordered_units'],
                'dimension' => ['day'],
                'filters' => [],
                'limit' => 31,
                'offset' => 0,
            ]);

            $rows = $response['result']['data'] ?? null;
            if (! is_array($rows)) {
                return null;
            }

            $units = 0;
            foreach ($rows as $row) {
                $units += (int) ($row['metrics'][0] ?? 0);
            }

            return $units;
        } catch (\Throwable $e) {
            Log::info('Ozon postings freshness: analytics недоступна', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
