# Сильный аудит автопланирования поставок Sellico: Ozon и Wildberries

Дата: 29 июля 2026 года.

Статус: продуктово-технический аудит текущего backend, официальных API и конкурентного рынка.

## 1. Итог для собственника продукта

Текущий модуль нельзя выводить в коммерческий релиз как надёжное «автопланирование».

Причина не в отсутствии логики. В коде уже около 16 000 строк, связанных с расчётом, ограничениями, территориальным распределением, качеством плана и Ozon execution. Проблема в другом:

- часть пользовательских настроек фактически игнорируется;
- есть критическое расхождение с действующим API Wildberries;
- свежесть ключевых данных не гарантирована;
- слишком много недоказанных констант и скрытых эвристик;
- статус `ready` означает только «job закончен», а не «план безопасен»;
- плохой по quality gate WB-план всё равно можно экспортировать;
- план может тихо подставить данные другого склада;
- продуктовая документация не соответствует фактической сложности;
- в этом репозитории нет frontend автопланирования, поэтому полного UX-контура, который мог бы скрыть сложность backend, здесь не существует.

При этом фундамент ценный:

- есть модели плана и строк;
- есть snapshot фактов;
- есть quality/readiness механика;
- есть симуляция, ограничения, бюджет и объяснения;
- Ozon уже имеет preview → confirm → draft;
- есть plan-fact сервисы;
- 235 профильных тестов проходят.

Рекомендация: не переписывать всё с нуля. За 10–12 недель выделить проверяемое ядро, исправить P0-контракты, построить простой review flow и запустить закрытую beta на реальных кабинетах.

## 2. Оценка готовности

| Область | Оценка | Почему |
|---|---:|---|
| Покрытие данных | 5/10 | источников много, но свежесть и полнота не являются обязательным контрактом |
| Прогноз спроса | 4/10 | много эвристик, нет доказанной калибровки и стабильной версии модели |
| Распределение по направлениям | 5/10 | развитая территориальная логика, но есть риск неверной атрибуции и нестабильных правил |
| Ozon execution | 7/10 | preview, подтверждение и draft — хорошая основа |
| Wildberries execution | 2/10 | неверный контракт acceptance options и неверное понимание коэффициента >1 |
| Безопасность решения | 3/10 | `ready` не равен approved, WB export не закрыт quality gate |
| Объяснимость | 6/10 | объяснений много, но они технические и перегруженные |
| Автоматичность | 3/10 | ключевые синхронизации выключены по умолчанию |
| UX-готовность | 2/10 | frontend модуля в репозитории не найден |
| Коммерческая готовность | 3/10 | нет доказанного repeatable workflow и launch gates |

Общий вердикт: сильный R&D-прототип backend, но пока не коммерческий продукт.

## 3. Что было изучено

### Код

Ключевые части:

- `app/Services/AutoSupplyPlanService.php`
- `app/Jobs/CalculateAutoSupplyPlanJob.php`
- `app/Http/Controllers/Api/AutoSupplyPlanController.php`
- `app/Services/AutoSupplyPlanning/*`
- `app/Domains/Ozon/*`
- `app/Domains/Wildberries/*`
- `routes/api.php`
- `routes/console.php`
- профильные unit/feature tests.

Ядро автопланирования и связанных сервисов — примерно 15 900 строк без учёта моделей, миграций и marketplace-клиентов.

### Проверка тестов

Запущен набор всех обнаруженных тестов автопланирования:

- 235 тестов прошли;
- 1711 assertions;
- 0 failures;
- 2 PHP deprecation warnings.

Это подтверждает внутреннюю согласованность существующей реализации, но не соответствие внешнему API. В частности, тесты закрепляют ошибочную гипотезу, что платное WB-окно с коэффициентом больше 1 физически доступно.

### Внешняя проверка

- официальные API и справочные материалы Ozon;
- официальные API и справочные материалы Wildberries;
- Поставлено!;
- Vendber;
- Мастер Поставки;
- MetricLab;
- Поставыч;
- Nitroseller;
- встроенные рекомендации Wildberries.

Дополнительные документы:

- [Матрица API-возможностей](marketplace-api-capabilities-2026-07-29.md)
- [Конкурентная карта](competitor-profiles/_summary.md)

## 4. Как система работает сейчас

Упрощённый путь:

