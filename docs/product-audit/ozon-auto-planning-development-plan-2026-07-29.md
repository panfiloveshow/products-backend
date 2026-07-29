# План разработки автопланирования поставок Ozon FBO

Дата: 29 июля 2026 года.

Горизонт: 10 недель до закрытой коммерческой beta.

План проверен по Context7 и фактически зарегистрированным Laravel routes. Детальная сверка: [Context7 и route feasibility review](ozon-context7-route-feasibility-review-2026-07-29.md).

Уточнение после проверки: новый параллельный execution-модуль не создаётся. Автоплан связывается с существующей сущностью `Supply`, после чего переиспользуются `/api/supplies/{id}/create-draft`, `/timeslots`, `/book-slot` и `/sync-status`.

## Статус реализации

Backend/API реализованы 29 июля 2026 года:

- отдельный `business_status` и переходы `REVIEW_REQUIRED → APPROVED → EXECUTING → IN_TRANSIT → RECEIVED → RECONCILED`;
- единый readiness-контракт с SLA, coverage, ошибками, credential health и прогрессом полного refresh;
- OAuth Bearer и legacy `Client-Id`/`Api-Key`, срок действия, переподключение credentials и уведомления 30/14/7/1 день;
- `OzonPlanningFactsBuilder`, построчная матрица фактов и хэши спроса, остатков, поставок, экономики и ограничений;
- расчёт v2: окна 7/28/56, OOS correction, promo/new-product trial, ABC по contribution margin, service-level safety stock, pack/min/max, seller reserve, бюджет и negative-profit gate;
- shadow-отчёт legacy EWMA против v2 без влияния на результат;
- review API с исключениями и фильтрами, агрегатом `SKU × кластер`, ручным quantity/destination, bulk/min/max, аудитом, пересчётом бюджета и сравнением планов;
- утверждение только готового Ozon-плана, прошедшего quality gate и проверку числовых Ozon SKU;
- отдельная `validate`, которая блокирует stale facts, неизвестные направления и недоступные кластеры;
- fingerprint утверждённой версии и блокировка исполнения после изменения строк, кластеров, способа поставки или товарного Ozon SKU;
- связи `AutoSupplyPlan → Supply` и `AutoSupplyPlanLine → SupplyItem`;
- идемпотентная материализация одной локальной поставки на кластер без вызова внешнего API;
- защита материализованного плана от удаления, пересчёта и ручного изменения;
- повторный `create-draft` не создаёт второй черновик Ozon;
- асинхронный execution с idempotency key, лимитами 2 draft/мин и 50/час, partial recovery и ручной сверкой неоднозначного remote state;
- обязательный свежий таймслот, привязанный к интеграции, складу и draft; без интервала money-path не вызывается;
- `sync-status` после создания заявки использует `/v3/supply-order/get`, а не статус операции черновика;
- production-вызовы списка FBO postings переведены с deprecated `/v2/posting/fbo/list` на `/v3/posting/fbo/list`;
- план-факт WAPE/weighted bias, accepted/rejected, OOS days, excess cover, manual override outcome и причины расхождений;
- 41 route автопланирования, tenant/permission-проверки и фоновые расписания refresh/tracking/plan-fact/credential alerts.

Финальная локальная регрессия: `619 passed`, `3252 assertions`, `0 failed`;
`git diff --check`, `composer validate`, route registration и scheduler registration проходят.

Ограничения репозитория и внешние production-gates:

- в репозитории нет frontend приложения продукта, поэтому физическая реализация экранов выполняется в frontend-репозитории по готовому API-контракту;
- живой вызов `draft → timeslot → supply order → supply get` требует выделенного Ozon-кабинета, реальных credentials и явного разрешения на создание заявки;
- продуктовые beta-метрики и p95 подтверждаются только нагрузочным прогоном и реальными циклами поставки.

Полная матрица покрытия: [ozon-auto-planning-implementation-coverage-2026-07-29.md](ozon-auto-planning-implementation-coverage-2026-07-29.md).
Контракт для экранов: [ozon-auto-planning-ui-api-contract-2026-07-29.md](ozon-auto-planning-ui-api-contract-2026-07-29.md).

## 1. Результат

Пользователь за один рабочий сценарий:

1. Подключает кабинет Ozon.
2. Видит готовность и свежесть данных.
3. Получает расчёт `товар × макролокальный кластер`.
4. Проверяет OOS, покрытие, товар в пути, бюджет и маржу.
5. Корректирует строки.
6. Получает актуальные варианты поставки.
7. Подтверждает действие.
8. Создаёт draft/order поставки через Ozon Seller API.
9. Отслеживает поставку до приёмки.
10. Видит план-факт и точность рекомендации.

Коммерческое обещание:

> Выполнимый план Ozon FBO с созданием поставки после подтверждения пользователя.

## 2. Граница MVP

Входит:

- Ozon FBO;
- прямые и кросс-док поставки, поддерживаемые публичным Seller API;
- товары, остатки, спрос, in-transit;
- макролокальные кластеры;
- собственный склад;
- safety stock, cover, кратность;
- бюджет и отрицательная маржа;
- review и ручные изменения;
- preview и создание draft/order;
- синхронизация статуса;
- план-факт.

Не входит:

- FBS replenishment;
- управление производством и закупочными заказами;
- автоматическое исполнение без подтверждения;
- browser automation кабинета;
- обещания роста ранжирования;
- сложная ML-сезонность до накопления plan-fact.

## 3. Что переиспользуем

- `AutoSupplyPlan` и строки плана;
- `PlanningFactSnapshotService`;
- `PlanningReadinessChecklistService`;
- `PlanQualityAuditService`;
- Ozon capability endpoints;
- crossdock drop-off point service;
- текущий preview/fingerprint/confirmation flow;
- Ozon draft creation;
- supply status sync;
- plan-fact задел.

Что выводим из legacy job:

- сбор фактов;
- прогноз;
- расчёт safety stock;
- территориальное распределение;
- budget optimizer;
- quality classification.

Переписывание выполняется по strangler pattern с `calculation_engine=legacy|v2`.

## 4. Целевой пользовательский поток

```text
READINESS
  → SYNC
  → CALCULATE
  → REVIEW_REQUIRED
  → VALIDATE
  → READY_TO_APPROVE
  → APPROVED
  → PREVIEW
  → CONFIRM
  → EXECUTING
  → IN_TRANSIT
  → RECEIVED
  → RECONCILED
```

Блокирующие состояния:

```text
DATA_BLOCKED
VALIDATION_BLOCKED
EXECUTION_FAILED
CANCELLED
```

## 5. Команда

Минимально:

- 1 backend lead;
- 1 backend developer;
- 1 frontend developer;
- 0,5 QA/automation;
- 0,25 product/data analyst.

При одном backend срок увеличится примерно до 13–14 недель.

## 6. Этапы

### Этап OZ-0. Общий фундамент — неделя 1

Цель: перестать создавать исполнимые планы из недостоверных фактов.

Backend:

- провести contract spike `draft → info → timeslot → supply create → supply get`;
- выбрать один canonical FBO API adapter и пометить дублирующие клиенты deprecated;
- разделить `calculation_status` и `business_status`;
- добавить состояния `DATA_BLOCKED`, `REVIEW_REQUIRED`, `READY_TO_APPROVE`, `APPROVED`;
- запретить export/execution без `APPROVED`;
- создать `DataFreshnessRegistry`;
- вернуть реальные timestamps, coverage и last error;
- определить SLA источников;
- сохранить requested и effective parameters;
- записывать `forecast_version`, `allocation_version`, `adapter_version`, `code_commit`;
- включить idempotency key для внешних действий.
- добавить `supplies.auto_supply_plan_id`;
- добавить `supply_items.auto_supply_plan_line_id`;

Tests:

- stale stock блокирует расчёт/исполнение;
- bad quality нельзя утвердить;
- повторный execute с тем же idempotency key не создаёт вторую поставку;
- effective parameters воспроизводят результат.

Definition of Done:

- невозможно получить исполнимый план без snapshot;
- невозможно выполнить план со статусом `ready` legacy;
- API показывает причину каждой блокировки.
- утверждённый план материализуется в локальные `Supply` и `SupplyItem` без внешнего вызова;
- живой Ozon contract подтверждён на отдельной интеграции до включения money-path.

### Этап OZ-1. Авторизация и контракты Ozon — неделя 2

Цель: интеграция не прекращает работу из-за истёкшего ключа.

Backend:

- добавить OAuth как основной способ подключения;
- сохранить поддержку legacy API key;
- хранить expiry/rotation health;
- уведомлять за 30, 14, 7 и 1 день;
- переподключать credentials без создания новой integration;
- проверить permissions необходимых методов;
- создать contract fixtures актуальных Ozon responses;
- зафиксировать `macrolocal_cluster_id`.

