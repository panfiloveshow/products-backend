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