```text
API request
  → AutoSupplyPlan
  → CalculateAutoSupplyPlanJob
      → настройки + inventory_warehouses
      → товары и unit economics
      → Ozon/WB факты
      → спрос/тренд/выкуп/возвраты
      → safety stock/cover
      → territorial planning
      → constraints
      → optimizer/budget
      → plan lines
      → quality audit/readiness
  → status=ready
  → Excel или Ozon preview/draft
```

### Что сделано хорошо

1. Расчёт выполняется асинхронно.
2. В плане фиксируются параметры.
3. Есть попытка создать snapshot фактов.
4. Есть источники спроса и confidence.
5. Учитываются остатки, часть товара в пути, тренд, safety stock, бюджет, маржа и ограничения.
6. Есть marketplace-specific ветки.
7. Ozon execution требует предпросмотра и подтверждения.
8. Есть аудит качества и план-факт задел.
9. Ошибки отдельных внешних источников часто не валят весь job.

Последний пункт полезен для устойчивости загрузки, но опасен для решения: деградация должна быть видимой и иногда блокирующей, а не всегда превращаться в «готовый» план.

## 5. P0 — блокирующие дефекты

### P0.1. Неверный контракт Wildberries acceptance options

Текущий код:

- документирует `GET /api/v1/acceptance/options`;
- передаёт `warehouseId`, `dateFrom`, `dateTo`;
- не передаёт баркоды и количества.

Официальный контракт WB:

- `POST /api/v1/acceptance/options`;
- query `warehouseID` опционален;
- request body — до 5000 строк `barcode + quantity`;
- метод отвечает, какие склады и типы упаковки доступны именно для этого товара и количества.

Риск: система не проверяет исполнимость рассчитанной поставки.

Исправление:

1. Создать отдельный `WbAcceptanceOptionsApi`.
2. Вход: сгруппированный план `barcode + qty`.
3. Разбивать запрос по лимиту 5000 строк.
4. Вызов выполнять после расчёта и после ручных правок.
5. Сохранять snapshot ответа и TTL.
6. Блокировать экспорт недопустимых строк.
7. Добавить contract fixtures из актуальной документации.

Доказательство в коде:

- `app/Domains/Wildberries/Api/SuppliesApi.php:196`
- `app/Domains/Wildberries/Api/SuppliesApi.php:212`

### P0.2. Неверная доступность WB при коэффициенте больше 1

Текущая логика считает любое `coefficient >= 0` допустимым и лишь штрафует цену.

Официальная документация WB: приёмка доступна только при:

```text
coefficient = 0 или 1
AND allowUnload = true
```

Риск: рекомендация на склад/дату, которые нельзя оформить.

Исправление:

- `available = allowUnload && in_array(coefficient, [0, 1])`;
- иные положительные коэффициенты не считать физически доступными без отдельного подтверждённого контракта;
- заменить тесты, которые сейчас закрепляют неверное поведение;
- различать `unavailable`, `free`, `base`, `unknown`.

Доказательство:

- `app/Services/AutoSupplyPlanning/MarketplaceConstraintSyncService.php:284`
- `app/Services/AutoSupplyPlanning/MarketplaceConstraintSyncService.php:321`

### P0.3. Пользователь меняет настройки, но расчёт их игнорирует

`ewma_alpha` проходит request validation, но при создании плана принудительно записывается `0.35`. Job также использует фиксированное значение.

`lead_time_days` принимается API, но job берёт значение из `SupplySettings` или default.

Риск:

- интерфейс сообщает, что настройка применена;
- результат не меняется;
- пользователь теряет доверие;
- A/B и воспроизводимость невозможны.

Исправление:

- до появления доказанной необходимости убрать `ewma_alpha` из пользовательского интерфейса;
- если параметр остаётся — использовать его в одной версионированной реализации;
- определить приоритет `request policy > integration settings > default`;
- записывать effective parameters отдельно от requested parameters;
- contract-test: изменение параметра обязано менять snapshot/результат ожидаемым образом.

Доказательство:

- `app/Http/Requests/AutoSupplyPlan/StoreAutoSupplyPlanRequest.php:29`
- `app/Http/Controllers/Api/AutoSupplyPlanController.php:196`
- `app/Http/Controllers/Api/AutoSupplyPlanController.php:197`
- `app/Jobs/CalculateAutoSupplyPlanJob.php:127`

