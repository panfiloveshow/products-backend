# ТЗ: Авто-синхронизация лимитов складов и коэффициентов приёмки маркетплейсов

**Статус:** черновик на ревью
**Дата:** 2026-06-11
**Контекст:** Roadmap автопланирования, пункт #1 (главный блокер коммерческого продукта)
**Связанные памятки:** `wb_sellico_credentials_ks_il` (резолв учёток), `ozon_returns_effective_logistics`

---

## 1. Проблема и цель

### Сейчас
Все «жёсткие» ограничения маркетплейсов, которые использует автопланирование — лимиты приёмки складов (`max_qty`), доступность направлений (`is_available`), потребности (`need_qty`), коэффициенты приёмки/доставки/хранения — попадают в расчёт **только из вручную загруженного продавцом файла Excel/CSV** (`MarketplaceConstraintFileParser` → `auto_supply_constraint_files` → `$plan->params`). Автоматического обновления нет. Файл устаревает молча: загрузили в понедельник — к среде лимиты Ozon уже другие.

Для SaaS, который продаёт «автопланирование», это ключевой разрыв обещания: продукт требует ручного ввода тех самых данных, ради автоматизации которых его покупают.

### Цель
Сделать **API маркетплейса основным источником** ограничений и коэффициентов, обновляемым по расписанию, с сохранением ручного файла как fallback/override. Продавец не должен ничего грузить руками для базового сценария.

### Критерий приёмки (Definition of Done)
1. Для интеграции Ozon/WB с валидными учётками лимиты и коэффициенты складов обновляются автоматически минимум раз в сутки (коэффициенты приёмки WB — чаще, т.к. они датированы).
2. При создании плана без загруженного файла ограничения подтягиваются из свежих авто-данных.
3. В `result_json`/UI видно: источник ограничений (`api_sync` / `constraint_file` / `manual`), время последней синхронизации, свежесть.
4. Ручной файл по-прежнему может переопределить авто-данные (приоритет: запрос > файл > авто-синк).
5. Sellico-интеграции работают (резолв через `resolveCredentials()`).

---

## 2. Что уже есть (НЕ переписывать)

| Компонент | Файл | Состояние |
|---|---|---|
| Синк слотов/коэф. приёмки | `app/Jobs/SyncWarehouseSlotsJob.php` | **Работает**, дёргается только из `SupplyController` (ручной HTTP-триггер). НЕ зашедулен. |
| Хранилище слотов | `app/Models/WarehouseSlot.php` + таблица `warehouse_slots` | Есть поля `marketplace, warehouse_id, warehouse_name, date, coefficient, is_available, allow_unload, capacity, capacity_used, storage_coefficient, delivery_coefficient, synced_at` |
| WB коэф. приёмки (живой) | `Wildberries/Api/SuppliesApi::getAvailableAcceptanceSlots()` → `GET common-api.wb.ru/api/tariffs/v1/acceptance/coefficients` | Используется в `SyncWarehouseSlotsJob:159` |
| WB склады | `Wildberries/Api/SuppliesApi::getAvailableWarehouses()` → `GET supplies-api.wb.ru/api/v1/warehouses` | Готов |
| WB box-тарифы (КС/хранение) | `Wildberries/Api/StorageApi::getTariffSnapshots()` + `WildberriesTariffSnapshot` | Работает, синкается `wb:refresh-tariffs` |
| Ozon слоты/ёмкость | `Ozon/Api/SuppliesApi::getAcceptanceSlots()` → `POST /v1/supply/timeslot/list` (поля `capacity`, `capacity_used`) | Используется в `SyncWarehouseSlotsJob` |
| Ozon кластеры/склады | `Ozon/Api/SuppliesApi::getClusters()` → `POST /v1/cluster/list` | Готов |
| Эталон команды-синка | `RefreshWildberriesTariffsCommand` + `WildberriesTariffRefresher` | Образец: fetch-once-shared → per-integration upsert |
| Резолв учёток с Sellico-fallback | `Integration::resolveCredentials()` + `MarketplaceFactory::create()` | Использовать ВЕЗДЕ вместо `*Client::fromIntegration()` |
| Формат ограничений в расчёте | `MarketplaceConstraintService::normalizeConstraints()` | Принимает «сырой» формат записей (см. §6) |

