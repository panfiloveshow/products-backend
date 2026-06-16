# ТЗ: Замыкание план-факт цикла автопланирования

**Статус:** черновик на ревью
**Дата:** 2026-06-11
**Контекст:** Roadmap автопланирования, пункт #2 (второй по важности блокер коммерческого продукта)
**Связанные документы:** [TZ_MARKETPLACE_LIMITS_AUTOSYNC.md](./TZ_MARKETPLACE_LIMITS_AUTOSYNC.md)

---

## 1. Проблема и цель

### Сейчас
Цикл планирования **разомкнут**: план строится → снапшот входных фактов пишется → и на этом всё. Прогноз спроса никогда не сравнивается с тем, что реально произошло после поставки.

Конкретные разрывы (проверено по коду):
- `PlanningFactSnapshot` **только пишется, никогда не читается обратно**. Вдобавок хранит лишь агрегаты/счётчики (`demand_facts_json` = `source_counts`, `analysis_period_days`…), а **не пер-SKU прогноз** — для сравнения с фактом непригоден as-is.
- Единственная сущность с полями `forecast_accuracy/mape/bias` (`SupplyAnalytics` + `CalculateSupplyAnalyticsCommand`) **сломана**: считает факт из таблиц `orders`/`order_items`, **которых в БД нет** → факт всегда 0 → метрика всегда уходит в оптимистичный дефолт `accuracy=100, mape=0`. И она не зарегистрирована в планировщике.
- Нет ни одной джобы «через N дней сравни план с фактом».
- Нет FK-связи `auto_supply_plan_lines` ↔ `supply_items` — план автопоставки и фактическая поставка живут в разных ветках.

### Почему это блокер продукта
Клиент платит за то, чтобы доверять рекомендации к поставке. Без план-факта продукт:
1. не может показать **доказанную точность** прогноза («наши рекомендации сбываются на X%»);
2. не **учится** — одни и те же систематические ошибки (перезаказ сезонных, недозаказ растущих) повторяются;
3. не отличает **ошибку прогноза** от **ошибки исполнения** (МП не принял поставку) — а это разные проблемы с разными владельцами.

### Цель
Замкнуть цикл: после построения плана автоматически, через горизонт N дней, измерять две вещи — **точность прогноза спроса** и **исполнение поставки** — хранить результат пер-SKU, показывать клиенту и использовать агрегированную ошибку для калибровки будущих прогнозов.

### Definition of Done
1. Для плана со `status=ready`, построенного ≥ N дней назад, автоматически считается точность прогноза (MAPE/bias) пер-строка и агрегат по плану.
2. Отдельно фиксируется исполнение: план vs принято на поставке (`accepted_qty`).
3. Результат хранится, доступен через API и виден в UI («точность прогноза по плану / по SKU»).
4. Источник факта — реальный (`postings`/`posting_items`), а не битый `orders`.
5. Агрегированная ошибка по SKU/кластеру доступна как корректор спроса (калибровка) — с защитными ограничениями и возможностью отключить.
6. Метрики не маскируют отсутствие данных под «100% точность» — пустой факт = `null`/`insufficient_data`, не `accuracy=100`.

---

## 2. Что меряем: два разных факта

Критично разделить — это разные метрики с разной семантикой.

### A. Точность прогноза спроса (forecast accuracy)
**Вопрос:** «насколько спрогнозированный спрос совпал с реальными продажами за горизонт».
- **Прогноз:** из `auto_supply_plan_lines` — `demand_daily * N` либо сумма `simulation_json[].sales_forecast` за N дней. (НЕ из снапшота — там нет пер-SKU.)
- **Факт:** реальные продажи по `(sku/offer_id, cluster_id/warehouse_id)` за окно `[plan.created_at; plan.created_at + N]` из `postings` + `posting_items`.
- **Метрики:** APE (`|forecast-actual|/actual`), bias (`(forecast-actual)/actual`), агрегаты MAPE/bias по плану и по SKU/кластеру.

