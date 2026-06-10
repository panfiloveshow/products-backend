<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\SellicoApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Сверяет локальное зеркало интеграций с живым списком Sellico (источник истины)
 * и удаляет осиротевшие записи — те, что были удалены в Sellico, но «зависли»
 * в продуктовом бэкенде вместе с товарами, кэшем юнит-экономики и т.д.
 *
 * Контекст бага: фронт берёт список интеграций напрямую из Sellico
 * (`/workspaces/{ws}/integrations`). Локальная таблица `integrations` — это
 * зеркало, которое лениво ДОЗАПОЛНЯЕТСЯ при синке (IntegrationAccessService),
 * но НИКОГДА не сверяется на удаление. Поэтому удалённые в Sellico интеграции
 * остаются локально навсегда. Эта команда закрывает разрыв.
 *
 * БЕЗОПАСНОСТЬ (fail-closed): удаление выполняется ТОЛЬКО если из Sellico получен
 * достоверный НЕПУСТОЙ список. Любая ошибка авторизации / сети / пустой ответ →
 * workspace пропускается без единого удаления. По умолчанию — dry-run.
 */
class ReconcileIntegrationsCommand extends Command
{
    protected $signature = 'integrations:reconcile
        {--workspace= : Конкретный work_space_id (по умолчанию — все из локальной таблицы)}
        {--token= : Пользовательский Sellico-токен (Bearer) для эндпоинта /workspaces/{ws}/integrations}
        {--apply : Реально удалить сирот (по умолчанию только показывает, ничего не трогает)}
        {--force : Снять предохранитель при количестве сирот больше порога}';

    protected $description = 'Сверяет локальные интеграции с живым списком Sellico и удаляет осиротевшие (удалённые в Sellico) вместе со всеми их данными';

    /** Если сирот в одном workspace больше — требуем --force (защита от сноса всего при глюке авторизации). */
    private const MAX_DELETE_WITHOUT_FORCE = 5;

