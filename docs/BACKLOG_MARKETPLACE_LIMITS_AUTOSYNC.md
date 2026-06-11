# Бэклог: Авто-синхронизация лимитов складов МП

**Источник:** [TZ_MARKETPLACE_LIMITS_AUTOSYNC.md](./TZ_MARKETPLACE_LIMITS_AUTOSYNC.md)
**Дата:** 2026-06-11
**Оценка в идеальных днях разработки (1 backend-разработчик, знакомый с проектом).**
**Легенда статусов зависимостей:** блок = нельзя начать без; soft = желательно после.

---

## Сводка по этапам

| Этап | Эпик | Тикеты | Оценка | Зависит от |
|---|---|---|---|---|
| 0 | Ресёрч и решения | RESEARCH-1 | 0.5 д (частично закрыто) | — |
| 1 | Хранилище данных | STORE-1, STORE-2, STORE-3 | 2 д | 0 |
| 2 | Синк WB (end-to-end) | WB-1, WB-2, WB-3 | 3.5 д | 1 |
| 3 | Синк Ozon | OZ-1, OZ-2, OZ-3 | 4 д | 1, 0 |
| 4 | Подключение к плану | PLAN-1, PLAN-2 | 2 д | 2 (WB достаточно) |
| 5 | UI/API и наблюдаемость | UI-1, UI-2, UI-3 | 2.5 д | 4 |
| 6 | Переключение default | SWITCH-1 | 1 д | 5 |
| — | Тесты (сквозной) | TEST-1 | 2 д | параллельно 2–4 |
| **Итого** | | **16 тикетов** | **~17.5 д** | |

**Рекомендованный MVP-срез (демонстрируемая ценность за ~7.5 д):** Этап 1 + Этап 2 (WB) + PLAN-1 + UI-1. Даёт авто-ограничения WB в плане без ручного файла. Ozon добивается отдельно.

---

## Эпик 0 — Ресёрч и решения

### RESEARCH-1 · Закрыть открытые вопросы API и формул
**Оценка:** 0.5 д (вопрос про Ozon `max_qty` уже закрыт 2026-06-11)
**Зависимости:** —
**Описание:**
Финализировать решения из §11 ТЗ, оставшиеся после закрытия вопроса про Ozon-лимиты:
- Формула нормализации WB `coefficient` → `acceptance_coefficient` плана, согласованная с `costScore = 100/max(1, max(storage,acceptance))` в `TerritorialPlanningService:173`.
- Горизонт WB-слотов для расчёта `is_available` (предложение: `min(horizon_days, 14)`).
- Проверить на проде, активен ли Laravel `schedule:run` (или нужен системный crontab) — см. `routes/console.php:86`.
- Сверить актуальность Ozon-пути `/v1/supplier/available_warehouses` (возможна замена на `/v1/warehouse/fbo/list`).

**Acceptance criteria:**
- [ ] В ТЗ зафиксирована точная формула WB-коэффициента (с примерами 0 / >0 / нет слота).
- [ ] Зафиксирован горизонт доступности WB.
- [ ] Подтверждён механизм запуска планировщика на проде.
- [ ] Подтверждён рабочий Ozon-эндпоинт workload на живом аккаунте.

---

## Эпик 1 — Хранилище данных

### STORE-1 · Миграция и модель `marketplace_constraint_snapshots`
**Оценка:** 1 д
**Зависимости:** RESEARCH-1 (soft)
**Описание:** Создать таблицу агрегированного среза ограничений на интеграцию (§5.1 ТЗ) и Eloquent-модель.
**Acceptance criteria:**
- [ ] Миграция `marketplace_constraint_snapshots` с полями `integration_id, marketplace, cluster_constraints_json, warehouse_constraints_json, summary_json, sources_json, sync_status, sync_error, synced_at` + unique `(integration_id, marketplace)`.
- [ ] Модель `MarketplaceConstraintSnapshot` с casts json→array, `belongsTo Integration`, методом `toPlanConstraints()`/`toPlanMetadata()` (формат как `AutoSupplyConstraintFile::toPlanMetadata`, но `source_kind = 'api_sync'`).
- [ ] `php artisan migrate` проходит на чистой БД и откатывается.