### B. Исполнение поставки (supply execution)
**Вопрос:** «то, что рекомендовали поставить, реально доехало и было принято».
- **План:** `auto_supply_plan_lines.qty_rounded`.
- **Факт:** `supply_items.accepted_qty` / `rejected_qty` / `planned_qty` через `supplies` (статусы `accepted_*`/`closed`), матчинг по `(integration_id, sku, cluster_id/warehouse_id, окно)`.
- **Метрики:** acceptance rate, доля отклонений, расхождение план/факт по количеству.

> Метрика B опциональна для MVP, но важна стратегически: низкая точность из-за того, что МП срезал лимит приёмки (B), — это НЕ ошибка прогноза (A). Связка с блокером #1 (авто-лимиты) очевидна.

---

## 3. Архитектура

```
┌──────────────────────────────────────────────────────────────┐
│ Schedule (routes/console.php, ENV-флаг)                        │
│ plan:evaluate-accuracy  (daily)                                │
└───────────────────────────┬──────────────────────────────────┘
                            │ выбирает планы: status=ready,
                            │ created_at <= now()-N, ещё не оценённые
            ┌───────────────▼──────────────────────────────────┐
            │ EvaluatePlanAccuracyJob (per plan)                │
            └───────────────┬──────────────────────────────────┘
                            │ per line
            ┌───────────────▼──────────────────────────────────┐
            │ PlanFactReconciler  ← НОВЫЙ сервис                │
            │  A) forecast vs realized sales (postings)          │
            │  B) planned vs accepted (supply_items)             │
            │  → APE, bias per line                              │
            └───────────────┬──────────────────────────────────┘
                            │ upsert
            ┌───────────────▼──────────────────────────────────┐
            │ plan_line_evaluations (НОВАЯ таблица)             │
            │  + агрегат в auto_supply_plans.accuracy_json      │
            └───────────────┬──────────────────────────────────┘
                            │ читает
   ┌────────────────────────┼───────────────────────────────────┐
   │ API/UI: точность плана  │  ForecastCalibrationService       │
   │ + heatmap по SKU        │  агрегат MAPE/bias по SKU/кластеру │
   │                         │  → корректор demand_daily (opt-in) │
   └─────────────────────────┴──────────────────────────────────┘
                                         │ (мягко влияет на след. план)
                            ┌────────────▼───────────┐
                            │ DemandForecaster /      │
                            │ calculateDailyDemandV2  │
                            └────────────────────────┘
```

**Принцип:** не трогаем существующий расчёт плана. Новая ветка «оценки» работает поверх готовых `auto_supply_plan_lines`. Калибровка (этап 4) подключается к движку спроса опционально и с guardrails.

---

## 4. Модель данных

### 4.1. Новая таблица `plan_line_evaluations`
Пер-строка оценка факта. Отдельно от снапшота (снапшот — вход; здесь — результат через N дней).

```php
Schema::create('plan_line_evaluations', function (Blueprint $t) {
    $t->id();
    $t->uuid('auto_supply_plan_id')->index();
    $t->unsignedBigInteger('auto_supply_plan_line_id')->index();
    $t->unsignedBigInteger('integration_id')->index();
    $t->string('marketplace', 50);
    $t->string('sku')->index();
    $t->string('cluster_id')->nullable();
    $t->string('warehouse_id')->nullable();

    // окно оценки
    $t->timestamp('plan_created_at');
    $t->unsignedInteger('horizon_days');
    $t->timestamp('evaluated_at');

    // A: прогноз спроса vs факт
    $t->decimal('forecast_demand_qty', 12, 2)->nullable();   // demand_daily * N
    $t->decimal('actual_sales_qty', 12, 2)->nullable();       // из postings
    $t->decimal('abs_pct_error', 8, 2)->nullable();           // APE, %
    $t->decimal('bias_pct', 8, 2)->nullable();                // signed
    $t->string('demand_fact_source', 40)->nullable();         // postings_fbo | ozon_order_report

    // B: исполнение поставки
    $t->integer('planned_qty')->nullable();
    $t->integer('accepted_qty')->nullable();
    $t->integer('rejected_qty')->nullable();
    $t->decimal('acceptance_rate', 6, 2)->nullable();

    $t->string('status', 20)->default('ok');   // ok | insufficient_data | error
    $t->json('details_json')->nullable();
    $t->timestamps();

    $t->unique(['auto_supply_plan_line_id'], 'plan_line_eval_unique');
    $t->index(['integration_id', 'sku', 'evaluated_at']);
});
```