**Вывод:** мы достраиваем существующий контур `SyncWarehouseSlotsJob → warehouse_slots`, а не строим заново. Основная новая работа — (а) превратить слоты/коэффициенты в **формат ограничений плана**, (б) зашедулить, (в) закрыть пробел по лимитам Ozon FBO, (г) сделать авто-данные источником в `createPlanFromRequest`.

---

## 3. Реальность по маркетплейсам (что вообще даёт API)

Это самый важный раздел — он определяет, что физически возможно, а что нет.

### Wildberries — данные есть, контур почти готов
- **Коэффициенты приёмки** (датированные, по складам, `coefficient ∈ {0,1}` + `allow_unload`): `getAvailableAcceptanceSlots()`. Это и есть «можно ли везти на склад и по какому коэффициенту». Уже синкается.
- **Коэффициенты доставки/хранения** (box-тарифы): `StorageApi` → `WildberriesTariffSnapshot`. Уже синкается.
- **Список складов**: `getAvailableWarehouses()`.
- **Лимит количества (`max_qty`) на склад**: ❌ WB **не отдаёт** жёсткий лимит штук на поставку через API. Доступность регулируется коэффициентом приёмки (0 = бесплатно/доступно, >0 = платный коэффициент, недоступность = нет слота). → `max_qty` для WB остаётся из файла/ручного ввода; авто-источник заполняет `is_available` + коэффициенты.

### Ozon — частично; есть пробел
- **Список кластеров/складов FBO**: `getClusters()`. Готов.
- **Ёмкость таймслотов** (`capacity`, `capacity_used`): `getAcceptanceSlots()` → `/v1/supply/timeslot/list`. Это ближайший аналог «лимита» — сколько ещё влезет в окно приёмки. Уже синкается в `warehouse_slots`.
- **Коэффициент приёмки как у WB**: ❌ **отсутствует как концепция**. `Ozon/Api/SuppliesApi::getAcceptanceCoefficients()` — заглушка `return []`. Ozon оперирует «индексом локализации» и стоимостью в черновике, а не коэффициентом приёмки склада.
- **Жёсткий лимит поставки на склад/кластер (`max_qty`)**: ❌ **Ozon НЕ отдаёт жёсткий лимит штук** (на SKU/склад на поставку) через API — проверено по документации и Go-клиенту (см. §11.1, вопрос закрыт). Вместо этого Ozon даёт три сигнала:
  - **`POST /v1/supplier/available_warehouses`** (`GetWarehouseWorkload`) → по складам `Schedule.Capacity[].Value` = **среднее кол-во товаров/день** (пропускная способность), `Date` = ближайшая дата приёмки. Это **мягкий сигнал загрузки**, не жёсткий cap.
  - **`POST /v1/draft/create/info`** → по складам `Status` (доступен/нет) + `RestrictedBundleId` (товары с ограничениями) + `TravelTimeDays`. Даёт `is_available` и блокировку отдельных SKU.
  - **`POST /v1/supply/timeslot/list`** (уже синкается) → `capacity`/`capacity_used` окна приёмки.
  → Для Ozon `max_qty` остаётся файловым/ручным; авто-синк даёт `is_available` (из draft-info `Status` + наличие окна), мягкий коэффициент загрузки (из workload `Capacity.Value`) и блокировку restricted-SKU. Методов `getWarehouseWorkload`/`getDraftInfo`-разбора restrictions в коде ещё нет — добавить в `Ozon/Api/SuppliesApi` (см. §13, этап 3).

### Итоговая матрица: что автоматизируем, что остаётся ручным

| Данное | Ozon | WB |
|---|---|---|
| `is_available` (доступность направления) | ✅ авто (timeslot/draft) | ✅ авто (коэф. приёмки) |
| `acceptance_coefficient` | ➖ нет в API (concept absent) | ✅ авто |
| `delivery_coefficient` / `storage_coefficient` | ⚠️ из draft estimated_cost (косвенно) | ✅ авто (box-тарифы) |
| ёмкость окна приёмки (`capacity`) | ✅ авто (timeslot) | ➖ |
| `max_qty` (жёсткий лимит штук) | ❌ Ozon API не отдаёт (проверено) → файл | ❌ WB не отдаёт → файл |
| мягкий сигнал загрузки склада | ✅ авто (`available_warehouses` → Capacity.Value) | ➖ |
| блокировка restricted-SKU | ✅ авто (`draft/create/info` → RestrictedBundleId) | ➖ |
| `need_qty` (потребность) | ❌ всегда файл (это входные данные продавца, не МП) | ❌ всегда файл |