Frontend:

- экран подключения OAuth;
- health badge;
- понятный reconnect flow;
- список отсутствующих разрешений.

Definition of Done:

- OAuth-интеграция проходит полный sync;
- legacy key показывает срок/риск;
- истёкшие credentials переводят план в `DATA_BLOCKED`.

Официальное основание: [Ozon рекомендует OAuth, новые ключи действуют 180 дней](https://dev.ozon.ru/news/649-Obnovlenie-pravil-raboty-s-API-kliuchami-Vazhnye-izmeneniia-v-rabote-s-Ozon-Seller-API/).

### Этап OZ-2. Нормализованные факты — недели 2–3

Цель: одна каноническая матрица данных перед прогнозом.

Источники:

- товары и offer/SKU mapping;
- FBO sellable stock;
- FBO postings;
- returns/cancellations;
- analytics stocks;
- turnover;
- supply orders и их состав;
- макролокальные кластеры;
- direct/crossdock capabilities;
- собственный склад;
- себестоимость;
- финансовые операции/тарифные snapshot.

Backend:

- ввести `OzonPlanningFactsBuilder`;
- запретить `reset(first row)` fallback;
- точное соответствие `destination_id`;
- явная агрегация warehouse → macrolocal cluster;
- дедупликация in-transit;
- integration-scoped unit economics;
- unavailable cost не считать нулём;
- сохранять source, fetched_at, coverage и hash;
- metric `unmatched_destination_rate`.

Definition of Done:

- 100% строки имеют точный product mapping;
- нет неявного выбора первого склада;
- in-transit не дублируется между внутренней и Ozon-поставкой;
- каждая цифра восстанавливается из snapshot.

### Этап OZ-3. Расчётное ядро v2 — недели 4–5

Цель: простой воспроизводимый расчёт вместо набора скрытых эвристик.

Backend:

- OOS-corrected demand;
- окна 7/28/56;
- promo/manual override;
- отдельная policy нового товара;
- ABC по доле contribution margin;
- service-level safety stock;
- target cover;
- pack/min/max;
- seller reserve;
- budget optimizer;
- negative-margin gate;
- confidence и blocking reasons;
- shadow calculation legacy vs v2.

Базовая формула:

```text
base_daily =
    0.50 × corrected_avg_28d
  + 0.30 × corrected_avg_7d
  + 0.20 × corrected_avg_56d

gross_need =
    forecast_daily × (lead_time + target_cover)
  + safety_stock

recommended =
  round_to_pack(
    max(0, gross_need - stock - confirmed_in_transit)
  )
```

Tests:

- OOS-дни не занижают спрос;
- один и тот же snapshot даёт тот же результат;
- изменение lead time влияет на расчёт;
- budget не превышается;
- новый товар не получает fake historical forecast;
- отрицательная маржа блокируется или требует override.

Definition of Done:

- 100% строк имеют короткое объяснение;
- legacy и v2 сравниваются в shadow report;
- параметры действительно применяются.

### Этап OZ-4. Review UX — недели 5–7

Цель: менеджер проверяет план за 10–15 минут.

Экран готовности:

- статус всех источников;
- время обновления;
- coverage;
- ошибки;
- кнопка `Обновить данные`.

Экран плана:

- товар;
- кластер;
- остаток;
- в пути;
- спрос/день;
- дата OOS;
- cover before/after;
- рекомендуемое количество;
- стоимость;
- confidence;
- причина;
- status.

Фильтры:

- срочный OOS;
- blocked;
- низкая confidence;
- новый товар;
- нет себестоимости;
- отрицательная маржа;
- budget-cut;
- изменено вручную.

Действия:

- изменить quantity;
- исключить;
- изменить destination;
- bulk update;
- minimum/maximum;
- комментарий;
- пересчитать бюджет;
- сравнить с предыдущим планом.

Definition of Done:

- 80% beta-пользователей завершают review без инструкции разработчика;
- нет необходимости читать raw JSON;
- любое изменение оставляет audit record.

### Этап OZ-5. Validation и execution — недели 7–8

Цель: довести план до реальной поставки без двойного создания.

Backend:

- capability check по каждой группе;
- актуальные direct/crossdock варианты;
- доступные точки сдачи и интервалы;
- preview final payload;
- fingerprint плана;
- explicit confirmation;
- draft/order creation;
- idempotency;
- mandatory timeslot для `POST /v2/draft/supply/create`;
- async draft jobs с rate limiter вместо синхронного цикла controller;
- retry только для безопасных ошибок;
- сохранение marketplace IDs;
- execution audit.

Frontend:

- сравнение `план → payload Ozon`;
- предупреждения о недоступных направлениях;
- выбор допустимого способа;
- финальное подтверждение;
- progress/status.

Definition of Done:

- никакого execute без подтверждения;
- повторный запрос не создаёт дубликат;
- изменённый после preview план требует нового подтверждения;
- API error не переводит план в ложный success.
- частично созданный набор drafts можно безопасно продолжить;
- после появления `ozon_supply_id` статус читается через supply-order API, а не через статус создания draft.

Официальное основание: [изменения Ozon FBO и макролокальных кластеров](https://dev.ozon.ru/news/647-Izmeneniia-v-metodakh-Seller-API-pri-rabote-s-postavkami-FBO/).

### Этап OZ-6. Tracking и план-факт — недели 9–10

Цель: измерить пользу и обучать будущие версии.

Backend:

- sync статуса supply order;
- approved/sent/received quantities;
- частичная приёмка;
- отмена/перенос;
- link plan line → supply item;
- WAPE и weighted bias;
- OOS days;
- excess cover;
- manual override outcome;
- причина расхождения.

Frontend:

- timeline поставки;
- расхождения по строкам;
- KPI плана;
- уведомления об отклонениях.

Definition of Done:

- каждая выполненная строка имеет plan-fact;
- фактическая приёмка не перезаписывает исходный plan snapshot;
- точность измеряется по SKU и кластеру.

## 7. API продукта

Рекомендуемые действия с учётом уже существующих routes:

```text
GET    /api/auto-supply-plans/data-health
POST   /api/auto-supply-plans
POST   /api/auto-supply-plans/{id}/calculate
GET    /api/auto-supply-plans/{id}
PUT    /api/auto-supply-plans/{id}/lines/{line}
POST   /api/auto-supply-plans/{id}/validate
POST   /api/auto-supply-plans/{id}/approve
GET    /api/auto-supply-plans/{id}/cluster-draft-preview
POST   /api/auto-supply-plans/{id}/materialize-supplies
GET    /api/auto-supply-plans/{id}/execution
GET    /api/auto-supply-plans/{id}/plan-fact

POST   /api/supplies/{id}/create-draft
GET    /api/supplies/{id}/timeslots
POST   /api/supplies/{id}/book-slot
POST   /api/supplies/{id}/sync-status
```

Не создавать новый параллельный модуль `/supply-recommendations-v2`.

Существующий `POST /auto-supply-plans/{id}/create-cluster-drafts` оставить как совместимый wrapper, но перевести на создание связанных `Supply` и асинхронное выполнение через `SupplyService`.

## 8. Нефункциональные требования

- calculation p95 ≤ 90 секунд для 5000 SKU;
- sync progress видим пользователю;
- calculation success ≥ 99%;
- execution success ≥ 98%, исключая подтверждённые внешние блокировки;
- 0 duplicate supply orders;
- 0 cross-integration facts;
- audit trail всех ручных и внешних действий;
- retry/backoff с учётом rate limits;
- encrypted credentials;
- structured logs по integration/plan/execution ID.

## 9. Beta

Состав:

- 5–7 Ozon кабинетов;
- 100–3000 SKU;
- минимум 3 цикла поставки каждый;
- разные типы direct/crossdock;
- минимум один кабинет с ограниченным собственным остатком.

Метрики выхода:

- ≥ 70% рекомендаций приняты или осознанно скорректированы;
- unmatched destination < 0,5%;
- duplicate execution = 0;
- stale-data execution = 0;
- 90% планов reviewed менее чем за 15 минут;
- измеряется 4-недельный WAPE и bias;
- минимум 80% пользователей готовы использовать следующий цикл.

## 10. Порядок реализации задач

```text
OZ-0 statuses/freshness
  → OZ-1 OAuth/contracts
  → OZ-2 facts
  → OZ-3 calculation v2
  → OZ-4 review UX
  → OZ-5 execute
  → OZ-6 plan-fact
```

OZ-2 и frontend readiness из OZ-4 можно делать параллельно. Execution нельзя начинать до строгого facts contract и approval state.
