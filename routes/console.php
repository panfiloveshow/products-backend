<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Все синхронизации запускаются пользователем вручную через UI.
// Scheduled jobs отключены — нет фоновой нагрузки на API маркетплейсов.
//
// Exception: Locality Engine — единственный источник правды для расчёта
// non-local переплат и рекомендаций поставок. Включи через ENV LOCALITY_SCHEDULE=true,
// запусти `php artisan schedule:work` (или systemd cron по артизану).

// Гигиена моста Uzum-расширения: команды живут 10 минут (expires_at), но DELETE их
// никто не делал — таблица росла бесконечно. Локальный DELETE, API маркетплейсов не трогает.
\Illuminate\Support\Facades\Schedule::call(function () {
    \App\Models\UzumExtensionCommand::query()
        ->where('expires_at', '<', now()->subHour())
        ->delete();
})->hourly()->name('uzum.extension-commands-cleanup');

// NB: читаем через config(), а не env() — чтобы флаг работал после config:cache.
if (config('locality.schedule_enabled', false)) {
    \Illuminate\Support\Facades\Schedule::job(new \App\Domains\Locality\Jobs\SyncClusterMapJob())
        ->weeklyOn(1, '03:00')
        ->withoutOverlapping()
        ->name('locality.sync-cluster-map');

    \Illuminate\Support\Facades\Schedule::job(new \App\Domains\Locality\Jobs\SyncFinanceTransactionsJob())
        ->dailyAt('04:00')
        ->withoutOverlapping()
        ->name('locality.sync-finance');

    \Illuminate\Support\Facades\Schedule::job(new \App\Domains\Locality\Jobs\AggregateLocalityDailyJob())
        ->dailyAt('05:00')
        ->withoutOverlapping()
        ->name('locality.aggregate-daily');

    \Illuminate\Support\Facades\Schedule::job(new \App\Domains\Locality\Jobs\GenerateRecommendationsJob())
        ->weeklyOn(1, '06:00')
        ->withoutOverlapping()
        ->name('locality.generate-recommendations');

    \Illuminate\Support\Facades\Schedule::job(new \App\Domains\Locality\Jobs\ReconcileFinanceJob())
        ->weeklyOn(1, '07:00')
        ->withoutOverlapping()
        ->name('locality.reconcile-finance');

    \Illuminate\Support\Facades\Schedule::job(new \App\Domains\Locality\Jobs\SyncLinkedSupplyOrdersJob())
        ->dailyAt('02:00')
        ->withoutOverlapping()
        ->name('locality.sync-linked-supply-orders');

    \Illuminate\Support\Facades\Schedule::job(new \App\Domains\Locality\Jobs\PurgeStaleLocalityRecommendationsJob())
        ->weeklyOn(0, '03:30')
        ->withoutOverlapping()
        ->name('locality.purge-stale-recommendations');

    // Health-check инвариантов (дубликаты в ozon_order_unit_economics,
    // свежесть snapshot'ов, drift orders_count). Пишет в лог, exit != 0
    // при проблемах — можно связать с alerting'ом через onFailure().
    \Illuminate\Support\Facades\Schedule::command('locality:health-check')
        ->hourly()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/locality-health.log'))
        ->name('locality.health-check');
}

// Платное хранение Ozon FBO — пишет в inventory_warehouses.storage_fee_prev_month.
// Включи через ENV OZON_STORAGE_SCHEDULE=true. Источник: Ozon Placement-by-Products report.
if (filter_var(env('OZON_STORAGE_SCHEDULE', false), FILTER_VALIDATE_BOOLEAN)) {
    \Illuminate\Support\Facades\Schedule::job(new \App\Jobs\SyncStorageCostJob('ozon', 30))
        ->dailyAt('03:30')
        ->withoutOverlapping()
        ->name('ozon.sync-storage-cost');
}

// Авто-обновление тарифов складов WB (КС). Между ручными синками КС
// замораживается (например, Электросталь оставалась 160% после поднятия WB до 180%),
// поэтому раз в сутки освежаем box-тарифы + inventory-коэффициенты и пересобираем кэш.
// Нагрузка минимальна (~2 запроса на интеграцию). Отключается через WB_TARIFF_SCHEDULE=false.
if (filter_var(env('WB_TARIFF_SCHEDULE', true), FILTER_VALIDATE_BOOLEAN)) {
    \Illuminate\Support\Facades\Schedule::command('wb:refresh-tariffs')
        ->dailyAt('05:30')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/wb-tariff-refresh.log'))
        ->name('wb.refresh-tariffs');
}