**Честная позиция для продукта:** авто-синк закрывает **доступность + коэффициенты** (главное для территориального ранжирования и блокировки закрытых складов). `max_qty` и `need_qty` остаются за файлом там, где API их не отдаёт — это нужно явно показать в UI как «дополняется вручную», а не делать вид, что всё автоматизировано.

---

## 4. Целевая архитектура

```
                     ┌─────────────────────────────────────────────┐
                     │  Schedule (routes/console.php, ENV-флаг)      │
                     │  mp:sync-constraints  (daily / hourly для WB) │
                     └───────────────────┬─────────────────────────┘
                                         │
                     ┌───────────────────▼─────────────────────────┐
                     │ SyncMarketplaceConstraintsCommand            │
                     │  перебор active Integration (ozon/wb)         │
                     └───────────────────┬─────────────────────────┘
                                         │ resolveCredentials() + MarketplaceFactory
                     ┌───────────────────▼─────────────────────────┐
                     │ MarketplaceConstraintSyncService             │  ← НОВЫЙ
                     │  ozon(): clusters + timeslots + draft-info    │
                     │  wb():   warehouses + acceptance coef + box   │
                     │  → нормализует в "сырой формат ограничений"   │
                     └───────────────────┬─────────────────────────┘
                                         │ upsert
        ┌────────────────────────────────▼───────────────────────────┐
        │ warehouse_slots (есть)  +  marketplace_constraint_snapshots │ ← НОВАЯ таблица
        │                                (агрегированный срез к плану) │
        └────────────────────────────────┬───────────────────────────┘
                                         │ читает при создании плана
        ┌────────────────────────────────▼───────────────────────────┐
        │ AutoSupplyPlanController::createPlanFromRequest()            │
        │  приоритет источника:                                        │
        │   1) request.cluster/warehouse_constraints (явный override)  │
        │   2) AutoSupplyConstraintFile (ручной файл)                  │
        │   3) MarketplaceConstraintSnapshot (АВТО) ← НОВОЕ            │
        │  → $plan->params[...] + constraint_metadata.source_kind      │
        └────────────────────────────────┬───────────────────────────┘
                                         │ (Job и TerritorialPlanningService НЕ меняются)
                     ┌───────────────────▼─────────────────────────┐
                     │ CalculateAutoSupplyPlanJob → ConstraintService│
                     │ → TerritorialPlanningService (читает params)  │
                     └───────────────────────────────────────────────┘
```

**Принцип минимальной инвазивности:** `CalculateAutoSupplyPlanJob`, `MarketplaceConstraintService`, `TerritorialPlanningService`, `PlanLineOptimizer` **не меняются**. Они читают `$plan->params['cluster_constraints'|'warehouse_constraints']` и `explain_json.constraints.*`. Новый источник просто наполняет params тем же форматом. Вся новая логика — в синке и в `createPlanFromRequest`.

---

## 5. Модель данных

### 5.1. Новая таблица `marketplace_constraint_snapshots`
Агрегированный, готовый-к-плану срез ограничений на интеграцию. Зачем отдельно от `warehouse_slots`: слоты — «сырьё» (датированные строки по дням), а снапшот — нормализованный к формату плана срез «на сейчас», который напрямую кладётся в params. Это снимает нагрузку с момента создания плана (не агрегировать слоты на лету).

```php
Schema::create('marketplace_constraint_snapshots', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('integration_id')->index();
    $t->string('marketplace', 50)->index();
    $t->json('cluster_constraints_json')->nullable();   // для ozon — формат §6
    $t->json('warehouse_constraints_json')->nullable(); // для прочих — формат §6
    $t->json('summary_json')->nullable();               // coverage, кол-во складов, доступных/закрытых
    $t->json('sources_json')->nullable();               // какие API дали данные (timeslot/draft/coef/box)
    $t->string('sync_status', 20)->default('ok');        // ok | partial | error
    $t->text('sync_error')->nullable();
    $t->timestamp('synced_at')->nullable();
    $t->timestamps();
    $t->unique(['integration_id', 'marketplace'], 'mp_constraint_snap_unique');
});
```
Одна актуальная строка на (integration, marketplace), перезаписывается синком (`updateOrCreate`).