    public function handle(SellicoApiService $sellico): int
    {
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $token = $this->option('token') ?: null;

        $workspaceOpt = $this->option('workspace');
        $workspaceIds = $workspaceOpt !== null
            ? [(int) $workspaceOpt]
            : Integration::query()
                ->whereNotNull('work_space_id')
                ->distinct()
                ->pluck('work_space_id')
                ->map(fn ($v) => (int) $v)
                ->filter()
                ->sort()
                ->values()
                ->all();

        if (empty($workspaceIds)) {
            $this->warn('Нет локальных интеграций с work_space_id — нечего сверять.');

            return self::SUCCESS;
        }

        $tables = $this->integrationIdTables();
        $totalOrphans = 0;
        $totalDeletedRows = 0;

        foreach ($workspaceIds as $ws) {
            $this->line("=== workspace {$ws} ===");

            $liveIds = $this->fetchLiveIntegrationIds($sellico, $ws, $token);

            if ($liveIds === null) {
                $this->warn('  ⚠ Не удалось получить достоверный список из Sellico — workspace пропущен (удалений нет).');
                continue;
            }
            if (empty($liveIds)) {
                $this->warn("  ⚠ Sellico вернул ПУСТОЙ список для workspace {$ws} — пропуск из осторожности (вероятна ошибка авторизации).");
                continue;
            }

            $local = Integration::where('work_space_id', $ws)->get(['id', 'name', 'marketplace']);
            $orphans = $local->reject(fn ($i) => in_array((int) $i->id, $liveIds, true))->values();

            if ($orphans->isEmpty()) {
                $this->info("  ✓ Сирот нет (локально {$local->count()}, живых ".count($liveIds).').');
                continue;
            }

            $this->warn("  Найдено сирот: {$orphans->count()} (живых в Sellico: ".count($liveIds).')');
            foreach ($orphans as $o) {
                $footprint = $this->footprint($tables, (int) $o->id);
                $this->line("    • id={$o->id} «{$o->name}» ({$o->marketplace}) — строк: ".array_sum($footprint));
                foreach ($footprint as $t => $n) {
                    if ($n > 0) {
                        $this->line("        {$t}: {$n}");
                    }
                }
            }

            $totalOrphans += $orphans->count();

            if (! $apply) {
                $this->line('  (dry-run — ничего не удалено; добавьте --apply)');
                continue;
            }

            if ($orphans->count() > self::MAX_DELETE_WITHOUT_FORCE && ! $force) {
                $this->error('  ⛔ Сирот '.$orphans->count().' > порога '.self::MAX_DELETE_WITHOUT_FORCE.' — подозрительно. Проверьте и запустите с --force.');
                continue;
            }

            foreach ($orphans as $o) {
                try {
                    $deleted = $this->deleteIntegration($tables, (int) $o->id);
                    $sum = array_sum($deleted);
                    $totalDeletedRows += $sum;
                    $this->info("    ✓ удалена id={$o->id} «{$o->name}» (строк: {$sum})");
                    Log::warning('Integration reconcile: orphan removed', [
                        'id' => $o->id,
                        'name' => $o->name,
                        'workspace' => $ws,
                        'deleted' => $deleted,
                    ]);
                } catch (\Throwable $e) {
                    $this->error("    ✗ не удалось удалить id={$o->id}: ".$e->getMessage());
                    Log::error('Integration reconcile: delete failed', [
                        'id' => $o->id,
                        'workspace' => $ws,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->line('');
        $this->info("Итог: сирот найдено {$totalOrphans}".($apply ? ", удалено строк {$totalDeletedRows}" : ' (dry-run, без изменений)'));

        return self::SUCCESS;
    }

    /**
     * Живой список integration_id из Sellico, либо null при недостоверном ответе (fail-closed).
     *
     * @return array<int>|null
     */
    private function fetchLiveIntegrationIds(SellicoApiService $sellico, int $ws, ?string $token): ?array
    {
        // Путь 1 (ручной запуск): пользовательский токен → тот же эндпоинт, что у фронта.
        if ($token) {
            try {
                $base = config('services.sellico.base_url') ?? 'https://sellico.ru/api';
                $r = Http::withToken($token)->acceptJson()->timeout(15)->get("{$base}/workspaces/{$ws}/integrations");
                if (! $r->successful()) {
                    return null;
                }
                $j = $r->json();
                $arr = is_array($j) ? ($j['data'] ?? $j) : null;

                return is_array($arr) ? $this->pluckIds($arr) : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Путь 2 (крон): сервисный аккаунт через SellicoApiService.
        // Требует, чтобы Sellico признавал сервисный аккаунт для /get-integrations/{ws}.
        try {
            $res = $sellico->getIntegrations($ws);
            if (! ($res['success'] ?? false)) {
                return null;
            }
            $list = $res['integrations'] ?? null;
            $arr = is_array($list) ? ($list['data'] ?? $list) : null;

            return is_array($arr) ? $this->pluckIds($arr) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param  array<mixed>  $items
     * @return array<int>
     */
    private function pluckIds(array $items): array
    {
        return collect($items)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Таблицы с колонкой integration_id (кроме самой `integrations`), из information_schema —
     * чтобы покрытие не отставало от миграций.
     *
     * @return array<string>
     */
    private function integrationIdTables(): array
    {
        $rows = DB::select(
            "SELECT table_name FROM information_schema.columns
             WHERE column_name = 'integration_id' AND table_schema = 'public'
             ORDER BY table_name"
        );

        $tables = array_map(fn ($r) => $r->table_name, $rows);

        // `products` удаляем ПОСЛЕДНЕЙ среди integration_id-таблиц: на неё ссылаются
        // дочерние по product_id (FK), их надо снести раньше.
        $tables = array_values(array_filter($tables, fn ($t) => $t !== 'products' && $t !== 'integrations'));
        $tables[] = 'products';

        return $tables;
    }

    /**
     * @param  array<string>  $tables
     * @return array<string,int>
     */
    private function footprint(array $tables, int $id): array
    {
        $out = [];
        foreach ($tables as $t) {
            try {
                $out[$t] = DB::table($t)->where('integration_id', $id)->count();
            } catch (\Throwable $e) {
                // тип колонки несовместим / таблицы нет — пропускаем
            }
        }
        $out['integrations'] = (int) Integration::whereKey($id)->count();

        return $out;
    }

    /**
     * Удаление в одной транзакции: дочерние по integration_id → products → сама запись.
     *
     * @param  array<string>  $tables
     * @return array<string,int>
     */
    private function deleteIntegration(array $tables, int $id): array
    {
        $deleted = [];
        DB::transaction(function () use ($tables, $id, &$deleted) {
            foreach ($tables as $t) {
                $deleted[$t] = DB::table($t)->where('integration_id', $id)->delete();
            }
            $deleted['integrations'] = DB::table('integrations')->where('id', $id)->delete();
        });

        return $deleted;
    }
}
