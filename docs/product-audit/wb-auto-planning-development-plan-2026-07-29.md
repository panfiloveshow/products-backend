# План разработки автопланирования поставок Wildberries FBW

Дата: 29 июля 2026 года.

Горизонт: 8 недель до закрытой коммерческой beta.

## 1. Результат

Пользователь:

1. Подключает кабинет WB.
2. Видит готовность каталога, баркодов, размеров, остатков и спроса.
3. Получает план `артикул + размер/баркод × регион/склад`.
4. Проверяет OOS, покрытие, FBS reserve, товар в пути, бюджет и тарифы.
5. Корректирует строки.
6. Sellico проверяет коэффициент приёмки.
7. Sellico вызывает acceptance options с фактическими баркодами и количествами.
8. Пользователь получает отдельный XLSX для каждой поставки/назначения.
9. Создаёт FBW-поставку в кабинете WB.
10. Sellico находит и связывает созданную поставку, затем ведёт план-факт.

Коммерческое обещание:

> Проверенный план WB FBW по регионам, размерам и баркодам, готовый к загрузке в кабинет.

## 2. Реальная граница API

Публичный API WB позволяет:

- получать каталог и баркоды;
- получать остатки по складам;
- получать заказы, продажи и финансовый факт;
- получать тарифы и коэффициенты приёмки;
- проверять `barcode + quantity` через acceptance options;
- читать существующие FBW-поставки и их состав.

Публичный API WB не позволяет:

- создать новую плановую FBW-поставку;
- добавить в неё товары;
- забронировать дату FBW.

Методы создания поставки FBS относятся к сборочным заданиям покупателей и не являются заменой FBW replenishment.