### P0.4. `ready` не означает «можно использовать»

После расчёта план получает `ready`, даже если quality gate плохой. Ozon execution позднее имеет отдельную проверку, WB export проверяет только `status=ready`.

Риск: пользователь выгружает и исполняет плохой план.

Исправление — ввести бизнес-состояния:

```text
DRAFT
SYNCING
DATA_BLOCKED
READY_TO_CALCULATE
CALCULATING
REVIEW_REQUIRED
READY_TO_APPROVE
APPROVED
EXECUTING
IN_TRANSIT
RECEIVED
RECONCILED
FAILED
CANCELLED
```

`calculation_status` и `business_status` лучше хранить отдельно. Экспорт/создание поставки возможны только из `APPROVED`.

Доказательство:

- `app/Models/AutoSupplyPlan.php:21`
- `app/Jobs/CalculateAutoSupplyPlanJob.php:1653`
- `app/Http/Controllers/Api/AutoSupplyPlanController.php:2969`

### P0.5. Нет обязательной свежести фактов

`data-health` возвращает:

- `last_inventory_sync_completed_at = null`;
- `ozon_delivery_analytics_cache_active = null`.

Ключевые schedule-флаги выключены по умолчанию:

- marketplace constraints;
- plan accuracy;
- inventory sync;
- часть locality и Ozon источников.

В комментариях указано, что Laravel scheduler на production может быть не настроен.

Риск: точная формула работает на старых остатках и создаёт неверную поставку.

Исправление:

- единый `DataFreshnessRegistry`;
- SLA на каждый source;
- обязательный preflight перед расчётом;
- sync-now с прогрессом;
- production heartbeat и alert;
- stale data → `DATA_BLOCKED`, не `warning`;
- health endpoint должен возвращать фактические timestamps, count, coverage и last error.

Доказательство:

- `app/Http/Controllers/Api/AutoSupplyPlanController.php:1379`
- `app/Http/Controllers/Api/AutoSupplyPlanController.php:1392`
- `routes/console.php:11`
- `routes/console.php:96`
- `routes/console.php:114`
- `routes/console.php:185`

### P0.6. Тихая подстановка первой строки Ozon

Если точное соответствие склада в Ozon stock analytics не найдено, код использует `reset()` первой строки SKU.

Риск: запас одного склада попадает в расчёт другого направления. Ошибка скрытая, численно правдоподобная и поэтому особенно опасная.

Исправление:

- только точное соответствие по каноническому destination ID;
- явная агрегация до кластера, если модель кластера;
- при отсутствии mapping — `DATA_BLOCKED` или строка `manual_review`;
- метрика unmatched destination rate.

Доказательство:

- `app/Jobs/CalculateAutoSupplyPlanJob.php:477`

### P0.7. Финансовые данные могут браться из legacy-записи без интеграции

Запрос unit economics допускает `integration_id IS NULL` как fallback.

Риск: себестоимость/маржа другого кабинета или старой общей записи влияет на бюджет и приоритет.

Исправление:

- расчёт плана использует только scoped факт текущей интеграции;
- global cost разрешён лишь как явно назначенный workspace-level fallback;
- источник и дата себестоимости отображаются;
- неизвестная себестоимость не превращается в ноль.

### P0.8. WB export теряет назначение поставки

Текущий export суммирует строки плана по barcode без включения destination в ключ группировки. Если один и тот же товар распределён в несколько регионов/складов, единый файл смешивает эти количества.

Риск:

- пользователь загружает суммарное количество не в тот склад;
- территориальная логика плана теряется на последнем шаге;
- невозможно доказать соответствие Excel утверждённому плану.

Исправление:

- один XLSX на одно назначение/будущую поставку;
- ZIP с файлами и manifest для плана с несколькими назначениями;
- проверка `Σ exported qty = Σ approved qty`;
- validation fingerprint отдельно по каждому destination;
- изменение строки инвалидирует предыдущий export.

Доказательство:

- `app/Http/Controllers/Api/AutoSupplyPlanController.php:2965`

## 6. P1 — существенные проблемы качества

### P1.1. ABC-класс считается нестабильно

Доход по SKU берётся как максимум по складам, а не сумма. Затем используются абсолютные пороги 100 000/30 000.

