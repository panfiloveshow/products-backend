# Проверка реализуемости плана Ozon: Context7, текущие routes и код

Дата: 29 июля 2026 года.

Проверено:

- официальная коллекция Ozon Seller API в Context7: `/websites/ozon_ru_api_seller`;
- зарегистрированные Laravel routes;
- `AutoSupplyPlanController`;
- `SupplyController`;
- `SupplyService`;
- `FboSupplyOrdersApi`;
- `SuppliesApi`;
- `OzonClient`;
- профильные feature tests.

## 1. Вердикт

План Ozon реализуем.

Оценка:

| Область | Готовность сейчас | Вывод |
|---|---:|---|
| Получение товаров/остатков/спроса | 75% | основные клиенты есть, нужна нормализация и contract tests |
| Расчёт плана | 70% | функционально богатый legacy, нужен v2 contract и исправление параметров |
| Review плана | 60% backend | routes и explain есть, frontend в репозитории не найден |
| Preview и создание Ozon draft | 75% | safe-flow существует и покрыт feature tests |
| Таймслоты и создание supply order | 60% | реализовано в `/supplies`, но не связано с автопланом |
| Tracking поставки | 45% | модель и routes есть, sync после создания заявки недостаточен |
| План-факт | 40% | accuracy существует, связь с конкретной поставкой эвристическая |
| OAuth Seller API | 0% | текущий клиент поддерживает только `Client-Id` + `Api-Key` |
| Внешние contract tests | 10% | feature tests мокают внутренний flow, живой API-контракт не доказан |

Реалистичный срок закрытой beta:

- 10 недель при 2 backend + 1 frontend + QA;
- 13–14 недель при одном backend;
- плюс доступ к тестовому Ozon-кабинету для контролируемых реальных вызовов.

## 2. Что подтвердил Context7

### 2.1. Данные для расчёта доступны

Context7 подтвердил:

| Назначение | Ozon endpoint | Состояние в проекте |
|---|---|---|
| FBO postings | `POST /v3/posting/fbo/list` | реализован |
| Аналитика запасов | `POST /v1/analytics/stocks` | реализован, batch по 100 SKU |
| Остатки по складам | `POST /v2/analytics/stock_on_warehouses` | реализован |
| Список supply orders | `POST /v3/supply-order/list` | реализован |
| Детали supply orders | `POST /v3/supply-order/get` | реализован |
| Склады Ozon | `POST /v1/warehouse/ozon/list` и FBO warehouse APIs | частично реализовано несколькими клиентами |
| Кластеры | `/v1/cluster/list` / кластерные методы | реализовано, но есть несколько нормализаторов |

Важные ограничения:

- `/v1/analytics/stocks` принимает максимум 100 SKU за запрос;
- текущий `StockAnalyticsApi` правильно делит SKU на batch по 100;
- аналитика обновляется дважды в сутки, поэтому она не должна быть единственным оперативным источником остатка;
- для текущего наличия нужен приоритет оперативного stock source, а analytics — для ADS/IDC/turnover.

### 2.2. FBO execution доступен

Context7 подтвердил последовательность:

```text
create draft
→ poll draft creation
→ get draft info and available warehouses
→ get timeslots
→ create supply order from draft
→ poll creation status
→ read supply order
```

Ключевые контракты:

```text
POST /v1/draft/create
POST /v1/draft/create/info
POST /v2/draft/create/info
POST /v2/draft/timeslot/info
POST /v2/draft/supply/create
POST /v2/draft/supply/create/status
POST /v3/supply-order/get
```

Для `POST /v2/draft/supply/create` актуальный payload включает:

```json
{
  "draft_id": 123,
  "selected_cluster_warehouses": [
    {
      "macrolocal_cluster_id": 456,
      "storage_warehouse_id": 789
    }
  ],
  "timeslot": {
    "from_in_timezone": "2026-07-30T10:00:00+03:00",
    "to_in_timezone": "2026-07-30T12:00:00+03:00"
  },
  "supply_type": "DIRECT"
}
```