### 5.2. Доработать `auto_supply_constraint_files`
Добавить поле, чтобы отличать источник (сейчас модель подразумевает только «файл»):
```php
$t->string('source_kind', 20)->default('file')->after('marketplace'); // file | api_sync | manual
```
(Используется для UI-статуса и логики приоритета.)

### 5.3. `warehouse_slots` — без изменений
Уже хранит всё нужное. Добавить только индекс `(integration_id, marketplace, warehouse_id, date)` если его нет — для быстрой агрегации в снапшот.

---

## 6. Контракт данных: «сырой формат записи ограничения»

Это формат, который потребляет `MarketplaceConstraintService::normalizeConstraints()`. Авто-синк ОБЯЗАН отдавать ровно его (эталон — выход `MarketplaceConstraintFileParser`, строки 84–105).

**Одна запись (ozon — кластер; wb — склад):**
```php
[
    // идентификация направления
    'cluster_id'   => '12',          // ozon: id кластера; wb — НЕ заполнять
    'cluster_name' => 'Москва, МО',  // ozon
    'warehouse_id' => '507',         // wb: id склада; ozon — НЕ заполнять
    'warehouse_name' => 'Коледино',  // wb
    'sku'          => null,           // null = ограничение на всё направление; иначе на SKU×направление

    // лимиты/доступность
    'max_qty'      => null,           // авто-источник заполняет ТОЛЬКО если API отдаёт (см. §3); иначе null
    'need_qty'     => null,           // авто-синк НЕ заполняет (это вход продавца) → всегда null
    'is_available' => true,           // ← ключевое, что даёт авто-синк

    // коэффициенты (мягкое влияние на ранжирование)
    'acceptance_coefficient' => 1.0,  // wb: из getAvailableAcceptanceSlots; ozon: null
    'delivery_coefficient'   => 1.1,  // wb: box-тарифы; ozon: косвенно из draft или null
    'storage_coefficient'    => 1.0,  // wb: box-тарифы; ozon: null
    'logistics_coefficient'  => null,

    // метаданные
    'reason'       => 'Приёмка открыта, коэф. 1.0',
    'source_type'  => 'marketplace_constraint', // marketplace_constraint | marketplace_need | constraint_and_need
],
```

Массив таких записей кладётся в `cluster_constraints` (ozon) / `warehouse_constraints` (wb).

**Важно про коэффициент приёмки WB:** в API `coefficient = 0` означает «бесплатная приёмка / доступно», `coefficient > 0` — платный множитель, отсутствие слота — недоступно. При маппинге в формат плана:
- слот есть, `coefficient = 0`, `allow_unload = true` → `is_available = true`, `acceptance_coefficient = 1.0` (нейтрально для costScore);
- слот есть, `coefficient > 0` → `is_available = true`, `acceptance_coefficient = 1 + coefficient/100` (или согласовать формулу с тем, как территориалка ждёт множитель — см. `TerritorialPlanningService:173` `costScore = 100/max(1, max(storage,acceptance))`);
- слота нет на горизонте N дней → `is_available = false`, `reason = 'Нет окон приёмки'`.

> ⚠️ Точную формулу нормализации `coefficient` WB → `acceptance_coefficient` плана надо сверить с семантикой `costScore`/`coefficientPenalty` в `TerritorialPlanningService` (§ потребление коэф.), чтобы 0 не превратился в «бесконечно дорого». Это требование к реализации, не очевидность.

---

## 7. Новые компоненты

### 7.1. `app/Services/AutoSupplyPlanning/MarketplaceConstraintSyncService.php` (НОВЫЙ)
Ядро синка. По образцу `WildberriesTariffRefresher`.