### 4.2. Агрегат на плане
Добавить в `auto_supply_plans` колонку `accuracy_json` (nullable) — сводка по плану: `mape`, `bias`, `lines_evaluated`, `lines_insufficient_data`, `acceptance_rate`, `evaluated_at`. (Не плодить новых столбцов под каждую метрику — как уже сделано с `result_json`/`data_quality_json`.)

### 4.3. Технические долги, которые ТЗ закрывает попутно
- **Битый `CalculateSupplyAnalyticsCommand::getActualSales`** (читает несуществующие `orders`/`order_items`) — заменить на источник из `postings`/`posting_items` ИЛИ задепрекейтить команду в пользу нового `PlanFactReconciler`. Решение в RESEARCH (§9).
- **Рассинхрон `SupplyAnalytics` модель↔миграция** (модель ждёт `period_start/period_end/forecast_mape/...`, миграция создаёт `date/period_type`) — не чинить вслепую; либо выверить, либо не трогать и строить новую ветку. Зафиксировать решение.
- **Нет FK `auto_supply_plan_lines` ↔ `supply_items`** — связь B строим по `(integration_id, sku, cluster/warehouse, окно)`. Рассмотреть добавление мягкой ссылки `supply_items.auto_supply_plan_line_id` на будущее (не обязательно для MVP).

---

## 5. Источники факта (как именно собирать)

### 5.1. Факт спроса — переиспользовать SQL из `DemandForecaster`
Эталон в `app/Domains/Locality/Recommendation/DemandForecaster.php:26-42`:
```
FROM postings p
JOIN posting_items pi ON pi.posting_id = p.id
WHERE p.integration_id = ?  AND p.created_at BETWEEN ? AND ?
GROUP BY pi.offer_id, p.financial_data->>'cluster_to', DATE(p.created_at)
```
Для оценки: окно `[plan.created_at; plan.created_at + horizon_days]`, сумма `quantity` по `(offer_id, cluster_to)`. Это зеркалит источник, которым план считает прогноз (`SalesApi::getSalesBySkuAndWarehouse`, `posting_fbo_v3`). Учесть отмены/возвраты (`postings.status`/`cancelled_at`) — считать только реализованные.

**WB:** аналогично, но матчинг по `barcode`/`warehouse_id` (нет кластеров). Уточнить источник фактических продаж WB (зеркало `postings` для WB или отдельный отчёт реализации) — RESEARCH §9.

### 5.2. Факт поставки — переиспользовать логику `calculateAcceptanceRate`
`CalculateSupplyAnalyticsCommand.php:337-388` уже корректно join'ит `supplies`+`supply_items` (таблицы существуют). Переиспользовать: суммировать `planned_qty/accepted_qty/rejected_qty` по `(integration_id, sku, окно)`, фильтр по статусам принятия `Supply` (`accepted_full`/`accepted_partial`/`closed`).

### 5.3. Когда факта недостаточно
Если за окно нет ни одной продажи И нет поставки по SKU → `status = insufficient_data`, метрики `null`. **Категорически не дефолтить в `accuracy=100`.** Это главный фикс «слишком красивых цифр».

---

## 6. Калибровка (этап 4, отдельно)

Превратить накопленную ошибку в улучшение прогноза.