Почему плохо:

- SKU с распределёнными продажами недооценён;
- границы не масштабируются между маленьким и крупным продавцом;
- валюта и период зашиты косвенно.

Нужно:

- сумма contribution margin или revenue по SKU;
- сортировка по вкладу;
- A — первые 80% вклада, B — следующие 15%, C — остальные;
- отдельный флаг strategic/new, который не ломает ABC.

Доказательство:

- `app/Jobs/CalculateAutoSupplyPlanJob.php:371`
- `app/Jobs/CalculateAutoSupplyPlanJob.php:379`

### P1.2. Новые и нулевые WB SKU могут выпадать

Для ряда marketplace активный SKU определяется через остаток >0 или продажи >0. Новый товар без истории и остатка не попадёт в план автоматически.

Нужно:

- источник активного ассортимента — каталог, а не только inventory;
- policy для launch SKU;
- trial quantity и список допустимых регионов;
- явная метка `new_product`, низкая confidence и лимит бюджета.

### P1.3. WB FBS-остаток загружается, но не распределяется

Комментарий говорит, что FBS inventory уменьшает потребность, но фактическая логика использует его в основном как отображаемый факт.

Нужно решить продуктовую семантику:

- FBS-остаток — доступный товар собственного склада;
- он не должен автоматически вычитаться из FBW без allocation policy;
- пользователь указывает reserve для FBS;
- остаток сверх reserve можно распределять между WB FBW и Ozon FBO.

### P1.4. Недоказанные глобальные константы

Примеры:

- возврат в доступный запас: 80%;
- WB redemption default: 85%;
- trial нового склада: 30%;
- fixed low-volume thresholds;
- dead-stock thresholds;
- safety stock и cover caps.

Константы допустимы в MVP, если:

- задокументированы;
- версионированы;
- видны как assumption;
- настраиваются на уровне policy, где это действительно нужно;
- калибруются по plan-fact.

Сейчас они выглядят как точное знание, хотя являются предположениями.

### P1.5. Версия алгоритма фиктивна

План всегда получает:

- `forecast_model = EWMA_0.35`;
- `algorithm_version = asp-1.0.0`.

Фактически расчёт включает десятки веток, источников и сервисов. Изменения не отражаются в версии.

Нужно:

```text
policy_version
forecast_version
allocation_version
marketplace_adapter_version
facts_contract_version
code_commit
```

### P1.6. Ozon API-key auth недостаточен для SaaS-надёжности

С 13 февраля 2026 года новые ключи Ozon имеют срок жизни 180 дней; Ozon рекомендует OAuth.

Нужно:

- OAuth как основной путь;
- health/expiry alert;
- безопасная ротация legacy key;
- reconnection flow без потери integration identity.

### P1.7. Контроллер и job слишком велики

- controller — более 3300 строк;
- calculation job — более 2000 строк;
- territorial service — более 3700 строк.

Следствия:

- изменения трудно тестировать по контракту;
- marketplace-ветки смешаны;
- невозможно независимо версионировать forecast/allocation/execution;
- высок риск скрытой регрессии.

Рефакторинг нужен через strangler pattern, без big bang rewrite.

## 7. P2 — продуктовый и архитектурный долг

1. Пересекающиеся сущности `/shipments`, `/supply-recommendations`, `/supply-plans`, `/warehouse-slots`, `/supplies`, `/postings`, `/auto-supply-plans`.
2. Документ `docs/auto-supply-planning/data-mapping.md` описывает упрощённую EWMA-модель и не соответствует текущей системе.
3. Backend возвращает слишком большие explain/source JSON вместо продуктовых объяснений.
4. Нет одного канонического понятия destination.
5. Нет единой семантики available stock.
6. Отсутствует найденный frontend автопланирования в текущем репозитории.
7. Нет публично выраженных SLA, model card и rollback для алгоритма.
8. Критические external API assumptions покрыты unit tests, но не contract tests.

## 8. Реальность API и правильная граница продукта

### Wildberries FBW

Можно:

- получить каталог/баркоды;
- получить остатки по складам;
- получить спрос и финансовый факт;
- получить тарифы и коэффициенты;
- проверить опции приёмки для конкретного баркода и количества;
- прочитать уже созданные FBW-поставки;
- выгрузить XLSX.