Следствие: создание реальной supply order технически возможно.

### 2.3. Контекст документации содержит версии одновременно

Context7 вернул одновременно:

- старые и новые direct/crossdock endpoints;
- противоречивые GET/POST описания `/draft/create/info`;
- разные warehouse methods.

Это не делает план невозможным. Это означает, что перед production каждый money-path endpoint должен быть подтверждён:

1. текущей официальной страницей;
2. сохранённым request/response fixture;
3. контролируемым вызовом на отдельной интеграции;
4. contract test в CI.

Нельзя строить production-flow только на комментарии в коде или одном агрегированном сниппете.

## 3. Зарегистрированные routes автопланирования

В проекте зарегистрировано 29 routes `/api/auto-supply-plans`.

Ключевые:

| Route | Есть | Назначение |
|---|---:|---|
| `GET /api/auto-supply-plans/capabilities` | да | возможности площадки |
| `GET /api/auto-supply-plans/data-health` | да | частичная готовность данных |
| `GET /api/auto-supply-plans/warehouses` | да | склады/кластеры |
| `GET /api/auto-supply-plans/crossdock-drop-off-points` | да | точки кросс-дока |
| `POST /api/auto-supply-plans` | да | создать и запустить план |
| `POST /api/auto-supply-plans/{id}/calculate` | да | повторный расчёт |
| `GET /api/auto-supply-plans/{id}` | да | результат |
| `GET /api/auto-supply-plans/{id}/lines` | да | строки |
| `PUT /api/auto-supply-plans/{id}/lines/{lineId}` | да | ручное изменение |
| `GET /api/auto-supply-plans/{id}/simulate` | да | симуляция |
| `GET /api/auto-supply-plans/{id}/cluster-draft-preview` | да | safe preview |
| `POST /api/auto-supply-plans/{id}/create-cluster-drafts` | да | создание Ozon draft |
| `GET /api/auto-supply-plans/{id}/accuracy` | да | план-факт/точность |

### Что уже хорошо

`cluster-draft-preview`:

- строит группы по кластерам;
- генерирует confirmation token;
- имеет TTL;
- создаёт fingerprint строк;
- учитывает supply method;
- учитывает crossdock drop-off;
- учитывает quality audit;
- запрещает создание после изменения плана.

`create-cluster-drafts`:

- требует готовый Ozon-план;
- проверяет quality gate;
- требует точную confirmation phrase;
- повторно проверяет fingerprint;
- ограничивает выбранные кластеры;
- поддерживает direct/crossdock;
- сохраняет результаты.

Проверка:

- `tests/Feature/AutoSupplyPlanShowTest.php`;
- 27 тестов прошли;
- 264 assertions;
- 0 failures.

Важно: тесты подтверждают внутренний safe-flow, но не реальный ответ Ozon.

## 4. Зарегистрированные routes поставок

Отдельный модуль `/api/supplies` уже содержит вторую половину процесса:

| Route | Реализация |
|---|---|
| `POST /api/supplies` | локальная Supply из рекомендаций |
| `POST /api/supplies/manual` | локальная Supply из товаров |
| `POST /api/supplies/{id}/create-draft` | создаёт Ozon draft |
| `GET /api/supplies/{id}/timeslots` | запрашивает slots |
| `POST /api/supplies/{id}/book-slot` | вызывает создание supply order из draft |
| `POST /api/supplies/{id}/sync-status` | синхронизирует статус |
| `POST /api/supplies/{id}/cancel` | отменяет |
| `POST /api/supplies/{id}/start-preparing` | локальный lifecycle |
| `POST /api/supplies/{id}/ready-to-ship` | локальный lifecycle |
| `POST /api/supplies/{id}/ship` | локальный lifecycle |
| package/document routes | упаковка, грузоместа, labels |