- `ForecastCalibrationService`: агрегирует `bias_pct` по `(sku, cluster)` за последние K оценок (напр. 3–5 планов). Если устойчивый bias (например, систематический перезаказ +20%) — формирует корректор.
- Подключение: мягкий множитель к `demand_daily` в `AutoSupplyPlanService::calculateDailyDemandV2` (`:388-464`), по образцу того, как там уже применяется `trendMultiplier` демпфированно (на 50% силы).
- **Guardrails (обязательно):**
  - корректор применяется только при достаточной выборке (≥K оценок, ≥M продаж);
  - ограничен диапазоном (например, ±25%), демпфирован (не на полную силу);
  - per-workspace флаг включения; по умолчанию — выключен (сначала показываем точность, потом включаем автокоррекцию);
  - корректор виден в `explain_json` строки («скорректировано на основе план-факта: −12%»), не «чёрный ящик».
- Это НЕ ML. Это статистический корректор систематического смещения — честный, объяснимый, ревёрсируемый.

---

## 7. Новые компоненты

| Компонент | Файл | Назначение |
|---|---|---|
| `PlanFactReconciler` | `app/Services/AutoSupplyPlanning/PlanFactReconciler.php` | Сбор факта A+B по строке плана, расчёт APE/bias |
| `EvaluatePlanAccuracyJob` | `app/Jobs/EvaluatePlanAccuracyJob.php` | Оценка одного плана (per-line), запись `plan_line_evaluations` + агрегат |
| `EvaluatePlanAccuracyCommand` | `app/Console/Commands/EvaluatePlanAccuracyCommand.php` | `plan:evaluate-accuracy {--plan=} {--integration=}` — выбор созревших планов, dispatch джоб |
| `ForecastCalibrationService` | `app/Services/AutoSupplyPlanning/ForecastCalibrationService.php` | Агрегат bias → корректор спроса (этап 4) |
| `PlanLineEvaluation` (модель) | `app/Models/PlanLineEvaluation.php` | Eloquent новой таблицы |
| Расписание | `routes/console.php` | `plan:evaluate-accuracy` `dailyAt(...)` под ENV-флагом `PLAN_ACCURACY_SCHEDULE`, `withoutOverlapping`, `appendOutputTo` |

**Выбор созревших планов:** `AutoSupplyPlan::where('status','ready')->where('created_at','<=',now()->subDays(N))` + нет свежей оценки (нет строки в `plan_line_evaluations` за этот план, либо `accuracy_json IS NULL`). N = `horizon_days` плана (оценивать по факту горизонта), но не раньше, чем горизонт реально прошёл.

---

## 8. API / UI

- `GET /api/auto-supply-plans/{id}/accuracy` (НОВЫЙ) — пер-строка оценки + агрегат: MAPE, bias, acceptance, сколько строк с `insufficient_data`.
- В `show` плана `summary` — блок «точность» если оценка готова (или «оценка через X дней» если горизонт не прошёл).
- `GET /api/auto-supply-plans/accuracy-trend` (опц.) — динамика MAPE по интеграции за период (доказательство «прогноз улучшается»).
- UI: heatmap точности по SKU/кластеру (где систематически мажем), и пометка причины низкой точности — ошибка прогноза (A) vs срез приёмки (B).
- `data-health` — добавить `last_accuracy_evaluation_at`.

---

## 9. Открытые вопросы (RESEARCH до старта)