Нельзя через публичный API:

- создать новую плановую FBW-поставку;
- добавить в неё товары;
- забронировать дату.

Правильный UX:

```text
расчёт
→ ручная проверка
→ WB validation
→ XLSX + открыть кабинет WB
→ пользователь создаёт поставку
→ Sellico импортирует/связывает созданную поставку
→ отслеживает приёмку и план-факт
```

### Ozon FBO

Можно построить более полный цикл:

```text
расчёт
→ ручная проверка
→ Ozon capability/timeslot validation
→ preview
→ подтверждение человеком
→ draft/order
→ выбор/подтверждение логистики
→ status sync
→ приёмка
→ план-факт
```

С 16 февраля 2026 года FBO-контракт менялся в сторону макролокальных кластеров. Текущий код уже содержит `macrolocal_cluster_id` и fallback нескольких версий, что является сильной стороной, но должно быть закреплено contract tests.

## 9. Целевой коммерческий продукт

### Обещание пользователю

> Sellico показывает, какой товар, в каком количестве и в какой регион нужно отправить на WB и Ozon, проверяет выполнимость плана и доводит его до поставки без ручного сведения таблиц.

Не обещать:

- гарантированный рост продаж;
- гарантированный рост позиций;
- «AI знает лучше»;
- полную автоматизацию WB FBW.

### Основной пользователь

Селлер/операционный менеджер:

- 100–5000 SKU;
- 1–10 кабинетов;
- общий собственный склад;
- регулярные FBW/FBO отгрузки;
- считает в Excel;
- теряет время на остатки, товар в пути, размеры и географию;
- боится OOS и излишков;
- хочет контролировать капитал.

### Один главный job-to-be-done

«Перед еженедельной отгрузкой за 15 минут получить выполнимый план WB и Ozon, скорректировать исключения и создать/выгрузить поставки».

## 10. Целевой рабочий процесс

### Шаг 1. Подключение и готовность

Экран показывает по каждому кабинету:

- права API;
- OAuth/key status;
- товары;
- баркоды;
- остатки;
- спрос;
- собственный склад;
- товар в пути;
- себестоимость;
- тарифы;
- ограничения;
- свежесть;
- coverage.

CTA один: `Обновить данные и исправить блокировки`.

Пока критические источники не готовы, кнопка расчёта недоступна.

### Шаг 2. Политика поставок

У пользователя 5–7 понятных настроек:

- горизонт поставки;
- lead time;
- минимальный страховой запас;
- бюджет;
- резерв собственного склада;
- кратность упаковки;
- режим: защита от OOS / баланс / сохранить деньги.

Продвинутые параметры скрыты. Пользователь не должен настраивать EWMA alpha.

### Шаг 3. Расчёт

Система создаёт immutable facts snapshot и запускает versioned calculation.

Результат не называется «готовой поставкой», пока не прошёл validation.

### Шаг 4. Review exceptions

Главный экран — таблица:

| Поле | Смысл |
|---|---|
| Товар/размер | что везём |
| Площадка | WB/Ozon |
| Куда | регион/кластер/склад |
| Остаток там | доступный marketplace stock |
| В пути | подтверждённый in-transit |
| Доступно у продавца | после reserve и других allocation |
| Спрос/день | forecast и источник |
| Хватит до | дата OOS |
| Рекомендация | итоговое количество |
| Стоимость | закупка/логистика |
| Уверенность | высокая/средняя/низкая |
| Причина | короткое объяснение |
| Статус | ok/warning/blocked |

Фильтры по умолчанию:

- срочный OOS;
- заблокировано;
- нет себестоимости;
- новый товар;
- отрицательная маржа;
- излишек;
- изменено пользователем.

### Шаг 5. Ручные корректировки

- qty;
- исключить строку;
- перенести в другой destination;
- задать minimum/maximum;
- изменить кратность;
- зафиксировать товар для площадки;
- bulk actions;
- комментарий и причина override.

Каждое изменение пересчитывает бюджет, покрытие и validation.

### Шаг 6. Проверка площадки

WB:

- `POST acceptance/options` по реальным barcode+qty;
- недопустимые строки блокируются;
- после успеха — XLSX и переход в кабинет.

Ozon:

- capabilities;
- точки/кластеры/интервалы;
- preview;
- подтверждение;
- создание draft/order.