Следствие: не нужно заново писать timeslot, supply order и tracking API. Нужно связать автоплан с `Supply`.

## 5. Сравнение первоначального плана с текущими routes

| Предложенный route | Что уже есть | Решение |
|---|---|---|
| `GET /auto-supply-plans/ozon/readiness` | `GET /auto-supply-plans/data-health` | расширить существующий, не создавать дубль |
| `POST /auto-supply-plans` | существует | оставить |
| `POST /{id}/calculate` | существует | оставить |
| `GET /{id}` | существует | оставить |
| `PATCH /{id}/lines/{line}` | существует как `PUT` | сохранить PUT или добавить PATCH alias |
| `POST /{id}/validate` | отдельного нет | превратить preview summary в явный validation result |
| `POST /{id}/approve` | нет | добавить |
| `POST /{id}/ozon/preview` | существует как GET `cluster-draft-preview` | оставить совместимость, новый flow лучше POST |
| `POST /{id}/ozon/execute` | `create-cluster-drafts` создаёт только draft | не обещать полное execute этим route |
| `GET /{id}/execution` | нет | получать связанные `Supply` |
| `GET /{id}/plan-fact` | есть `/accuracy`, но частично | расширить и связать с конкретными SupplyItem |

## 6. Главный архитектурный разрыв

Сейчас:

```text
AutoSupplyPlan
  → createClusterDrafts
  → Ozon draft IDs в result_json

Supply
  → create draft
  → timeslots
  → create supply order
  → status tracking
```

Между ними нет надёжной связи.

`Supply` связан с legacy `SupplyPlan`, но не с `AutoSupplyPlan`.

План-факт ищет поставку best-effort по:

```text
integration_id + sku + окно дат
```

Это может сопоставить строку не с той поставкой.

### Обязательное исправление

Добавить:

```text
supplies.auto_supply_plan_id UUID nullable FK
supply_items.auto_supply_plan_line_id BIGINT nullable FK
```

И отношения:

```text
AutoSupplyPlan hasMany Supplies
AutoSupplyPlanLine hasMany SupplyItems
Supply belongsTo AutoSupplyPlan
SupplyItem belongsTo AutoSupplyPlanLine
```

После approval:

```text
AutoSupplyPlan
  → materialize one local Supply per execution group
  → materialize SupplyItems
  → use existing SupplyService
```

## 7. Что в текущем Ozon-коде нужно исправить

### 7.1. Дублирующие API-клиенты

Есть как минимум:

- `App\Domains\Ozon\Api\SuppliesApi`;
- `App\Domains\Ozon\Api\FboSupplyOrdersApi`;
- legacy supply методы в других Ozon services.

В `SuppliesApi` одновременно находятся старые и новые версии методов и fallbacks.

Решение:

- canonical `FboSupplyDraftsApi`;
- canonical `FboSupplyOrdersApi`;
- canonical `OzonStocksApi`;
- один DTO для каждого response;
- deprecated clients вызывают canonical adapter и позже удаляются.

### 7.2. Старый FBO postings endpoint ещё используется

Context7 указывает, что `/v2/posting/fbo/list` отключён с 1 июня 2026 года.

Основной flow уже использует `/v3/posting/fbo/list`, но `WarehousesApi` содержит вызов v2.

Решение:

- найти и удалить production usage v2;
- запретить v2 static test;
- один `FboPostingsApi`.

### 7.3. Timeslot фактически должен быть обязательным

Context7 показывает обязательные:

- `timeslot.from_in_timezone`;
- `timeslot.to_in_timezone`.

Текущий `createSupplyFromDraft()` допускает `timeslot=null` и отправляет v2 payload без него.

Решение:

- execution route требует выбранный актуальный slot;
- slot snapshot и fingerprint;
- истёкший slot блокирует execution;
- никакого fallback без timeslot.

### 7.4. Синхронное создание draft по кластерам

Текущий controller:

- создаёт draft в цикле;
- ждёт polling;
- делает только 1 секунду между кластерами.

Context7 указывает жёсткий лимит создания draft: ориентир 2 в минуту и 50 в час.

Риск:

- 429;
- HTTP timeout;
- частично созданный набор drafts;
- пользователь повторяет запрос и создаёт дубли.

Решение:

- `CreateOzonDraftBatchJob`;
- queue rate limiter;
- один execution record на группу;
- resume после partial success;
- multi-cluster draft только после живого contract proof;
- status polling отдельными jobs.

### 7.5. Нет idempotency

Confirmation fingerprint защищает от изменения preview, но не гарантирует отсутствие повторного Ozon-вызова при network timeout.

Добавить:

```text
execution_key = hash(plan_id + version + group + payload)
unique(execution_key, action)
```

До вызова Ozon сохранять execution attempt. Повторный запрос возвращает существующий результат или продолжает polling.

### 7.6. Status sync использует не тот этап lifecycle

После создания supply order нужно синхронизировать фактический order через:

- `/v3/supply-order/get`;
- bundle/items;
- status/details.

Текущий `SupplyService::syncStatus()` продолжает спрашивать статус создания по `ozon_draft_id`.

Решение:

- до появления `ozon_supply_id` — poll create status;
- после появления — `/v3/supply-order/get`;
- отдельный mapping актуальных Ozon states;
- items acceptance sync.

### 7.7. OAuth отсутствует

`OzonClient` формирует только:

```text
Client-Id
Api-Key
```

OAuth-код в проекте относится к Performance API, не к Seller API.

Решение:

- отдельный spike по Seller API OAuth;
- token provider;
- refresh/expiry handling;
- backward compatibility API key;
- integration reconnect.

Context7-запрос не вернул достаточного OAuth-контракта. Поэтому OAuth реализуем, но не оцениваем как «готовый за один endpoint» до официального onboarding spike.

### 7.8. Внутренняя документация устарела

`docs/OZON_SUPPLIES_MODULE.md` и `docs/OZON_CLUSTER_API_MIGRATION.md` описывают routes, которых нет в зарегистрированном route list, и смешивают legacy/current flow.

Решение:

- генерировать route inventory из Laravel;
- пометить legacy docs;
- source of truth — contract matrix + tests.

## 8. Исправленный route plan

### Autoplanning

Переиспользовать:

```text
GET    /api/auto-supply-plans/data-health
POST   /api/auto-supply-plans
POST   /api/auto-supply-plans/{id}/calculate
GET    /api/auto-supply-plans/{id}
GET    /api/auto-supply-plans/{id}/lines
PUT    /api/auto-supply-plans/{id}/lines/{lineId}
GET    /api/auto-supply-plans/{id}/cluster-draft-preview
GET    /api/auto-supply-plans/{id}/accuracy
```

Добавить:

```text
POST   /api/auto-supply-plans/{id}/validate
POST   /api/auto-supply-plans/{id}/approve
POST   /api/auto-supply-plans/{id}/materialize-supplies
GET    /api/auto-supply-plans/{id}/execution
```

Изменить:

```text
POST /{id}/create-cluster-drafts
```

Не должен напрямую обходить `Supply`. Варианты:

1. backward-compatible wrapper над `materialize-supplies + create-draft jobs`;
2. deprecated после перехода frontend.

### Execution

Переиспользовать:

```text
POST /api/supplies/{id}/create-draft
GET  /api/supplies/{id}/timeslots
POST /api/supplies/{id}/book-slot
POST /api/supplies/{id}/sync-status
GET  /api/supplies/{id}/events
GET  /api/supplies/{id}
```

Переименовать на уровне UI:

- `book-slot` фактически создаёт supply order из draft с выбранным timeslot;
- пользователю показывать «Создать заявку Ozon», а не обещание отдельного бронирования.

## 9. Исправленный план на 10 недель

### Неделя 1 — Contract spike

