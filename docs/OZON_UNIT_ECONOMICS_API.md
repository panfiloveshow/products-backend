# Unit Economics API для Ozon — Полная документация

## Содержание
1. [API Endpoints](#api-endpoints)
2. [Схемы работы Ozon](#схемы-работы-ozon)
3. [Формулы расчёта](#формулы-расчёта)
4. [Тарифы (декабрь 2025)](#тарифы-декабрь-2025)
5. [Поля модели UnitEconomics](#поля-модели-uniteconomics)
6. [Примеры запросов](#примеры-запросов)
7. [Правила и ограничения](#правила-и-ограничения)

---

## API Endpoints

### Основные роуты Unit Economics

| Метод | URL | Описание |
|-------|-----|----------|
| `GET` | `/api/unit-economics` | Список всех записей юнит-экономики |
| `GET` | `/api/unit-economics/{marketplace}` | Записи по маркетплейсу (ozon, wildberries, yandex) |
| `GET` | `/api/unit-economics/{marketplace}/{sku}` | Детали по SKU |
| `POST` | `/api/unit-economics/{marketplace}` | Создать запись |
| `PUT` | `/api/unit-economics/{marketplace}/{id}` | Обновить запись |
| `DELETE` | `/api/unit-economics/{marketplace}/{id}` | Удалить запись |
| `POST` | `/api/unit-economics/calculate/{marketplace}` | Рассчитать юнит-экономику |
| `POST` | `/api/unit-economics/save` | Массовое сохранение |
| `POST` | `/api/unit-economics/sync/{integrationId}` | Синхронизация (фоновая) |
| `POST` | `/api/unit-economics/sync-now/{integrationId}` | Синхронизация (синхронная) |
| `GET` | `/api/unit-economics/comparison` | Сравнение маркетплейсов |
| `GET` | `/api/unit-economics/product-comparison` | Сравнение товаров |
| `GET` | `/api/unit-economics/stats` | Общая статистика |
| `GET` | `/api/unit-economics/stats/{marketplace}` | Статистика по маркетплейсу |
| `GET` | `/api/unit-economics/commissions/{marketplace}` | Комиссии по категориям |
| `GET` | `/api/unit-economics/tariffs/{marketplace}` | Тарифы маркетплейса |

### Дополнительные роуты (Integrations)

| Метод | URL | Описание |
|-------|-----|----------|
| `GET` | `/api/integrations/{id}/premium-status` | Проверка Premium статуса |
| `POST` | `/api/integrations/{id}/redemption-rate` | Установить % выкупа вручную |

---

## Схемы работы Ozon

> **Важно:** Параметр `fulfillment_type` **пересчитывает тарифы** для выбранной схемы, а не фильтрует товары. Один товар можно просмотреть с расчётами для любой схемы.

### Сравнение схем

| Параметр | FBO | FBS | realFBS | Express |
|----------|-----|-----|---------|---------|
| Логистика базовая | 46.77₽/л | 81.34₽/л | своя | своя |
| Последняя миля | 25₽ | 10₽ | - | - |
| Обработка | - | 20-30₽ | - | - |
| Коэфф. времени | да | нет | нет | нет |
| Хранение | да | нет | нет | нет |
| Комиссия (дефолт) | 15% | 21% | 22% | 25% |

### FBO (Fulfillment by Ozon)
Товар хранится на складе Ozon. Ozon полностью обрабатывает заказы.

**Затраты:**
- Комиссия за продажу
- Логистика × Коэффициент времени доставки
- Дополнительная комиссия (% от цены)
- Последняя миля (25₽)
- Хранение (по оборачиваемости)
- Эквайринг (до 1.5%)
- Обратная логистика (при возвратах)

### FBS (Fulfillment by Seller)
Товар на складе продавца, доставка через Ozon.

**Затраты:**
- Комиссия за продажу
- Обработка отправления (20₽ СЦ / 30₽ ПВЗ)
- Логистика FBS
- Доставка до места выдачи (10₽)
- Эквайринг (до 1.5%)
- Обратная логистика (при возвратах)

### realFBS / DBS (Delivery by Seller)
Своя доставка продавца.

**Затраты:**
- Комиссия за продажу
- Своя логистика
- Минус компенсация от Ozon (до 799₽ для КГТ)
- Эквайринг (до 1.5%)

### Express
Экспресс-доставка своими силами.

**Затраты:**
- Комиссия за продажу (повышенная, до 25%)
- Своя логистика (курьерская служба)
- Эквайринг (до 1.5%)
- Нет компенсации от Ozon

---

## Формулы расчёта

### Основные формулы

```php
// Выручка
$revenue = $price × $salesCount;

// Комиссия
$commissionAmount = $price × ($commissionPercent / 100);

// Эквайринг
$acquiringAmount = $price × ($acquiringPercent / 100);

// Налоги (УСН 6% по умолчанию)
$taxAmount = $price × ($taxPercent / 100);
$vatAmount = $price × ($vatPercent / 100);

// РК (ДРР) — рекламные расходы
$drrAmount = $price × ($drrPercent / 100);

// Наша часть
$ourShareAmount = $price × ($ourSharePercent / 100);

// Затраты МП (без себестоимости)
$marketplaceCosts = $commissionAmount + $logisticsCost + $processingCost 
                  + $acquiringAmount + $storageCost + $additionalCommission;

// На расчётный счёт (за единицу)
$toSettlementAccount = $price - $marketplaceCosts - $drrAmount 
                     - $taxAmount - $vatAmount - $ourShareAmount;

// Прибыль (за единицу)
$netProfit = $toSettlementAccount - $costPrice;

// Маржа %
$marginPercent = ($netProfit / $price) × 100;

// Наценка (множитель)
$markupPercent = $price / $costPrice;

// ROI %
$roiPercent = ($netProfit × $salesCount / $totalCosts) × 100;
```

### Логистика FBO (декабрь 2025)

```php
// Базовый тариф по объёму (для товаров от 301₽)
function calculateFboBaseLogistics($volumeLiters, $price) {
    if ($price <= 300) {
        return $volumeLiters × 17.28; // Фикс для дешёвых товаров
    }
    
    if ($volumeLiters > 1000) {
        return 7859.86; // Фикс для крупногабарита
    }
    
    $cost = 46.77; // Первый литр
    
    if ($volumeLiters > 1) {
        // Литры 2-3: +10.17₽ за литр
        $cost += min($volumeLiters - 1, 2) × 10.17;
    }
    
    if ($volumeLiters > 3) {
        // Литры 4-190: +15.25₽ за литр
        $cost += min($volumeLiters - 3, 187) × 15.25;
    }
    
    if ($volumeLiters > 190) {
        // Литры 191-1000: +6.10₽ за литр
        $cost += ($volumeLiters - 190) × 6.10;
    }
    
    return $cost;
}

// Коэффициент времени доставки (с 07.04.2025)
// Среднее время доставки → Коэффициент → Доп. комиссия %
// 29ч → 1.000 → 0.00%
// 30ч → 1.050 → 0.25%
// 35ч → 1.320 → 1.60%
// 40ч → 1.510 → 2.55%
// 50ч → 1.760 → 3.80%
// 61ч+ → 1.800 → 4.00%

// Логистика с коэффициентом
$logisticsWithCoefficient = $baseLogistics × $coefficient;

// Дополнительная комиссия (от цены, не от логистики!)
$additionalCommission = $price × ($additionalPercent / 100);

// Последняя миля (с 01.06.2025)
$lastMile = 25.00;

// Стоимость доставки
$deliveryCost = $logisticsWithCoefficient + $lastMile;
```

### Обратная логистика (возвраты)

```php
// Обратная логистика = базовый тариф БЕЗ коэффициента + обработка 15₽
$returnLogistics = $baseLogistics + 15.00;

// Ожидаемая стоимость возвратов
$returnRate = (100 - $redemptionRate) / 100;
$expectedReturnCost = $returnLogistics × $returnRate;

// Эффективная логистика (итого с учётом возвратов)
$effectiveLogistics = $deliveryCost + $expectedReturnCost;
```

### Логистика FBS (декабрь 2025)

```php
function calculateFbsLogistics($volumeLiters, $price) {
    if ($price <= 300) {
        return $volumeLiters × 1.9; // Фикс для дешёвых
    }
    
    if ($volumeLiters > 1000) {
        return 9432.87; // Фикс для крупногабарита
    }
    
    $cost = 81.34; // Первый литр
    
    if ($volumeLiters > 1) {
        // Литры 2-3: +18.30₽ за литр
        $cost += min($volumeLiters - 1, 2) × 18.30;
    }
    
    if ($volumeLiters > 3) {
        // Литры 4-190: +23.39₽ за литр
        $cost += min($volumeLiters - 3, 187) × 23.39;
    }
    
    if ($volumeLiters > 190) {
        // Литры 191-1000: +6.10₽ за литр
        $cost += ($volumeLiters - 190) × 6.10;
    }
    
    return $cost;
}

// Обработка отправления (с 10.12.2025)
$processingCost = 20; // СЦ (с доверительной приёмкой 10₽)
// или
$processingCost = 30; // ПВЗ/ППЗ

// Доставка до места выдачи
$lastMile = 10.00;
```

### Хранение FBO

```php
function calculateFboStorage($volumeLiters, $turnoverDays) {
    if ($turnoverDays <= 160) {
        return 0; // Бесплатно
    }
    
    if ($turnoverDays <= 180) {
        return $volumeLiters × 0.75; // ₽/л
    }
    
    return $volumeLiters × 1.5; // ₽/л (более 180 дней)
}
```

---

## Тарифы (декабрь 2025)

### FBO Логистика

| Объём | Тариф |
|-------|-------|
| 0-1 л | 46.77₽ |
| 1-3 л | +10.17₽/л |
| 3-190 л | +15.25₽/л |
| 190-1000 л | +6.10₽/л |
| >1000 л | 7859.86₽ фикс |

### FBS Логистика

| Объём | Тариф |
|-------|-------|
| 0-1 л | 81.34₽ |
| 1-3 л | +18.30₽/л |
| 3-190 л | +23.39₽/л |
| 190-1000 л | +6.10₽/л |
| >1000 л | 9432.87₽ фикс |

### Коэффициенты времени доставки FBO

| Время (ч) | Коэфф. | Доп. % |
|-----------|--------|--------|
| ≤29 | 1.000 | 0.00% |
| 30 | 1.050 | 0.25% |
| 35 | 1.320 | 1.60% |
| 40 | 1.510 | 2.55% |
| 45 | 1.660 | 3.30% |
| 50 | 1.760 | 3.80% |
| 55 | 1.788 | 3.94% |
| 60 | 1.798 | 3.99% |
| ≥61 | 1.800 | 4.00% |

### Прочие тарифы

| Услуга | Тариф |
|--------|-------|
| Последняя миля FBO | 25₽ |
| Последняя миля FBS | 10₽ |
| Обработка FBS (СЦ) | 20₽ |
| Обработка FBS (ПВЗ) | 30₽ |
| Обработка возврата | 15₽ |
| Эквайринг | до 1.5% |
| Хранение (161-180 дн) | 0.75₽/л |
| Хранение (>180 дн) | 1.5₽/л |

---

## Поля модели UnitEconomics

### Основные поля

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | int | ID записи |
| `product_id` | int | ID товара |
| `integration_id` | int | ID интеграции |
| `sku` | string | Артикул товара |
| `marketplace` | string | Маркетплейс (ozon) |
| `product_name` | string | Название товара |
| `price` | decimal | Цена продажи |
| `cost_price` | decimal | Себестоимость (вводится вручную) |
| `sales_count` | int | Количество продаж |
| `fulfillment_type` | string | Схема работы (FBO/FBS/DBS) |

### Расчётные поля

| Поле | Тип | Описание |
|------|-----|----------|
| `revenue` | decimal | Выручка |
| `total_costs` | decimal | Общие затраты |
| `gross_profit` | decimal | Валовая прибыль |
| `net_profit` | decimal | Чистая прибыль (за единицу) |
| `margin_percent` | decimal | Маржа % |
| `markup_percent` | decimal | Наценка (множитель) |
| `roi_percent` | decimal | ROI % |
| `to_settlement_account` | decimal | На расчётный счёт |

### Поля комиссий и логистики

| Поле | Тип | Описание |
|------|-----|----------|
| `commission_percent` | decimal | Комиссия % |
| `commission_amount` | decimal | Комиссия ₽ |
| `base_logistics_cost` | decimal | Базовая логистика |
| `logistics_coefficient` | decimal | Коэффициент времени |
| `logistics_with_coefficient` | decimal | Логистика с коэфф. |
| `logistics_cost` | decimal | Итого логистика |
| `last_mile_cost` | decimal | Последняя миля |
| `processing_cost` | decimal | Обработка (FBS) |
| `delivery_cost` | decimal | Стоимость доставки |
| `effective_logistics` | decimal | Эффективная логистика |

### Поля возвратов

| Поле | Тип | Описание |
|------|-----|----------|
| `redemption_rate` | decimal | % выкупа |
| `return_logistics_cost` | decimal | Обратная логистика |
| `return_processing_cost` | decimal | Обработка возврата |
| `expected_return_cost` | decimal | Ожидаемые возвраты |
| `orders_count` | int | Количество заказов |
| `returns_count` | int | Количество возвратов |

### Поля хранения

| Поле | Тип | Описание |
|------|-----|----------|
| `storage_cost` | decimal | Стоимость хранения |
| `storage_days` | int | Дней хранения |
| `turnover_days` | int | Оборачиваемость (дни) |
| `litrobonus` | decimal | Литробонусы |

### Поля налогов и расходов (вводятся вручную)

| Поле | Тип | Описание |
|------|-----|----------|
| `drr_percent` | decimal | РК % (ДРР) |
| `drr_amount` | decimal | РК сумма |
| `our_share_percent` | decimal | Наша часть % |
| `our_share_amount` | decimal | Наша часть сумма |
| `tax_percent` | decimal | Налог % (УСН) |
| `tax_amount` | decimal | Налог сумма |
| `vat_percent` | decimal | НДС % |
| `vat_amount` | decimal | НДС сумма |
| `acquiring_percent` | decimal | Эквайринг % |
| `acquiring_amount` | decimal | Эквайринг сумма |

### Дополнительные поля

| Поле | Тип | Описание |
|------|-----|----------|
| `additional_commission_percent` | decimal | Доп. комиссия % |
| `additional_commission_amount` | decimal | Доп. комиссия ₽ |
| `avg_delivery_time_hours` | int | Среднее время доставки (ч) |
| `volume_liters` | decimal | Объём (л) |
| `volume_weight` | decimal | Объёмный вес |
| `actual_weight` | decimal | Фактический вес |
| `is_actual_scheme` | bool | Фактическая схема |
| `is_in_promotion` | bool | Участвует в акции |
| `promotion_discount` | decimal | Скидка по акции |
| `seller_price` | decimal | Цена продавца |
| `marketing_seller_price` | decimal | Маркетинговая цена |

---

## Примеры запросов

### Получить юнит-экономику для Ozon

```bash
# Без схемы — возвращает товары с их фактическими схемами
curl -X GET "http://localhost:8000/api/unit-economics/ozon?integration_id=13&limit=50"

# С указанием схемы — ПЕРЕСЧИТЫВАЕТ тарифы для выбранной схемы
curl -X GET "http://localhost:8000/api/unit-economics/ozon?integration_id=13&fulfillment_type=FBS&limit=50"
```

**Параметры:**
- `integration_id` — ID интеграции (обязательно)
- `search` — поиск по SKU/названию
- `fulfillment_type` — **FBO/FBS/REALFBS/EXPRESS** (пересчитывает тарифы, НЕ фильтрует!)
- `profitability` — profitable/unprofitable
- `margin_min`, `margin_max` — диапазон маржи
- `price_min`, `price_max` — диапазон цены
- `sort` — поле сортировки
- `sort_order` — asc/desc
- `limit` — количество записей (по умолчанию 50)
- `page` — номер страницы

> **Важно:** При передаче `fulfillment_type` бекенд берёт уникальные товары и **пересчитывает** их юнит-экономику для указанной схемы. Это позволяет сравнить прибыльность одного товара при разных схемах работы.

**Ответ (с пересчётом для FBS):**
```json
{
  "data": {
    "items": [
      {
        "id": 1,
        "sku": "3-02/3515",
        "product_name": "Лента кассовая",
        "marketplace": "ozon",
        "fulfillment_type": "FBS",
        "is_actual_scheme": false,
        "original_scheme": "FBO",
        "price": "3336.00",
        "cost_price": "901.56",
        "fulfillment_type": "FBO",
        "net_profit": "1234.56",
        "margin_percent": "37.00",
        "commission_percent": "15.00",
        "effective_logistics": "144.97",
        "redemption_rate": "80.00",
        ...
      }
    ],
    "total": 101,
    "scheme_counts": {
      "FBO": { "count": 80, "actual_count": 80 },
      "FBS": { "count": 21, "actual_count": 21 }
    }
  },
  "stats": {
    "total_revenue": 1234567.89,
    "total_costs": 987654.32,
    "total_profit": 246913.57,
    "average_margin": 20.00,
    "average_roi": 25.00,
    "total_sales": 5000,
    "profitable_products": 85,
    "unprofitable_products": 16
  }
}
```

### Рассчитать юнит-экономику

```bash
curl -X POST "http://localhost:8000/api/unit-economics/calculate/ozon" \
  -H "Content-Type: application/json" \
  -d '{
    "price": 1600,
    "cost_price": 500,
    "sales_count": 100,
    "fulfillment_type": "FBO",
    "volume_liters": 1,
    "avg_delivery_time_hours": 35,
    "redemption_rate": 80,
    "commission_percent": 15,
    "drr_percent": 5,
    "tax_percent": 6
  }'
```

### Обновить запись

```bash
curl -X PUT "http://localhost:8000/api/unit-economics/ozon/123" \
  -H "Content-Type: application/json" \
  -d '{
    "cost_price": 550,
    "drr_percent": 7,
    "our_share_percent": 10,
    "tax_percent": 6
  }'
```

### Массовое сохранение

```bash
curl -X POST "http://localhost:8000/api/unit-economics/save" \
  -H "Content-Type: application/json" \
  -d '[
    {
      "sku": "3-02/3515",
      "marketplace": "ozon",
      "cost_price": 901.56,
      "drr_percent": 5
    },
    {
      "sku": "3-02/3516",
      "marketplace": "ozon",
      "cost_price": 450.00,
      "drr_percent": 3
    }
  ]'
```

### Синхронизация юнит-экономики

```bash
# Фоновая синхронизация (рекомендуется)
curl -X POST "http://localhost:8000/api/unit-economics/sync/13"

# Синхронная синхронизация (для небольших интеграций)
curl -X POST "http://localhost:8000/api/unit-economics/sync-now/13" \
  -H "Content-Type: application/json" \
  -d '{
    "period_start": "2025-11-01",
    "period_end": "2025-12-01"
  }'
```

### Установить % выкупа вручную

```bash
curl -X POST "http://localhost:8000/api/integrations/13/redemption-rate" \
  -H "Content-Type: application/json" \
  -d '{ "redemption_rate": 85.5 }'
```

---

## Правила и ограничения

### 1. Себестоимость (cost_price)
- **Вводится только вручную** — не синхронизируется автоматически
- Уникальный ключ: `sku` + `integration_id`
- Можно загрузить через CSV/Excel: `POST /api/products/cost-price/upload`

### 2. Ручные проценты
Следующие поля **не перезаписываются** при синхронизации:
- `drr_percent` — РК %
- `our_share_percent` — Наша часть %
- `tax_percent` — Налог %
- `vat_percent` — НДС %

### 3. Приоритет % выкупа

| Приоритет | Источник | Условие |
|-----------|----------|---------|
| 1 | API аналитики | Premium аккаунт |
| 2 | Ручной ввод | `manual_redemption_rate` задано |
| 3 | Fallback | Расчёт через заказы/возвраты |
| 4 | Дефолт | 100% |

### 4. Условия возврата Ozon
При возврате Ozon:
- **Возвращает**: комиссию + эквайринг
- **Списывает**: сумму реализации
- **Не возвращает**: стоимость прямой логистики

Обратная логистика:
- **FBO**: базовый тариф **БЕЗ коэффициента** + 15₽
- **FBS**: базовый тариф FBS **БЕЗ коэффициента** + 15₽

### 5. Схемы работы
- `is_actual_scheme` = true — фактическая схема работы товара
- Товар может иметь записи для разных схем (FBO и FBS)
- Параметр `fulfillment_type` **пересчитывает тарифы**, а не фильтрует товары

### 6. Синхронизация
- Автоматическая синхронизация запускается после `SyncProductsJob`
- Ручная синхронизация: `POST /api/unit-economics/sync/{integrationId}`
- При синхронизации сохраняются ручные значения (cost_price, drr_percent и т.д.)

### 7. Определение fulfillment_type при синхронизации

При синхронизации товаров бекенд автоматически определяет `fulfillment_type` из Ozon API:

**Источники данных (по приоритету):**
1. `sources[]` — массив активных схем продаж
2. `visibility_details.has_stock` — наличие на складах Ozon
3. `fbo_sku` / `fbs_sku` — наличие SKU для схем
4. `stocks[].type` — тип остатков (FBO/FBS)
5. `commissions[]` — комиссии по схемам (если есть — схема активна)
6. По умолчанию — FBO

**Логика определения:**
```php
// Приоритет: FBO > FBS > REALFBS
if (sources содержит 'FBO') return 'FBO';
if (sources содержит 'FBS') return 'FBS';
if (sources содержит 'RFBS') return 'REALFBS';

// Проверка по SKU
if (fbo_sku && !fbs_sku) return 'FBO';
if (fbs_sku && !fbo_sku) return 'FBS';

// Проверка по остаткам
if (stocks.type === 'FBO' && present > 0) return 'FBO';
if (stocks.type === 'FBS' && present > 0) return 'FBS';

// По умолчанию
return 'FBO';
```

### 8. Комиссии по категориям (дефолтные)

| Категория | FBO | FBS |
|-----------|-----|-----|
| Электроника | 8% | 5% |
| Одежда | 15% | 12% |
| Обувь | 15% | 12% |
| Красота | 18% | 15% |
| Дом | 12% | 9% |
| Детские | 13% | 10% |
| Спорт | 11% | 8% |
| Дефолт | 15% | 12% |

---

## Artisan команды

```bash
# Синхронизация юнит-экономики для интеграции
php artisan sync:unit-economics --integration=13

# Проверить данные
php artisan tinker
>>> \App\Models\UnitEconomics::where('integration_id', 13)->count()
>>> \App\Models\UnitEconomics::where('integration_id', 13)->where('marketplace', 'ozon')->first()
```

---

## Файлы

| Файл | Описание |
|------|----------|
| `routes/api.php` | Роуты API |
| `app/Http/Controllers/Api/UnitEconomicsController.php` | Контроллер |
| `app/Services/UnitEconomicsService.php` | Сервис расчётов |
| `app/Models/UnitEconomics.php` | Модель |
| `app/Jobs/SyncUnitEconomicsJob.php` | Фоновая синхронизация |
| `app/Services/Marketplace/OzonService.php` | Сервис Ozon API |

---

*Документация актуальна на декабрь 2025*