### STORE-2 · Поле `source_kind` в `auto_supply_constraint_files`
**Оценка:** 0.5 д
**Зависимости:** —
**Описание:** §5.2 ТЗ — добавить `source_kind` (`file|api_sync|manual`, default `file`), чтобы отличать источник.
**Acceptance criteria:**
- [ ] Миграция `add_source_kind_to_auto_supply_constraint_files` (default `file`, чтобы не сломать существующие записи).
- [ ] Модель и `toPlanMetadata()` пробрасывают `source_kind`.

### STORE-3 · Индекс для агрегации слотов
**Оценка:** 0.5 д
**Зависимости:** —
**Описание:** §5.3 — добавить (если нет) индекс `warehouse_slots (integration_id, marketplace, warehouse_id, date)` для быстрой выборки при сборке снапшота.
**Acceptance criteria:**
- [ ] Индекс существует, миграция идемпотентна (проверка наличия перед созданием).

---

## Эпик 2 — Синк Wildberries (end-to-end)

### WB-1 · Сервис `MarketplaceConstraintSyncService::buildWbConstraints`
**Оценка:** 2 д
**Зависимости:** STORE-1 (блок)
**Описание:** §7.1 ТЗ. Собрать из существующих источников (`getAvailableWarehouses`, `getAvailableAcceptanceSlots`, box-тарифы `StorageApi`) массив записей ограничений в «сыром формате» (§6) и записать `MarketplaceConstraintSnapshot`.
**Acceptance criteria:**
- [ ] Резолв учёток через `Integration::resolveCredentials()` + `MarketplaceFactory::create()` (НЕ `fromIntegration`).
- [ ] Маппинг WB-коэффициента по формуле из RESEARCH-1; `coefficient=0 → acceptance=1.0`, нет слота на горизонте → `is_available=false`.
- [ ] Каждый блок данных (склады/коэф/box) в отдельном try/catch; частичный успех → `sync_status='partial'` + `sources_json`.
- [ ] Идемпотентность: повторный вызов перезаписывает снапшот (`updateOrCreate`).
- [ ] Запись проходит `MarketplaceConstraintService::normalizeConstraints()` без потери полей (контрактный тест).

### WB-2 · Команда `mp:sync-constraints` (+ WB-ветка)
**Оценка:** 1 д
**Зависимости:** WB-1 (блок)
**Описание:** §7.2 ТЗ. Console-команда по образцу `RefreshWildberriesTariffsCommand`.
**Acceptance criteria:**
- [ ] Signature `mp:sync-constraints {--integration=} {--marketplace=}`.
- [ ] Без опций перебирает `active` интеграции; одна упавшая не валит остальные; корректный exit-code.
- [ ] Лог: складов синкнуто, доступно/закрыто, статус на интеграцию.
- [ ] `--integration=ID` и `--marketplace=wildberries` работают как фильтры.

### WB-3 · Расписание WB под ENV-флагом
**Оценка:** 0.5 д
**Зависимости:** WB-2 (блок)
**Описание:** §7.3 ТЗ. Регистрация в `routes/console.php` под `MP_CONSTRAINTS_SCHEDULE`.
**Acceptance criteria:**
- [ ] Общий синк `dailyAt('04:30')` + WB-синк `hourly()`, оба `withoutOverlapping()`, `appendOutputTo(logs/mp-constraints-sync.log)`, `name(...)`.
- [ ] Обёрнуто в `filter_var(env('MP_CONSTRAINTS_SCHEDULE', true), ...)`.
- [ ] `.env.example` дополнен флагом.

---

## Эпик 3 — Синк Ozon