### Шаг 7. Исполнение и план-факт

Система связывает recommendation lines с фактической поставкой:

- предложено;
- принято пользователем;
- отправлено;
- принято маркетплейсом;
- продано за горизонт;
- остаток после горизонта;
- ошибка прогноза;
- OOS days;
- финансовый эффект.

## 11. Расчётная логика v1

Цель v1 — не самый сложный прогноз, а воспроизводимый, калибруемый и понятный.

### 11.1. Канонический доступный остаток

```text
destination_available =
    marketplace_sellable_stock
  + confirmed_in_transit
  - reserved_or_blocked_stock
```

Собственный склад распределяется отдельно:

```text
seller_allocatable =
    seller_on_hand
  - seller_reserve
  - already_allocated_to_other_plans
```

FBS-остаток не следует автоматически вычитать из FBW-потребности: сначала нужна политика распределения.

### 11.2. Исторический спрос

Для каждой пары товар × территория:

1. Взять оперативные заказы/продажи.
2. Определить дни OOS и не считать нулевой спрос в эти дни нормальным.
3. Ограничить выбросы или выделить promo.
4. Посчитать окна 7/28/56 дней.
5. Использовать устойчивую комбинацию, а не единственное EWMA.
6. Для WB использовать географию покупателя, а не только склад отгрузки.
7. Для размеров считать на barcode/size уровне.

Пример простой v1:

```text
base_daily =
  0.50 × corrected_avg_28d
  + 0.30 × corrected_avg_7d
  + 0.20 × corrected_avg_56d
```

Вес должен быть частью версии модели, а не пользовательской настройкой.

### 11.3. Прогноз

```text
forecast_daily =
    base_daily
  × seasonality_factor
  × promo_factor
  × manual_override
```

Если нет доказанного источника сезонности или промо — коэффициент равен 1 и это явно видно.

### 11.4. Safety stock

Для товаров с достаточной историей:

```text
safety_stock =
  service_level_z
  × demand_stddev
  × sqrt(lead_time + review_period)
```

Service level задаётся policy:

- A: 97%;
- B: 94%;
- C: 90%.

Это стартовая гипотеза, которую надо калибровать.

Для низкого спроса использовать минимальный запас/ручную policy; позже добавить Croston/SBA.

### 11.5. Потребность

```text
gross_need =
    forecast_daily × (lead_time + target_cover)
  + safety_stock

raw_qty =
    gross_need
  - destination_available

recommended_qty =
  round_up_to_pack(
    clamp(raw_qty, min_batch, max_cover_cap)
  )
```

После этого применяются:

- ограничения категории/склада;
- seller allocatable;
- бюджет;
- экономическая приоритизация;
- marketplace validation.

### 11.6. Бюджет

Если товара меньше потребности:

```text
priority_score =
    expected_margin_loss_avoided
  × oos_probability
  × confidence
  / capital_required
```

Пользователь видит, какие строки были уменьшены бюджетом и почему.

### 11.7. Новый товар

Новый товар — отдельная policy:

- trial batch;
- выбранные территории;
- cap капитала;
- низкая confidence;
- пересчёт после первых 7/14 дней;
- не подмешивать в обычную history-based модель.

## 12. Объяснение, которому поверит пользователь

Вместо сырого explain JSON:

> Рекомендуем 48 шт. в кластер Казань. Продажи с поправкой на отсутствие товара — 2,1 шт./день. В кластере 8 шт., в пути 10 шт. Цель — 21 день, lead time — 5 дней, страховой запас — 4 шт. Расчёт дал 40 шт., округлено до короба 12. Уверенность средняя: 9 дней из последних 28 товар отсутствовал.

Кнопка «Подробно» открывает:

- формулу;
- источники;
- timestamps;
- effective parameters;
- все warnings;
- предыдущую рекомендацию и причину изменения.

## 13. Целевая архитектура

```text
Marketplace ingestion
  → normalized facts
  → data health/readiness
  → immutable planning snapshot
  → Forecast Engine
  → Inventory Allocation
  → Marketplace Adapter
  → Quality Gate
  → Human Review
  → Execution Adapter
  → Supply Tracking
  → Plan-Fact Learning
```

### Компоненты