```php
class MarketplaceConstraintSyncService
{
    // Главный вход: синкнуть одну интеграцию, вернуть снапшот.
    public function syncIntegration(Integration $integration): MarketplaceConstraintSnapshot;

    // Ozon: clusters + timeslots(capacity) + draft-info(is_available) → записи ограничений
    private function buildOzonConstraints(MarketplaceInterface $mp): array;

    // WB: warehouses + acceptance coefficients + box-тарифы → записи ограничений
    private function buildWbConstraints(MarketplaceInterface $mp): array;

    // Маппинг коэф. приёмки WB → формат плана (см. §6, согласовать с costScore)
    private function normalizeWbCoefficient(array $slot): array;

    // Сводка: сколько складов, доступно/закрыто, какие источники дали данные
    private function buildSummary(array $records, array $sources): array;
}
```
Требования:
- Резолв учёток **только** через `$integration->resolveCredentials()` + `MarketplaceFactory::create($mp, $creds, $integration)`. Если нет `api_key` (Ozon — ещё и `client_id`) → пропустить интеграцию, статус `error`, не падать.
- Каждый блок данных (clusters / timeslots / coef / box) в **отдельном try/catch** — частичный успех валиден (`sync_status = partial`), как в `StorageApi::getTariffSnapshots`.
- Идемпотентность: `updateOrCreate` снапшота по `(integration_id, marketplace)`.
- Rate limiting уже в клиентах (`WildberriesClient::rateLimitDelay`, `OzonClient` 429-retry) — не дублировать, но между интеграциями ставить паузу (как `usleep` в Job для кластеров).

### 7.2. `app/Console/Commands/SyncMarketplaceConstraintsCommand.php` (НОВЫЙ)
По образцу `RefreshWildberriesTariffsCommand`.
```php
protected $signature = 'mp:sync-constraints {--integration=} {--marketplace=}';
```
- Без опций: перебор `Integration::query()->active()->whereIn('marketplace', ['ozon','wildberries'])`.
- `--integration=ID` — одна интеграция (для ручного триггера/отладки).
- `--marketplace=ozon|wildberries` — фильтр.
- На каждую интеграцию: `MarketplaceConstraintSyncService::syncIntegration()`, лог результата (складов синкнуто, доступно/закрыто, статус).
- Возвращает корректный exit-code; одна упавшая интеграция не валит остальные.

### 7.3. Расписание — `routes/console.php`
По образцу `wb:refresh-tariffs` (строки 77–83), под ENV-флагом:
```php
if (filter_var(env('MP_CONSTRAINTS_SCHEDULE', true), FILTER_VALIDATE_BOOLEAN)) {
    Schedule::command('mp:sync-constraints')
        ->dailyAt('04:30')              // базово раз в сутки
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/mp-constraints-sync.log'))
        ->name('mp.sync-constraints');

    // WB-коэффициенты приёмки датированы и «горят» — обновлять чаще
    Schedule::command('mp:sync-constraints --marketplace=wildberries')
        ->hourly()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/mp-constraints-sync.log'))
        ->name('mp.sync-constraints-wb');
}
```
> Замечание: на проде часть scheduler-задач гоняется системным cron, а не Laravel scheduler (комментарий в `routes/console.php:86`). Уточнить, активен ли `schedule:run` на 194.87.104.42, иначе зарегистрировать команду в системном crontab.

### 7.4. `app/Models/MarketplaceConstraintSnapshot.php` (НОВЫЙ)
Eloquent-модель новой таблицы. `belongsTo Integration`. Casts json-полей в array. Метод `toPlanConstraints(): array` → отдаёт `['cluster_constraints' => ..., 'warehouse_constraints' => ..., 'metadata' => ...]` в формате, готовом для `$plan->params` (аналог `AutoSupplyConstraintFile::toPlanMetadata`).

---

## 8. Интеграция в создание плана

Точечная правка `AutoSupplyPlanController::createPlanFromRequest()` (строки 153–171), расширить цепочку приоритетов:

```php
$constraintFile = $this->resolveConstraintFile($request, $integration);
$autoSnapshot   = $this->resolveAutoConstraintSnapshot($request, $integration); // ← НОВОЕ

// приоритет: явный request > ручной файл > авто-синк
$warehouseConstraints = $request->input('warehouse_constraints')
    ?? $constraintFile?->warehouse_constraints_json
    ?? $autoSnapshot?->warehouse_constraints_json;          // ← НОВОЕ
$clusterConstraints = $request->input('cluster_constraints')
    ?? $constraintFile?->cluster_constraints_json
    ?? $autoSnapshot?->cluster_constraints_json;            // ← НОВОЕ

$constraintMetadata = $request->input('constraint_metadata')
    ?? $constraintFile?->toPlanMetadata()
    ?? $autoSnapshot?->toPlanMetadata();                    // ← НОВОЕ, с source_kind = 'api_sync'
```