### OZ-1 · Методы API: workload + restricted-bundle
**Оценка:** 1.5 д
**Зависимости:** RESEARCH-1 (soft)
**Описание:** Добавить в `Ozon/Api/SuppliesApi` метод `getWarehouseWorkload()` (`POST /v1/supplier/available_warehouses` → `Schedule.Capacity.Value`, `Date`) и разбор `RestrictedBundleId`/`Status` из `getDraftInfo()`.
**Acceptance criteria:**
- [ ] `getWarehouseWorkload()` возвращает по складам ёмкость/день и ближайшую дату приёмки; обработка `_error`.
- [ ] Извлечение `Status` (доступность) и `RestrictedBundleId` из `getDraftInfo()`.
- [ ] Проверен рабочий путь эндпоинта (см. RESEARCH-1 deprecated-оговорка).

### OZ-2 · `MarketplaceConstraintSyncService::buildOzonConstraints`
**Оценка:** 2 д
**Зависимости:** OZ-1 (блок), STORE-1 (блок)
**Описание:** §3+§7.1. Собрать Ozon-ограничения: `is_available` (draft `Status` + наличие окна), мягкий коэф. загрузки (из `Capacity.Value`), блокировка restricted-SKU. `max_qty` НЕ заполняется (Ozon API не отдаёт).
**Acceptance criteria:**
- [ ] Записи в формате §6 с `cluster_id`/`cluster_name`; `max_qty=null`, `need_qty=null`.
- [ ] Restricted-SKU → отдельные записи с `is_available=false` + `reason`.
- [ ] Мягкий коэф. загрузки маппится так, чтобы перегруженный склад получал ниже ранг (согласовать с territorial scoring), без жёсткого cap.
- [ ] Контрактный тест через `normalizeConstraints()`.

### OZ-3 · Ozon в расписании
**Оценка:** 0.5 д
**Зависимости:** OZ-2, WB-3
**Описание:** Убедиться, что общий `mp:sync-constraints` (daily) корректно синкает Ozon; при необходимости отдельная ветка.
**Acceptance criteria:**
- [ ] Ozon-интеграции синкаются в общем daily-прогоне.
- [ ] Лог различает WB/Ozon результаты.

---

## Эпик 4 — Подключение к созданию плана

### PLAN-1 · Авто-источник в `createPlanFromRequest`
**Оценка:** 1.5 д
**Зависимости:** WB-1 (блок; этого достаточно для старта)
**Описание:** §8 ТЗ. Расширить цепочку приоритетов: запрос > файл > авто-снапшот. Новый приватный метод `resolveAutoConstraintSnapshot()` со свежестью (≤48ч) и `sync_status != error`.
**Acceptance criteria:**
- [ ] Приоритет источника: `request > AutoSupplyConstraintFile > MarketplaceConstraintSnapshot`.
- [ ] Флаг `use_auto_constraints` (default true) в `StoreAutoSupplyPlanRequest` (`nullable|boolean`).
- [ ] `constraint_metadata.source_kind = 'api_sync'` пробрасывается и различается в `MarketplaceConstraintService::apply()` (строка ~178).
- [ ] План, созданный без файла, но с авто-снапшотом, получает ограничения в `$plan->params` → закрытые склады блокируются.
- [ ] Регрессия: файловый поток не сломан (приоритет файла над авто).

### PLAN-2 · Свежесть и stale-предупреждение
**Оценка:** 0.5 д
**Зависимости:** PLAN-1 (блок)
**Описание:** Если снапшот старше порога — план строится, но в `data_quality` отражается stale-источник.
**Acceptance criteria:**
- [ ] Снапшот > 48ч → флаг stale в `result_json`/quality, расчёт не блокируется.
- [ ] Нет валидного снапшота и нет файла → план строится без ограничений с явным предупреждением (не падает).

---

## Эпик 5 — UI/API и наблюдаемость