1. `PlanningFactsService`
2. `DemandForecastService`
3. `SafetyStockPolicy`
4. `InventoryAllocationService`
5. `BudgetOptimizer`
6. `WbFbwPlanningAdapter`
7. `OzonFboPlanningAdapter`
8. `PlanValidationService`
9. `PlanApprovalService`
10. `ExecutionService`
11. `PlanFactService`

Каждый компонент имеет versioned input/output contract и независимые tests.

### Strangler plan

Не удалять текущий job сразу.

1. Добавить `calculation_engine=v1_legacy|v2`.
2. Сначала вынести facts snapshot/readiness.
3. Затем forecast v2.
4. Затем marketplace validation.
5. Запускать v1 и v2 в shadow mode.
6. Сравнивать рекомендации и фактический результат.
7. Перевести beta кабинеты на v2.
8. После доказанной стабильности удалить legacy ветки.

## 14. Метрики продукта и модели

### Продуктовые

- time to first useful plan;
- plan calculation success rate;
- recommendation acceptance rate;
- доля строк с ручным override;
- plan → export/draft conversion;
- execution success rate;
- weekly active planning accounts;
- paid conversion;
- retained planning accounts week 4/8/12.

### Модель

- WAPE;
- weighted bias;
- OOS days;
- excess cover days;
- доля low-confidence;
- unmatched SKU/destination rate;
- stale-data block rate;
- accepted qty / recommended qty;
- actual received / approved qty.

### Бизнес

- предотвращённая маржинальная потеря;
- капитал в избыточном запасе;
- стоимость хранения;
- логистические расходы;
- экономия рабочего времени.

Нельзя заявлять causal uplift без контрольной группы или хотя бы стабильного before/after с поправкой на сезонность.

## 15. Roadmap на 12 недель

### Этап 0 — неделя 1: заморозить и измерить

- зафиксировать текущий algorithm build;
- собрать 5–10 beta кабинетов WB/Ozon;
- сохранить legacy output;
- добавить contract fixtures;
- определить SLA и launch metrics;
- запретить новые эвристики до завершения ядра.

Выход: известна baseline точность и доля неготовых данных.

### Этап 1 — недели 2–3: data foundation

- исправить WB API contract;
- исправить WB coefficient availability;
- убрать Ozon first-row fallback;
- strict integration scoping;
- единый freshness registry;
- фактический data-health;
- обязательный preflight;
- Ozon OAuth/rotation health;
- immutable fact snapshot v2.

Выход: ни один план нельзя исполнить на старых/неоднозначных данных.

### Этап 2 — недели 4–6: расчётное ядро v2

- канонический available stock;
- demand windows с OOS correction;
- отдельная new-product policy;
- percentile ABC;
- service-level safety stock;
- pack/min/max;
- budget allocation;
- versioned effective parameters;
- shadow comparison v1/v2.

Выход: воспроизводимый расчёт с model card.

### Этап 3 — недели 7–9: коммерческий workflow

- data readiness screen;
- exception-first review table;
- ручные правки и bulk actions;
- короткие объяснения;
- approval state;
- WB validation + XLSX;
- Ozon preview + confirm + draft/order;
- аудит всех действий.

Выход: пользователь завершает поставку без работы с внутренними JSON и разрозненными разделами.

### Этап 4 — недели 10–12: план-факт и beta

- link recommendation → marketplace supply;
- status sync;
- received qty;
- WAPE/bias;
- alerts;
- onboarding;
- тарифные лимиты;
- support playbook;
- beta go/no-go.

Выход: измеримый коммерческий продукт, а не калькулятор.

## 16. Launch gates

Нельзя запускать платный продукт, пока не выполнено:

- 0 известных P0 contract mismatches;
- 100% executable plans имеют fresh facts snapshot;
- 0 cross-integration facts;
- 100% WB export прошли barcode+qty validation;
- 100% Ozon execution требуют explicit confirmation;
- 0 export/execution при bad quality;
- unmatched destination < 0,5%;
- calculation success > 99%;
- source freshness success > 98%;
- WAPE и bias измеряются минимум на 4-недельном горизонте;
- все ручные правки и executions аудируются;
- rollback на legacy/previous model version проверен;
- минимум 5 реальных кабинетов завершили 3 цикла поставки.

## 17. Приоритетный backlog

### P0 — до любого релиза