Официальный источник: [документация поставок FBW](https://dev.wildberries.ru/docs/openapi/orders-fbw).

## 3. Граница MVP

Входит:

- WB FBW;
- детализация до баркода/размера;
- география спроса;
- WB stock;
- FBS/seller stock как отдельный резерв;
- существующие FBW-поставки как in-transit;
- safety stock, cover, кратность;
- тарифы и коэффициенты;
- бюджет;
- review;
- acceptance validation;
- отдельные XLSX по назначениям;
- linking с созданной поставкой;
- план-факт.

Не входит:

- автоматическое создание и бронирование FBW;
- смешивание FBW и FBS execution;
- browser automation;
- закупки/производство;
- прогноз спроса конкурентов WB, недоступный через публичный Seller API;
- обещания роста выдачи.

## 4. Критические исправления до разработки функций

### WB-P0.1. Исправить acceptance options

Сейчас:

```text
GET /api/v1/acceptance/options
warehouseId + dates
```

Должно быть:

```text
POST /api/v1/acceptance/options
query: warehouseID optional
body: [{barcode, quantity}, ...]
```

До 5000 позиций в одном запросе.

### WB-P0.2. Исправить правило доступности

Сейчас `coefficient > 1` считается платным, но доступным.

Официальное правило:

```text
available =
  coefficient in [0, 1]
  AND allowUnload = true
```

Источник: [WB API тарифов](https://dev.wildberries.ru/ru/openapi/wb-tariffs).

### WB-P0.3. Не объединять разные назначения в один Excel

Текущий export суммирует строки только по barcode. Если один товар рекомендован в несколько направлений, итоговый файл теряет склад/регион.

Нужно:

- один XLSX на одну будущую FBW-поставку/назначение;
- либо ZIP с файлами и manifest;
- имя файла содержит destination и plan ID;
- сумма файлов равна утверждённому плану;
- один barcode может присутствовать в нескольких destination-файлах.

### WB-P0.4. Запретить export плохого плана

Export разрешён только если:

- plan approved;
- facts fresh;
- barcode mapping однозначен;
- destination допустим;
- acceptance options пройдены после последних изменений;
- fingerprint плана совпадает с validation fingerprint.

## 5. Что переиспользуем

- `AutoSupplyPlan`;
- snapshot/readiness/quality services;
- WB products и barcode mapping;
- текущие остатки по складам;
- tariff synchronization;
- existing supply read APIs;
- plan line editing;
- budget optimizer;
- plan-fact задел.

Что выделяем:

- `WbPlanningFactsBuilder`;
- `WbDemandForecaster`;
- `WbRegionAllocator`;
- `WbAcceptanceCoefficientsApi`;
- `WbAcceptanceOptionsApi`;
- `WbPlanExportService`;
- `WbSupplyLinker`.

## 6. Целевой пользовательский поток

```text
READINESS
  → SYNC
  → CALCULATE
  → REVIEW_REQUIRED
  → COEFFICIENT_CHECK
  → ACCEPTANCE_OPTIONS_CHECK
  → READY_TO_APPROVE
  → APPROVED
  → EXPORTED
  → WAITING_FOR_WB_SUPPLY
  → LINKED
  → IN_TRANSIT
  → RECEIVED
  → RECONCILED
```

Блокирующие состояния:

```text
DATA_BLOCKED
BARCODE_BLOCKED
DESTINATION_BLOCKED
VALIDATION_EXPIRED
CANCELLED
```

## 7. Команда

Минимально:

- 1 backend lead;
- 1 backend developer на первые 3–4 недели;
- 1 frontend developer;
- 0,5 QA/automation;
- 0,25 product/data analyst.

Если общий фундамент Ozon уже готов, WB-план сокращается примерно на 1–2 недели.

## 8. Этапы

### Этап WB-0. External contracts и безопасность — неделя 1

Backend:

- разделить coefficients и acceptance options в два клиента;
- реализовать правильный POST contract;
- обновить fixtures;
- исправить правило `0/1 + allowUnload`;
- добавить batching до 5000 строк;
- fingerprint validation;
- блокировать export по quality/freshness;
- добавить destination-aware export model;
- удалить тестовые ожидания, закрепляющие коэффициент >1 как доступный.

Tests:

- coefficient -1, 2, 5 → unavailable;
- coefficient 0/1 + allowUnload → available;
- coefficient 0/1 + `allowUnload=false` → unavailable;
- acceptance request содержит barcode/quantity;
- изменение qty инвалидирует validation;
- два destination не объединяются.

Definition of Done:

- текущая реализация не может экспортировать логически неверную матрицу.

### Этап WB-1. Нормализованные данные — недели 1–2

Источники:

- карточки и артикулы;
- размеры и баркоды;
- stock report по складам WB;
- orders/sales;
- realization report;
- FBS/seller stock;
- FBW supplies и товары в них;
- warehouses/transit directions;
- tariffs;
- acceptance coefficients.

Backend:

- `WbPlanningFactsBuilder`;
- canonical product key `nm_id + barcode + size`;
- canonical destination;
- точное integration scoping;
- фактическая свежесть;
- coverage каталог/баркоды;
- inventory snapshot;
- deduplicated in-transit;
- separate preliminary demand и final finance;
- ошибки rate limit сохраняются и показываются.

Definition of Done:

- 100% планируемых строк имеют barcode;
- размерные товары не схлопываются в один артикул;
- stock endpoint соответствует актуальному контракту;
- каждый источник имеет timestamp и coverage.

Официальные источники:

- [WB Analytics API](https://dev.wildberries.ru/en/openapi/analytics)
- [WB Reports API](https://dev.wildberries.ru/en/openapi/reports)
- [Финансовые отчёты WB](https://dev.wildberries.ru/docs/openapi/financial-reports-and-accounting)

### Этап WB-2. Прогноз и география — недели 3–4

Цель: распределять по реальному спросу покупателей, не только по истории склада.

Backend:

- спрос `barcode/size × buyer region`;
- OOS correction;
- окна 7/28/56;
- возвраты/выкуп как отдельный факт;
- launch policy нового товара;
- size curve;
- target cover;
- safety stock;
- FBS/seller reserve;
- in-transit;
- pack multiples;
- ABC по contribution;
- confidence.

Правило size curve:

```text
article_total_need
  × stable_share_of_size
  → size_need
  → round_to_pack
```

Для размера без достаточной истории:

- использовать общую кривую артикула;
- либо ручную size curve;
- confidence = low;
- ограничить trial quantity.

Definition of Done:

- одежда/обувь рассчитываются по barcode/size;
- OOS не интерпретируется как нулевой спрос;
- новый товар не исчезает из расчёта;
- FBS reserve не распределяется дважды.

### Этап WB-3. Allocation по регионам и складам — недели 4–5

Цель: превратить потребность региона в выполнимые назначения.

Backend:

- region demand;
- список допустимых складов;
- category/package restrictions;
- current stock;
- acceptance coefficients;
- delivery/storage tariff;
- max cover;
- seller availability;
- budget;
- приоритет OOS;
- fallback только на подтверждённый alternate destination.

Алгоритм:

```text
1. Рассчитать потребность региона.
2. Исключить запрещённые направления.
3. Оценить доступность и стоимость.
4. Выбрать primary destination.
5. Если primary unavailable — показать alternate.
6. Не перераспределять молча.
7. После ручного выбора повторить validation.
```

Definition of Done:

- каждая строка имеет region и destination;
- причина выбора объяснена;
- unavailable destination не получает qty;
- альтернативы видны пользователю.

### Этап WB-4. Review UX — недели 4–6

Экран готовности:

- каталог;
- баркоды;
- размеры;
- stock;
- orders;
- supplies;
- tariffs;
- coefficients;
- freshness;
- ошибки.

Главная таблица:

- товар;
- размер;
- barcode;
- регион;
- склад;
- остаток;
- FBS reserve;
- в пути;
- спрос;
- OOS date;
- cover;
- рекомендация;
- кратность;
- коэффициент;
- стоимость;
- confidence;
- status.

Фильтры:

- срочно;
- нет barcode;
- низкая confidence;
- недоступная приёмка;
- новый товар;
- излишек;
- отрицательная маржа;
- изменено вручную.

Действия:

- изменить qty;
- изменить склад;
- исключить;
- bulk edit;
- size curve;
- reserve;
- комментарий;
- пересчитать.

Definition of Done:

- менеджер видит проблемы до Excel;
- raw JSON не нужен;
- размерные SKU редактируются независимо;
- сумма доступного собственного товара не превышается.

### Этап WB-5. Финальная validation и export — недели 6–7

Backend:

1. Сгруппировать план по destination.
2. Сформировать body `barcode + quantity`.
3. Вызвать acceptance options.
4. Сохранить response snapshot и fingerprint.
5. Заблокировать недоступные строки.
6. Дать альтернативы.
7. После approval создать отдельные XLSX.
8. Сформировать manifest:
   - destination;
   - строк;
   - единиц;
   - сумма;
   - validation time;
   - warnings.

Frontend:

- validation progress;
- список ошибок;
- замена склада;
- approve;
- скачать файлы/ZIP;
- открыть кабинет WB;
- чек-лист создания поставки.

Definition of Done:

- каждый экспорт соответствует одному destination;
- `Σ export qty = Σ approved qty`;
- изменение плана инвалидирует export;
- файлы открываются и соответствуют шаблону WB;
- blocked line не попадает в файл.

### Этап WB-6. Linking и план-факт — недели 7–8

Цель: вернуть ручной шаг WB в измеримый контур.

Backend:

- опрашивать список новых FBW-поставок;
- предлагать candidate match по кабинету, дате, destination и составу;
- пользователь подтверждает link;
- не связывать неоднозначно автоматически;
- синхронизировать товары и status;
- accepted quantity;
- partial acceptance;
- rejected/unloading quantity;
- reconciliation.

Frontend:

- «Мы нашли созданную поставку»;
- сравнение plan vs supply;
- подтверждение связи;
- timeline;
- расхождения;
- WAPE/bias/OOS/excess.

Definition of Done:

- не менее 95% beta-поставок связываются автоматически или в один клик;
- неоднозначная связь требует решения пользователя;
- исходный план неизменяем;
- факт приёмки виден по barcode.

## 9. API продукта

```text
GET    /api/auto-supply-plans/wb/readiness
POST   /api/auto-supply-plans
POST   /api/auto-supply-plans/{id}/calculate
GET    /api/auto-supply-plans/{id}
PATCH  /api/auto-supply-plans/{id}/lines/{line}
POST   /api/auto-supply-plans/{id}/wb/check-coefficients
POST   /api/auto-supply-plans/{id}/wb/validate-options
POST   /api/auto-supply-plans/{id}/approve
GET    /api/auto-supply-plans/{id}/wb/exports
POST   /api/auto-supply-plans/{id}/wb/find-supplies
POST   /api/auto-supply-plans/{id}/wb/link-supply
GET    /api/auto-supply-plans/{id}/plan-fact
```

## 10. Нефункциональные требования

- calculation p95 ≤ 90 секунд для 5000 barcode;
- acceptance validation учитывает лимит запросов;
- 0 export без fresh validation;
- 0 объединений разных destination;
- 0 строк без однозначного barcode;
- 0 cross-integration facts;
- audit ручных изменений;
- rate-limit backoff;
- sync progress;
- structured logging.

## 11. Beta

Состав:

- 5–7 WB кабинетов;
- минимум 2 размерных категории;
- 100–5000 barcode;
- разные регионы;
- продавцы с FBS reserve и без него;
- минимум 3 цикла поставки на кабинет.

Метрики:

- validation error rate измеряется и объясняется;
- stale-data export = 0;
- destination merge error = 0;
- barcode mapping ≥ 99,5%;
- ≥ 70% рекомендаций приняты/осознанно изменены;
- review ≤ 15 минут у 90% пользователей;
- link rate с созданной WB-поставкой ≥ 95%;
- 80% пользователей возвращаются к следующему циклу.

## 12. Порядок реализации задач

```text
WB-0 contracts/export safety
  → WB-1 normalized facts
  → WB-2 demand/size
  → WB-3 region allocation
  → WB-4 review UX
  → WB-5 validation/export
  → WB-6 link/plan-fact
```

WB-0 нельзя откладывать: текущая реализация может формировать невыполнимый или неверно сгруппированный экспорт.

