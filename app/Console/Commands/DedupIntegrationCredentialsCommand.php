<?php

namespace App\Console\Commands;

use App\Models\Integration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Санитар «зомби»-интеграций.
 *
 * Ловит один и тот же кабинет продавца, подключённый дважды: две (и более) АКТИВНЫЕ
 * интеграции одного workspace + маркетплейса с одинаковым отпечатком credentials
 * (Integration::credentialFingerprint). Такое возникает, когда старую интеграцию
 * удалили во фронте Sellico, но локальная запись products-backend осталась и
 * продолжает синкать тот же кабинет параллельно с новой (реальный случай: id=80
 * «Свежее Поле» синкал каталог рядом с живой id=81, задваивая юнит-экономику).
 *
 * Почему не integrations:reconcile: тот сверяется с ЖИВЫМ списком Sellico, а сервисный
 * аккаунт доступа к нему не имеет → команда безопасно пропускает всё. Здесь сигнал
 * чисто ЛОКАЛЬНЫЙ (совпадение ключей), Sellico не нужен.
 *
 * Действие консервативное: НЕ удаляет данные, а лишь ДЕАКТИВИРУЕТ дубликаты
 * (is_active=false, auto_sync_enabled=false) — синк-задвоение прекращается, данные
 * остаются, откат тривиален. Полная зачистка данных — отдельно (руками/reconcile).
 *
 * Оставляем самую свежую по created_at (тай-брейк: последний last_sync_at, затем max id).
 * По умолчанию dry-run; --apply чтобы реально деактивировать.
 */
class DedupIntegrationCredentialsCommand extends Command
{
    protected $signature = 'integrations:dedup-credentials
        {--apply : Реально деактивировать дубликаты (по умолчанию только показывает)}
        {--workspace= : Ограничить конкретным work_space_id}';

    protected $description = 'Деактивирует дубликаты интеграций (один кабинет подключён дважды) по отпечатку credentials';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $query = Integration::query()->where('is_active', true);
        if ($ws = $this->option('workspace')) {
            $query->where('work_space_id', (int) $ws);
        }
        $active = $query->get();

        // Группируем по (workspace, marketplace, отпечаток). Записи без отпечатка
        // (нет ключевых полей) пропускаем — сравнивать нечего.
        $groups = [];
        foreach ($active as $integration) {
            $fp = $integration->credentialFingerprint();
            if ($fp === null) {
                continue;
            }
            $key = ($integration->work_space_id ?? 'null').'|'.$integration->marketplace.'|'.$fp;
            $groups[$key][] = $integration;
        }

        $dupGroups = array_filter($groups, static fn (array $g): bool => count($g) > 1);

        if ($dupGroups === []) {
            $this->info('Дубликатов по отпечатку credentials не найдено.');

            return self::SUCCESS;
        }

        $deactivated = 0;
        foreach ($dupGroups as $group) {
            // Сортируем «желаемый первым»: свежая created_at → свежий last_sync_at → больший id.
            usort($group, static function (Integration $a, Integration $b): int {
                return [$b->created_at, $b->last_sync_at, $b->id] <=> [$a->created_at, $a->last_sync_at, $a->id];
            });
            $keep = array_shift($group);

            $this->warn(sprintf(
                'Дубль кабинета: ws=%s %s — оставляем id=%s «%s», деактивируем %d:',
                $keep->work_space_id ?? 'null',
                $keep->marketplace,
                $keep->id,
                $keep->name,
                count($group),
            ));

            foreach ($group as $dup) {
                $this->line(sprintf('    • id=%s «%s» (создана %s, last_sync %s)',
                    $dup->id, $dup->name, $dup->created_at, $dup->last_sync_at ?? '—'));

                if (! $apply) {
                    continue;
                }

                $dup->update(['is_active' => false, 'auto_sync_enabled' => false]);
                $deactivated++;
                Log::warning('Integration dedup: duplicate credential integration deactivated', [
                    'id' => $dup->id,
                    'name' => $dup->name,
                    'marketplace' => $dup->marketplace,
                    'work_space_id' => $dup->work_space_id,
                    'kept_id' => $keep->id,
                ]);
            }
        }

        $this->line('');
        $this->info($apply
            ? "Готово: деактивировано дубликатов {$deactivated}."
            : 'dry-run — ничего не изменено; добавьте --apply.');

        return self::SUCCESS;
    }
}