1. WB `POST /acceptance/options` с barcode+qty.
2. WB availability только `0/1 + allowUnload`.
3. Quality gate для всех export/execution.
4. Разделить calculation и approval status.
5. Реальный freshness/readiness.
6. Убрать/подключить игнорируемые параметры.
7. Запретить Ozon first-row fallback.
8. Убрать unscoped unit economics fallback.
9. Разделить WB export по destination.
10. Contract tests Ozon macrolocal cluster.

### P1 — до платной beta

1. Ozon OAuth/rotation monitoring.
2. Available stock semantics.
3. New product policy.
4. ABC по доле вклада.
5. Versioned model/effective parameters.
6. Review table и human-readable explanations.
7. WB size/barcode completeness gate.
8. Plan-fact linking.

### P2 — после beta

1. Cross-market allocation.
2. Продвинутая сезонность.
3. Intermittent demand model.
4. Multi-echelon собственный склад → marketplace.
5. Purchase order/production planning.
6. Public API и ERP/WMS integrations.
7. Controlled experiments по locality и прибыльности.

## 18. Что не надо делать

- Не добавлять ещё один «умный коэффициент» в текущий job.
- Не делать полный rewrite до появления baseline.
- Не обещать автосоздание WB FBW.
- Не использовать browser automation для кабинетов.
- Не делать AI-summary центральной функцией.
- Не показывать EWMA alpha пользователю.
- Не считать старые данные просто warning.
- Не строить позиционирование только вокруг локальности/KTR.
- Не считать прохождение unit tests доказательством корректности API.
- Не запускать платный тариф без plan-fact.

## 19. Рекомендованное коммерческое позиционирование

### Основной оффер

> Выполнимый план поставок WB и Ozon за 15 минут: остатки, спрос, товар в пути, бюджет и ограничения площадки в одном расчёте.

### Три доказательства

1. Каждая цифра объяснена и привязана к свежему источнику.
2. Невыполнимая поставка не пройдёт в экспорт.
3. Ozon-поставка создаётся после подтверждения; WB-план готов к загрузке и затем отслеживается.

### Тарифная гипотеза

- Start: 3 490–3 990 ₽/мес.;
- Pro: 7 990–9 990 ₽/мес.;
- Business: по договору.

Тарифировать по active SKU и подключённым кабинетам, а не по числу расчётов.

## 20. Финальная рекомендация

У проекта уже есть достаточно кода, чтобы за короткий срок получить сильный продукт. Но коммерческая ценность появится не от дальнейшего усложнения формулы.

Нужная последовательность:

```text
достоверные данные
→ простая воспроизводимая модель
→ marketplace validation
→ понятная ручная проверка
→ безопасное исполнение
→ план-факт
→ обучение модели
```

Первый инженерный спринт должен быть посвящён не UI и не новым режимам, а десяти P0 из раздела backlog. После этого можно безопасно строить пользовательский интерфейс и запускать beta.

## Источники

Официальные:

- [Wildberries: Поставки FBW](https://dev.wildberries.ru/docs/openapi/orders-fbw)
- [Wildberries: Тарифы](https://dev.wildberries.ru/ru/openapi/wb-tariffs)
- [Wildberries: Рекомендации по поставкам](https://seller.wildberries.ru/instructions/ru/ru/material/recommendations-for-the-supply-of-goods)
- [Wildberries: Аналитика](https://dev.wildberries.ru/en/openapi/analytics)
- [Ozon: Изменения FBO API](https://dev.ozon.ru/news/647-Izmeneniia-v-metodakh-Seller-API-pri-rabote-s-postavkami-FBO/)
- [Ozon: Срок API-ключей и OAuth](https://dev.ozon.ru/news/649-Obnovlenie-pravil-raboty-s-API-kliuchami-Vazhnye-izmeneniia-v-rabote-s-Ozon-Seller-API/)
- [Ozon: Публичный Seller API](https://dev.ozon.ru/start/298-Seller-API-kak-izbezhat-blokirovok/)

Рынок:

- [Поставлено!](https://postavleno.ru/)
- [Vendber](https://vendber.ru/)
- [Мастер Поставки](https://masterpostavki.ru/)
- [MetricLab API](https://web.metriclab.ru/api-doc/)
- [Поставыч](https://postavich.ru/)
- [Nitroseller](https://www.nitroseller.ru/)