1. **Горизонт оценки N.** Брать `horizon_days` плана? Или фиксированные 14/30? Учесть, что поставка доезжает не мгновенно — возможно, окно факта = `[plan.created_at + lead_time; + horizon]`, а не от даты плана. **Влияет на корректность метрики.**
2. **Факт продаж WB.** Есть ли в `postings` данные WB или нужен отдельный источник (отчёт реализации WB)? От этого зависит, считаем ли точность для WB в MVP.
3. **Отмены/возвраты в факте спроса.** Какие статусы `postings` считать реализованным спросом (исключать `cancelled`, как учитывать возвраты — связать с памяткой `ozon_returns_effective_logistics`).
4. **Судьба `CalculateSupplyAnalyticsCommand` / `SupplyAnalytics`.** Чинить (выверить схему) или задепрекейтить в пользу новой ветки? Рекомендация: новая ветка чистая, старую пометить deprecated.
5. **Матчинг плана и поставки (B).** По ключу `(sku, cluster, окно)` достаточно, или добавлять `supply_items.auto_supply_plan_line_id`?

---

## 10. Тестирование

- **Unit `PlanFactReconciler`:** прогноз vs факт на фикстурах `postings`/`posting_items`; `insufficient_data` при пустом факте (НЕ 100%); APE/bias арифметика; B-метрика на `supply_items`.
- **Feature `plan:evaluate-accuracy`:** выбирает только созревшие планы (created_at ≤ now−N, status=ready); не оценивает дважды; падение одного плана не валит остальные.
- **Integration:** полный цикл — построить план → засидить факт-продажи за горизонт → прогнать оценку → проверить `plan_line_evaluations` + `accuracy_json`.
- **Calibration:** устойчивый bias → корректор в пределах guardrails; недостаточная выборка → корректор не применяется; флаг workspace выключает.
- **Regression:** существующий расчёт плана не изменился при выключенной калибровке.

---

## 11. Этапы внедрения

| Этап | Содержание | Оценка | Зависит |
|---|---|---|---|
| **0. RESEARCH** | Закрыть §9 (горизонт, факт WB, возвраты, судьба SupplyAnalytics, матчинг B) | 1 д | — |
| **1. Хранилище** | `plan_line_evaluations` + `accuracy_json` + модель | 1.5 д | 0 |
| **2. Reconciler A (спрос)** | `PlanFactReconciler` метрика A на `postings`, фикс источника факта | 2.5 д | 1 |
| **3. Джоба + расписание** | `EvaluatePlanAccuracyJob` + команда + scheduler, выбор созревших планов | 1.5 д | 2 |
| **4. Reconciler B (поставка)** | acceptance/execution на `supply_items` | 1.5 д | 1 |
| **5. API/UI** | эндпоинт точности, блок в summary, heatmap | 2 д | 3 |
| **6. Калибровка** | `ForecastCalibrationService` + guardrails + opt-in флаг + explain | 3 д | 5 |
| **Тесты** | сквозняком | 2 д | 2–4 |
| **Итого** | | **~15 д** | |

**MVP-срез (доказанная точность без автокоррекции, ~7 д):** этапы 1–3 + минимальный UI (этап 5 частично). Даёт клиенту «точность прогноза по плану = X%, MAPE = Y%» на реальных данных. Калибровка (этап 6) — отдельная фаза после того, как точность измеряется и ей доверяют.

---

## Приложение: ключевые файлы

- `app/Models/AutoSupplyPlanLine.php` — `demand_daily`, `qty_rounded`, `simulation_json` (источник прогноза)
- `app/Domains/Locality/Recommendation/DemandForecaster.php:26-42` — эталон SQL факта продаж (`postings`+`posting_items`)
- `app/Models/SupplyItem.php` / `app/Models/Supply.php` — `accepted_qty`/`rejected_qty` (факт поставки)
- `app/Console/Commands/CalculateSupplyAnalyticsCommand.php:175-246` (MAPE/bias, переиспользовать) + `:251-266` (битый `getActualSales`, заменить) + `:337-388` (acceptance, переиспользовать)
- `app/Models/PlanningFactSnapshot.php` — снапшот входа (НЕ источник прогноза, только агрегаты)
- `app/Services/AutoSupplyPlanService.php:388-464` — `calculateDailyDemandV2` (точка подключения калибровки)
- `routes/console.php:57-96` — эталон регистрации scheduled-команд под ENV-флагом