- зафиксировать Context7 contract matrix;
- сверить каждую official page;
- выполнить read-only методы на тестовой интеграции;
- выполнить один контролируемый draft;
- выполнить одну контролируемую supply order после разрешения;
- сохранить sanitized fixtures;
- выбрать canonical client;
- запретить устаревшие endpoint versions.

Gate:

- draft → info → timeslot → supply create → supply get доказан end-to-end.

### Неделя 2 — Bridge и безопасность

- `auto_supply_plan_id` в supplies;
- `auto_supply_plan_line_id` в supply_items;
- approval status;
- execution records;
- idempotency;
- fresh validation fingerprint;
- actual data-health;

Gate:

- один утверждённый plan материализуется в локальные Supply без Ozon-вызова.

### Недели 3–4 — Facts и calculation v2

- source priority;
- current stock отдельно от twice-daily analytics;
- FBO postings v3;
- supply orders/in-transit;
- exact cluster mapping;
- no first-row fallback;
- versioned effective parameters;
- shadow legacy/v2.

Gate:

- расчёт воспроизводим из snapshot.

### Недели 5–6 — Review и approval

- readiness;
- exception table;
- edits/bulk actions;
- short explanations;
- validate;
- approve;
- invalidation after edits.

Gate:

- approved plan неизменяем; изменение создаёт новую revision.

### Недели 7–8 — Ozon execution

- async draft jobs;
- rate limiter;
- draft info;
- storage warehouse selection;
- mandatory timeslot;
- supply order creation;
- idempotent retry;
- partial batch recovery.

Gate:

- 0 duplicates;
- любой partial success отображается и может быть безопасно продолжен.

### Недели 9–10 — Tracking и beta

- supply-order/get after creation;
- items/bundle;
- status mapping;
- acceptance quantities;
- direct plan-line links;
- plan-fact;
- alerts;
- beta support.

Gate:

- три полных цикла минимум на пяти кабинетах.

## 10. Contract tests, необходимые до production

### Read-only

- products;
- FBO postings v3 pagination/cursor;
- analytics stocks: 1, 100 и >100 SKU;
- stock on warehouses pagination;
- clusters;
- warehouses;
- supply-order list/get/items;
- 429 retry and backoff;
- expired/invalid credentials.

### Money path

- direct draft;
- crossdock draft;
- draft create polling;
- draft info;
- timeslot;
- supply create mandatory payload;
- create status;
- network timeout after Ozon accepted request;
- duplicate client retry;
- partial multi-group success;
- cancellation.

### Invariants

- одна plan revision не создаёт две одинаковые Supply;
- одна Supply не создаёт две Ozon supply orders;
- Ozon SKU всегда numeric and integration-scoped;
- cluster is macrolocal where required;
- execution payload equals approved fingerprint;
- stale slot cannot execute.

## 11. Go / No-Go

### Go

План можно запускать в разработку, потому что:

- необходимые read APIs существуют;
- draft APIs существуют;
- timeslot и supply create существуют;
- проект уже содержит работающие adapters;
- internal safe-preview покрыт тестами;
- supply lifecycle model и routes существуют.

### No-Go для production прямо сейчас

Нельзя включать автоматическое создание реальной заявки, пока:

- не выполнен живой contract test;
- autoplanning не связан с `Supply`;
- нет idempotency;
- timeslot не обязателен;
- draft batch выполняется синхронно;
- status sync не перешёл на supply-order/get;
- нет contract tests;
- credentials health не контролируется.

## 12. Итоговая формулировка

Техническая реализуемость высокая. Основной риск не в отсутствии Ozon API, а в фрагментации собственной реализации.

Правильное решение:

```text
не писать третий Ozon-модуль
→ выбрать canonical adapters
→ связать AutoSupplyPlan с Supply
→ переиспользовать существующий execution lifecycle
→ доказать внешние контракты
→ включать money-path только после idempotency и contract tests
```