`resolveAutoConstraintSnapshot()` — новый приватный метод: берёт свежий `MarketplaceConstraintSnapshot` по `(integration_id, marketplace)`, проверяет свежесть (`synced_at` не старше, например, 48ч) и `sync_status != error`. Управляется флагом запроса `use_auto_constraints` (default true) — чтобы продавец мог явно отключить авто-источник.

`StoreAutoSupplyPlanRequest` — добавить `'use_auto_constraints' => 'nullable|boolean'`.

`constraint_metadata.source_kind` теперь принимает `api_sync` — пробросить в `MarketplaceConstraintService::apply()` (строка 178, где сейчас различается `constraint_file` vs `request_params`) для корректного статуса в `result_json`/UI.

---

## 9. API / UI изменения

- `GET /api/auto-supply-plans/constraints` (существующий) — расширить ответом: текущий авто-снапшот (когда синкнут, сколько складов, доступно/закрыто), а не только загруженные файлы.
- `POST /api/auto-supply-plans/sync-constraints` (НОВЫЙ, опционально) — ручной триггер `mp:sync-constraints --integration=` для кнопки «обновить лимиты сейчас» в UI (dispatch job, не синхронно).
- `GET /api/auto-supply-plans/data-health` (существующий, сейчас частично-заглушка) — добавить реальные `marketplace_constraints_synced_at`, `marketplace_constraints_source` вместо захардкоженных `null`.
- В `summary` плана (`show`) — блок «источник ограничений»: `api_sync` / `constraint_file` / `manual` + дата свежести, чтобы продавец видел, на чём построен план.

---

## 10. Граничные случаи и надёжность