// Авто-синхронизация ограничений складов МП (доступность приёмки, коэффициенты)
// из API в marketplace_constraint_snapshots — заменяет ручную загрузку файла.
// WB-коэффициенты приёмки датированы и быстро устаревают → обновляем ежечасно.
// По умолчанию ВЫКЛЮЧЕНО (новый синк, нагрузка на API МП): включить MP_CONSTRAINTS_SCHEDULE=true.
// ВНИМАНИЕ: на проде schedule:run может быть не настроен (см. ниже про системный cron) —
// тогда задачу нужно завести в /etc/cron.d.
if (filter_var(env('MP_CONSTRAINTS_SCHEDULE', false), FILTER_VALIDATE_BOOLEAN)) {
    // WB: коэффициенты приёмки датированы и быстро устаревают → ежечасно.
    \Illuminate\Support\Facades\Schedule::command('mp:sync-constraints --marketplace=wildberries')
        ->hourly()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/mp-constraints-sync.log'))
        ->name('mp.sync-constraints-wb');

    // Ozon: доступность кластеров/загрузка складов меняется реже → раз в сутки.
    \Illuminate\Support\Facades\Schedule::command('mp:sync-constraints --marketplace=ozon')
        ->dailyAt('04:30')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/mp-constraints-sync.log'))
        ->name('mp.sync-constraints-ozon');
}

// Оценка точности планов через горизонт N дней (этап 2, план-факт). Раз в сутки
// ставит в очередь EvaluatePlanAccuracyJob для созревших готовых планов.
// Включено по умолчанию: без ежедневной сверки коммерческий контур не
// накапливает WAPE/bias и не может улучшать следующие планы.
if ((bool) config('autoplanning.plan_fact.schedule_enabled', true)) {
    \Illuminate\Support\Facades\Schedule::command('plan:evaluate-accuracy')
        ->dailyAt((string) config('autoplanning.plan_fact.schedule_time', '06:00'))
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/plan-accuracy.log'))
        ->name('plan.evaluate-accuracy');
}

// Жизненный цикл Ozon-поставок автоплана: после создания черновика/заявки
// подтягиваем текущий state, слот и факт приёмки. В отличие от тяжёлых синков
// обрабатываются только активные локальные поставки.
if ((bool) config('autoplanning.execution.status_poll_enabled', true)) {
    \Illuminate\Support\Facades\Schedule::job(new \App\Jobs\SyncSupplyStatusesJob())
        ->everyFifteenMinutes()
        ->withoutOverlapping()
        ->name('autoplanning.ozon-supply-statuses');
}

// Полный refresh фактов Ozon, без которого readiness быстро становится stale.
// Команда только ставит idempotent jobs в очереди; внешние вызовы выполняются
// воркерами с unique locks и штатными retry/backoff.
if ((bool) config('autoplanning.facts.refresh_enabled', true)) {
    \Illuminate\Support\Facades\Schedule::command('autoplanning:refresh-ozon-facts')
        ->cron((string) config('autoplanning.facts.refresh_cron', '17 2,10,18 * * *'))
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/autoplanning-ozon-facts.log'))
        ->name('autoplanning.ozon-facts-refresh');
}

// In-product уведомления о credentials Ozon: 30/14/7/1 день и истечение.
if ((bool) config('autoplanning.credential_notifications.enabled', true)) {
    \Illuminate\Support\Facades\Schedule::job(new \App\Jobs\GenerateAlertsJob())
        ->dailyAt((string) config('autoplanning.credential_notifications.schedule_time', '08:00'))
        ->withoutOverlapping()
        ->name('autoplanning.ozon-credential-alerts');
}

// Сторожок свежести постингов Ozon: ловит «тихую смерть» эндпоинтов
// (200 + пустой result, как /v3/posting/fbo/list 29.07.2026) — постинги молчат
// N дней при живых продажах в аналитике → Log::error. 1 запрос аналитики
// на интеграцию в сутки, лимитам не угрожает.
\Illuminate\Support\Facades\Schedule::command('ozon:postings-freshness')
    ->dailyAt('09:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/ozon-postings-freshness.log'))
    ->name('ozon.postings-freshness');

// Sanity-check unit_economics_cache запускается через системный cron
// (/etc/cron.d/ue-sanity-check) — не через Laravel scheduler, потому что
// schedule:run на этом сервере не настроен. См. `php artisan ue:sanity-check`.