### UI-1 · `data-health`: реальные метрики источника
**Оценка:** 1 д
**Зависимости:** PLAN-1 (блок)
**Описание:** §9 ТЗ. Заменить захардкоженные `null` в `AutoSupplyPlanController::dataHealth` (строки ~1191/1221) на реальные `marketplace_constraints_synced_at`, `marketplace_constraints_source`.
**Acceptance criteria:**
- [ ] `data-health` отдаёт дату последнего синка ограничений и источник.
- [ ] Удалены статичные `null`-заглушки, относящиеся к ограничениям.

### UI-2 · Блок «источник ограничений» в summary плана
**Оценка:** 1 д
**Зависимости:** PLAN-1 (блок)
**Описание:** В ответе `show` — блок: `source_kind` (`api_sync|constraint_file|manual`), дата свежести, сколько складов доступно/закрыто. Пометка «`max_qty`/`need_qty` — вручную» там, где API не отдаёт.
**Acceptance criteria:**
- [ ] `summary` содержит блок источника ограничений.
- [ ] UI-флаг «дополняется вручную» для `max_qty`/`need_qty`.

### UI-3 · Ручной триггер синка (кнопка «обновить лимиты»)
**Оценка:** 0.5 д
**Зависимости:** WB-2 (блок)
**Описание:** §9 — `POST /api/auto-supply-plans/sync-constraints` диспатчит `mp:sync-constraints --integration=` асинхронно.
**Acceptance criteria:**
- [ ] Эндпоинт под `sellico.permission`, dispatch job, не синхронно.
- [ ] Возвращает статус «запущено», без блокировки запроса.

---

## Эпик 6 — Переключение на авто как default

### SWITCH-1 · Авто-источник по умолчанию + переходный период
**Оценка:** 1 д
**Зависимости:** UI-1, UI-2 (блок)
**Описание:** §13 этап 6. Сделать авто-источник default; файл — override. Опциональный переключатель в UI на переходный период (решение из RESEARCH-1 / §11.5).
**Acceptance criteria:**
- [ ] При наличии свежего авто-снапшота он используется без действий продавца.
- [ ] Файл по-прежнему переопределяет авто.
- [ ] Документация для продавца обновлена (что теперь автоматически, что вручную).

---

## Сквозной — Тестирование

### TEST-1 · Покрытие тестами синка и подключения
**Оценка:** 2 д
**Зависимости:** параллельно с эпиками 2–4
**Описание:** §12 ТЗ.
**Acceptance criteria:**
- [ ] **Unit:** `MarketplaceConstraintSyncService` с замоканным `MarketplaceInterface` — маппинг slots/coef → формат §6; частичный успех; нормализация WB coefficient (0 / >0 / нет слота).
- [ ] **Feature:** `mp:sync-constraints` создаёт/обновляет снапшот; упавшая интеграция не валит команду; Sellico-fallback учёток (памятка `wb_sellico_credentials_ks_il`).
- [ ] **Integration:** `createPlanFromRequest` с авто-снапшотом без файла; приоритет request > file > auto.
- [ ] **Regression:** существующий файловый поток.
- [ ] **Контрактный:** запись авто-источника проходит `normalizeConstraints()` без потерь.

---

## Граф зависимостей (критический путь)

```
RESEARCH-1 ──┬──► STORE-1 ──► WB-1 ──► WB-2 ──► WB-3
             │              └──► PLAN-1 ──► UI-1 ──► SWITCH-1
             │                      └──► PLAN-2     UI-2 ──┘
             └──► OZ-1 ──► OZ-2 ──► OZ-3            UI-3
                                                   TEST-1 (параллельно)
```

**Критический путь до демонстрируемого MVP (WB):** RESEARCH-1 → STORE-1 → WB-1 → PLAN-1 → UI-1 ≈ **6 дней**.
**Полный объём (WB + Ozon + переключение):** ≈ **17.5 дней** с учётом параллельного тестирования.