| Случай | Поведение |
|---|---|
| Нет учёток / Sellico не отдал ключ | Интеграция пропускается, `sync_status = error`, прошлый снапшот не затирается (плановое создание использует последний валидный + предупреждение о свежести) |
| API МП вернул частично (slots ok, box упал) | `sync_status = partial`, пишем что есть, `sources_json` фиксирует пробел |
| 429 / rate limit | Ретраи на уровне клиента; команда не падает, лог-варнинг |
| Склад в авто-данных, которого нет в файле, и наоборот | Приоритет источника по §8; авто-данные не мёржатся с файлом, а замещаются целиком (во избежание скрытых конфликтов max_qty) |
| WB `coefficient` нормализация | По §6, согласовать с `costScore`; `0 → 1.0` нейтрально, нет слота → `is_available=false` |
| Ozon без `max_qty` из API | `max_qty = null` (не cap'ит), `is_available` из draft/timeslot; в UI пометка «лимит штук — вручную» |
| Снапшот устарел (> 48ч) | План создаётся, но `data_quality` отражает stale-источник; UI предупреждает |

**Идемпотентность:** повторный синк той же интеграции даёт тот же снапшот (перезапись). Параллельные запуски исключены `withoutOverlapping()`.

---

## 11. Открытые вопросы (требуют ресёрча/решения до старта)

1. ~~**Ozon FBO per-warehouse лимит штук.**~~ ✅ **ЗАКРЫТО (2026-06-11).** Ozon Supply API **не отдаёт** жёсткий лимит количества (max_qty) на склад/кластер/SKU. Проверено по официальной документации (docs.ozon.ru) и Go-клиенту `diphantxm/ozon-api-client`. Доступны только: пропускная способность склада по датам (`POST /v1/supplier/available_warehouses` → `Schedule.Capacity.Value`, среднее товаров/день), статус доступности и restricted-bundle (`POST /v1/draft/create/info` → `Status`, `RestrictedBundleId`), ёмкость окна приёмки (`POST /v1/supply/timeslot/list` → `capacity`/`capacity_used`). **Решение:** для Ozon `max_qty` остаётся файловым; авто-синк заполняет `is_available` (из draft `Status` + наличие окна), мягкий коэффициент загрузки (опционально, из `Capacity.Value`) и блокировку restricted-SKU. Нужно добавить в `Ozon/Api/SuppliesApi` методы `getWarehouseWorkload()` и разбор `RestrictedBundleId` из `getDraftInfo()` (объём этапа 3 уточнён, не вырос).
   > ⚠️ `/v1/supplier/available_warehouses` в одном из источников помечен как deprecated — при реализации сверить актуальный путь (возможна замена на `/v1/warehouse/fbo/list`) на живом аккаунте.
2. **Формула нормализации WB `coefficient` → `acceptance_coefficient`.** Сверить с математикой `costScore`/`coefficientPenalty` в `TerritorialPlanningService`, чтобы не инвертировать смысл.
3. **Горизонт WB-слотов.** На сколько дней вперёд считать «доступность» склада (есть ли хоть одно окно с `coefficient ∈ {0,1}` в ближайшие N дней). Предложение: N = `horizon_days` плана, но не более 14.
4. **Прод scheduler.** Активен ли Laravel `schedule:run` на 194.87.104.42 или нужен системный crontab (см. комментарий в `routes/console.php`).
5. **Миграция с файла.** Нужен ли период, когда файл и авто-синк сосуществуют с явным переключателем в UI, прежде чем авто станет default.

---

## 12. Тестирование

- **Unit:** `MarketplaceConstraintSyncService` с замоканными `MarketplaceInterface` — корректный маппинг slots/coef → формат §6; частичный успех; нормализация WB coefficient (0, >0, нет слота).
- **Feature:** `mp:sync-constraints` создаёт/обновляет снапшот; одна упавшая интеграция не валит команду; `resolveCredentials` Sellico-fallback (есть памятка `wb_sellico_credentials_ks_il`).
- **Integration:** `createPlanFromRequest` с авто-снапшотом (без файла) → ограничения попали в `$plan->params` → `apply()` блокирует закрытые склады. Приоритет request > file > auto.
- **Regression:** существующий файловый поток не сломан (приоритет файла над авто).
- **Контрактный тест:** запись авто-источника проходит `MarketplaceConstraintService::normalizeConstraints()` без потерь полей.

---

## 13. Этапы внедрения

| Этап | Содержание | Зависит от |
|---|---|---|
| **0. Ресёрч** | Закрыть открытые вопросы §11 (особенно Ozon `max_qty` и WB-формулу) | — |
| **1. Хранилище** | Миграции: `marketplace_constraint_snapshots`, `source_kind` в `auto_supply_constraint_files`, индекс `warehouse_slots`. Модель снапшота. | 0 |
| **2. Синк WB** | `MarketplaceConstraintSyncService::buildWbConstraints` (данные уже синкаются — переиспользовать) + команда + расписание. WB первым, т.к. данные полнее. | 1 |
| **3. Синк Ozon** | `buildOzonConstraints` (clusters + timeslots + draft is_available). `max_qty` по итогам §11.1. | 1, 0 |
| **4. Подключение к плану** | Правка `createPlanFromRequest` (§8), `use_auto_constraints`, `source_kind` в metadata. | 2 |
| **5. UI/API** | `data-health`, блок источника в `summary`, ручной триггер, индикация свежести. | 4 |
| **6. Переключение default** | Авто-источник становится default, файл → override. Опциональный переходный период. | 5 |

**Оценка:** этапы 1–2 (WB end-to-end) — основной value, т.к. данные уже синкаются и нужно лишь нормализовать + зашедулить + подключить. Ozon (этап 3) дороже из-за пробела с `max_qty`.

---

## Приложение: ключевые файлы

- `app/Jobs/SyncWarehouseSlotsJob.php` — переиспользуемый синк слотов (зашедулить/обернуть)
- `app/Models/WarehouseSlot.php` — сырьё
- `app/Domains/Wildberries/Api/SuppliesApi.php:352` `getAvailableAcceptanceSlots` / `:171` `getAvailableWarehouses`
- `app/Domains/Wildberries/Api/StorageApi.php` — box-тарифы
- `app/Domains/Ozon/Api/SuppliesApi.php:640` `getClusters` / `:179` `getAcceptanceSlots` / `:1352` `getDraftInfo`
- `app/Models/Integration.php:162` `resolveCredentials` (Sellico-fallback — обязательно)
- `app/Domains/Marketplace/MarketplaceFactory.php:22` `create`
- `app/Services/Wildberries/WildberriesTariffRefresher.php` — эталон fetch→upsert
- `app/Console/Commands/RefreshWildberriesTariffsCommand.php` — эталон команды
- `routes/console.php:77` — эталон расписания под ENV-флагом
- `app/Services/AutoSupplyPlanning/MarketplaceConstraintService.php:344` `normalizeConstraints` — целевой формат
- `app/Http/Controllers/Api/AutoSupplyPlanController.php:153` `createPlanFromRequest` — точка подключения
- `app/Models/AutoSupplyConstraintFile.php:49` `toPlanMetadata` — эталон metadata