// Синхронизация фактических количеств по workspace в лимиты Sellico.
// Запускается часто и безопасно: считает локальные usage и отправляет
// абсолютное value в /workspaces/{workspace}/limits-external/sync.
\Illuminate\Support\Facades\Schedule::command('limits:sync-products')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/limits-sync.log'))
    ->name('limits.sync-products');

// Ad-hoc запуск синхронизации платного хранения Ozon (для проверки/первого прогрева кэша).
// Использование: php artisan sync:ozon-storage --days=30 --integration_id=59 --wait=60
Artisan::command('sync:ozon-storage {--days=30 : Окно периода в днях для отчёта Ozon Placement} {--integration_id= : ID интеграции Ozon} {--wait=60 : Максимальное ожидание отчёта, секунд}', function () {
    $days = (int) $this->option('days');
    $integrationId = $this->option('integration_id') !== null && $this->option('integration_id') !== ''
        ? (int) $this->option('integration_id')
        : null;
    $wait = (int) $this->option('wait');

    $scope = $integrationId ? "интеграция {$integrationId}" : 'все Ozon-интеграции';
    $this->info("Запуск SyncStorageCostJob для Ozon ({$scope}), окно {$days} дн., ожидание {$wait} сек.");
    \App\Jobs\SyncStorageCostJob::dispatchSync('ozon', $days, $integrationId, $wait);
    $this->info('Готово. Проверь storage/logs/laravel.log → SyncStorageCostJob.');
})->purpose('Синхронизация платного хранения Ozon FBO в inventory_warehouses');

// Сверка локального зеркала интеграций с живым списком Sellico: удаляет осиротевшие
// (удалённые в Sellico, но «зависшие» локально) интеграции + их товары/кэш/снапшоты.
// Команда fail-closed: без достоверного непустого списка из Sellico ничего не удаляет.
//
// ВКЛЮЧАТЬ только когда сервисный аккаунт Sellico получит доступ к списку интеграций
// (сейчас /get-integrations/{ws} отвечает «reserved for service accounts», а
// /workspaces/{ws}/integrations — 401 для сервисного токена → команда безопасно
// пропускает все workspace). До этого запускать вручную с пользовательским токеном:
//   php artisan integrations:reconcile --workspace=3 --token=<bearer> --apply
if (filter_var(env('INTEGRATIONS_RECONCILE_SCHEDULE', false), FILTER_VALIDATE_BOOLEAN)) {
    \Illuminate\Support\Facades\Schedule::command('integrations:reconcile --apply')
        ->dailyAt('06:30')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/integrations-reconcile.log'))
        ->name('integrations.reconcile');
}

// Санитар «зомби»-интеграций по ОТПЕЧАТКУ credentials (в отличие от reconcile выше —
// сигнал чисто локальный, Sellico не требуется). Ловит один кабинет, подключённый
// дважды: деактивирует старые дубликаты, оставляя самую свежую. Безопасно (только
// is_active=false, данные не трогаются). Прод использует /etc/cron.d, а не schedule:run,
// поэтому там задача заводится отдельной cron-строкой:
//   */30 * * * * www-data cd /var/www/products-backend && php artisan integrations:dedup-credentials --apply >> storage/logs/integrations-dedup.log 2>&1
if (filter_var(env('INTEGRATIONS_DEDUP_SCHEDULE', false), FILTER_VALIDATE_BOOLEAN)) {
    \Illuminate\Support\Facades\Schedule::command('integrations:dedup-credentials --apply')
        ->everyThirtyMinutes()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/integrations-dedup.log'))
        ->name('integrations.dedup-credentials');
}

// Авто-обновление остатков и продаж ПО СКЛАДАМ из API → inventory_warehouses.
// Заменяет ручную загрузку CSV-отчёта Ozon в разделе автопланирования: матрица
// предпочитает свежий average_daily_sales из синка (см. resolveInventoryMatrixDailyDemand).
// 3 раза в сутки — бережём API МП (синки исторически были ручными именно из-за нагрузки).
// По умолчанию ВЫКЛЮЧЕНО: включить INVENTORY_SYNC_SCHEDULE=true.
// ВНИМАНИЕ: на проде schedule:run не настроен — задача заведена в /etc/cron.d/inventory-sync,
// которая дёргает `php artisan inventory:sync-scheduled` напрямую (как ue-sanity-check выше).
if (filter_var(env('INVENTORY_SYNC_SCHEDULE', false), FILTER_VALIDATE_BOOLEAN)) {
    \Illuminate\Support\Facades\Schedule::command('inventory:sync-scheduled')
        ->cron('20 2,10,18 * * *')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/inventory-sync.log'))
        ->name('inventory.sync-scheduled');
}
