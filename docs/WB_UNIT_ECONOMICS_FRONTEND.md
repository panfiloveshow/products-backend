# Юнит-экономика Wildberries — Инструкция для фронтенда

> **Версия:** 1.3  
> **Дата:** 23.12.2025

## Маппинг полей API → UI

### Все поля юнит-экономики WB

| # | Колонка UI | Поле API | Источник | Редакт. | Формула расчёта |
|---|------------|----------|----------|---------|-----------------|
| 1 | Артикул | `sku` | API товаров | ❌ | — |
| 2 | Наименование | `product_name` | API товаров | ❌ | — |
| 3 | Схема | `scheme` | API остатков | ❌ | FBW/FBS/DBS/EDBS/DBW |
| 4 | Объём, л | `volume_liters` | Расчёт | ❌ | `length × width × height / 1000000` |
| 5 | Длина, мм | `length_mm` | API габаритов | ❌ | — |
| 6 | Ширина, мм | `width_mm` | API габаритов | ❌ | — |
| 7 | Высота, мм | `height_mm` | API габаритов | ❌ | — |
| 8 | Вес, г | `weight_g` | API габаритов | ❌ | — |
| 9 | Себестоимость | `cost_price` | Настройки | ✅ | — |
| 10 | Действующая цена | `price` | API цен | ❌ | — |
| 11 | Наценка, x | `markup_multiplier` | Расчёт | ❌ | `price / cost_price` |
| 12 | Цена покупателя | `customer_price` | Расчёт | ❌ | `price × (1 - spp_percent/100)` |
| 13 | Комиссия, % | `commission_percent` | API комиссий | ❌ | По категории товара |
| 14 | СПП, % | `spp_percent` | Настройки | ✅ | — |
| 15 | СПП, ₽ | `spp_amount` | Расчёт | ❌ | `price × spp_percent/100` |
| 16 | КС, % | `warehouse_coef_percent` | API тарифов | ❌ | Коэффициент склада |
| 17 | КС, ₽ | `warehouse_coef_amount` | Расчёт | ❌ | `base_logistics × warehouse_coef_percent/100` |
| 18 | Логистика | `logistics_cost` | Расчёт | ❌ | `base_logistics + warehouse_coef_amount` |
| 19 | Обр. логистика | `return_logistics` | Тарифы | ❌ | Базовый тариф по объёму |
| 20 | % выкупа | `redemption_rate` | Настройки | ✅ | — |
| 21 | Ожид. возвраты | `expected_return_cost` | Расчёт | ❌ | `return_logistics × (100 - redemption_rate)/100` |
| 22 | Эфф. логистика | `effective_logistics` | Расчёт | ❌ | `logistics_cost + expected_return_cost` |
| 23 | Хранение | `storage_cost` | Тарифы | ❌ | `volume_liters × 0.07₽ × days` |
| 24 | Всего затрат, % | `total_expenses_percent` | Расчёт | ❌ | `(комиссия+лог+возвр+хран) / price × 100` |
| 25 | На р/с | `to_settlement_account` | Расчёт | ❌ | `customer_price - комиссия - логистика - возвраты - хранение` |
| 26 | ДРР, % | `drr_percent` | Настройки | ✅ | — |
| 27 | ДРР, ₽ | `drr_amount` | Расчёт | ❌ | `price × drr_percent/100` |
| 28 | Наша часть, % | `our_share_percent` | Настройки | ✅ | — |
| 29 | Наша часть, ₽ | `our_share_amount` | Расчёт | ❌ | `price × our_share_percent/100` |
| 30 | Налог, % | `tax_percent` | Настройки | ✅ | — |
| 31 | Налог, ₽ | `tax_amount` | Расчёт | ❌ | `(на_р/с - себест - ДРР - наша_часть) × tax_percent/100` |
| 32 | НДС, % | `vat_percent` | Настройки | ✅ | — |
| 33 | НДС, ₽ | `vat_amount` | Расчёт | ❌ | `price × vat_percent/100` |
| 34 | Чистая прибыль | `net_profit` | Расчёт | ❌ | `на_р/с - себестоимость - ДРР - наша_часть - налог - НДС` |
| 35 | Маржа, % | `margin_percent` | Расчёт | ❌ | `net_profit / price × 100` |

### Сравнение редактируемых полей WB и Ozon

| Поле | WB | Ozon | Примечание |
|------|-----|------|------------|
| Габариты (длина, ширина, высота, вес) | ❌ | ❌ | Из API, нередактируемые |
| Себестоимость (`cost_price`) | ✅ | ✅ | Одинаково |
| СПП, % (`spp_percent`) | ✅ | — | Только WB |
| % выкупа (`redemption_rate`) | ✅ | ✅ | Одинаково |
| ДРР, % (`drr_percent`) | ✅ | ✅ | Одинаково |
| Наша часть, % (`our_share_percent`) | ✅ | ✅ | Одинаково |
| Налог, % (`tax_percent`) | ✅ | ✅ | Одинаково |
| НДС, % (`vat_percent`) | ✅ | ✅ | Одинаково |
| Время доставки (`avg_delivery_time_hours`) | — | ❌ | Только Ozon FBO, из API |
| Коэффициент (`logistics_coefficient`) | — | ❌ | Только Ozon FBO, из API |
| Доп. комиссия (`additional_commission_percent`) | — | ❌ | Только Ozon FBO, из API |

### Источники данных WB API

| Данные | Endpoint | Домен |
|--------|----------|-------|
| Товары и габариты | `/content/v2/get/cards/list` | content-api.wildberries.ru |
| Цены | `/public/api/v1/info` | discounts-prices-api.wildberries.ru |
| Комиссии | `/api/v1/tariffs/commission` | common-api.wildberries.ru |
| Коэф. складов (КС) | `/api/tariffs/v1/acceptance/coefficients` | common-api.wildberries.ru |
| Тарифы возврата | `/api/v1/tariffs/return` | common-api.wildberries.ru |
| Платное хранение | `/api/v1/paid_storage` | statistics-api.wildberries.ru |

### Хранение данных (аналогично Ozon)

Данные WB API сохраняются в поле `wb_data` модели Product (аналогично `ozon_data`):

```json
{
  "nmID": 123456789,
  "imtID": 987654321,
  "subjectID": 123,
  "vendorCode": "ABC-123",
  "dimensions": {
    "length": 10,
    "width": 5,
    "height": 3,
    "weight": 200
  },
  "characteristics": [...],
  "commissions": {
    "fbo": {"percent": 15, "category": "Одежда"},
    "fbs": {"percent": 15, "category": "Одежда"}
  },
  "actual_price": 1599,
  "old_price": 1999,
  "length_mm": 100,
  "width_mm": 50,
  "height_mm": 30,
  "weight_g": 200,
  "volume_liters": 0.15
}
```

**Приоритет данных:**
1. Настройки пользователя (`unit_economics_settings`)
2. Данные маркетплейса (`wb_data`)
3. Данные из кэша (`unit_economics_cache`)
4. Данные продукта (`products`)

### API для обновления редактируемых полей

**PUT** `/api/v2/unit-economics/settings/{sku}`

```json
{
  "integration_id": 1,
  "cost_price": 500,
  "spp_percent": 15,
  "redemption_rate_override": 85,
  "drr_percent": 10,
  "our_share_percent": 5,
  "tax_percent": 6,
  "vat_percent": 0
}
```

> **Примечание:** Габариты (`length_mm`, `width_mm`, `height_mm`, `weight_g`) **нередактируемые** — они берутся из API WB.

**PUT** `/api/v2/unit-economics/settings/bulk` — массовое обновление

```json
{
  "integration_id": 1,
  "items": [
    {"sku": "123", "cost_price": 500, "spp_percent": 15, "drr_percent": 10},
    {"sku": "456", "cost_price": 300, "spp_percent": 10, "vat_percent": 20}
  ]
}
```

---

## Схемы работы (табы)

| Таб | Код | Описание |
|-----|-----|----------|
| **FBW** | `FBW` | Склад WB, логистика WB |
| **FBS** | `FBS` | Ваш склад, доставка через WB |
| **DBS** | `DBS` | Своя доставка |
| **EDBS** | `EDBS` | Экспресс-доставка своими силами |
| **DBW** | `DBW` | Доставка курьером WB от вашего склада |

## API Endpoint

```
GET /api/unit-economics?marketplace=wildberries&integration_id={id}&fulfillment_type={scheme}
```

**Параметры:**
- `marketplace` — обязательный, `wildberries`
- `integration_id` — обязательный, ID интеграции
- `fulfillment_type` — опциональный, фильтр по схеме (FBW, FBS, DBS, EDBS, DBW)
- `limit` — опциональный, количество записей (по умолчанию 50)
- `page` — опциональный, номер страницы

## Колонки таблицы по схемам

### FBW — Склад WB

| # | Колонка | Поле | Редакт. | Формат |
|---|---------|------|---------|--------|
| 1 | Артикул | `sku` | ❌ | string |
| 2 | Наименование | `product_name` | ❌ | string |
| 3 | Схема | `scheme` | ❌ | string (FBW/FBS/DBS/EDBS/DBW) |
| 4 | Объем, л | `volume_liters` | ❌ | number |
| 5 | Длина, мм | `length_mm` | ✅ | number |
| 6 | Ширина, мм | `width_mm` | ✅ | number |
| 7 | Высота, мм | `height_mm` | ✅ | number |
| 8 | Вес, г | `weight_g` | ✅ | number |
| 9 | **Себестоимость** | `cost_price` | ✅ | currency |
| 10 | Действующая цена | `price` | ❌ | currency |
| 11 | Наценка, x | `markup_multiplier` | ❌ | `0.0x` |
| 12 | Цена покупателя | `customer_price` | ❌ | currency |
| 13 | Комиссия, % | `commission_percent` | ❌ | percent |
| 14 | **СПП, %** | `spp_percent` | ✅ | percent |
| 15 | СПП, ₽ | `spp_amount` | ❌ | currency |
| 16 | КС, % | `warehouse_coefficient_percent` | ❌ | percent |
| 17 | КС, ₽ | `warehouse_coefficient_amount` | ❌ | currency |
| 18 | Логистика | `logistics_cost` | ❌ | currency |
| 19 | Обр. логистика | `return_logistics_cost` | ❌ | currency |
| 20 | **% выкупа** | `redemption_rate` | ✅ | percent |
| 21 | Ожид. возвраты | `expected_return_cost` | ❌ | currency |
| 22 | Эфф. логистика | `effective_logistics` | ❌ | currency |
| 23 | Хранение | `storage_cost` | ❌ | currency |
| 24 | Всего затрат, % | `total_expenses_percent` | ❌ | percent |
| 25 | На р/с | `to_settlement_account` | ❌ | currency |
| 26 | **ДРР, %** | `drr_percent` | ✅ | percent |
| 27 | ДРР, ₽ | `drr_amount` | ❌ | currency |
| 28 | **Налог, %** | `tax_percent` | ✅ | percent |
| 29 | Налог, ₽ | `tax_amount` | ❌ | currency |
| 30 | Чистая прибыль | `net_profit` | ❌ | currency |
| 31 | Маржа, % | `margin_percent` | ❌ | percent |

### FBS — Свой склад, доставка через WB

| # | Колонка | Поле | Редакт. | Отличие от FBW |
|---|---------|------|---------|----------------|
| 1-15 | Как FBW | | | |
| 16 | ~~КС, %~~ | — | — | **Нет** |
| 17 | ~~КС, ₽~~ | — | — | **Нет** |
| 18 | Логистика | `logistics_cost` | ❌ | Тариф FBS |
| 19 | Обр. логистика | `return_logistics_cost` | ❌ | 128₽ + 9.5₽/л |
| 20-22 | Как FBW | | | |
| 23 | ~~Хранение~~ | — | — | **Нет** |
| 24-31 | Как FBW | | | |

### DBS — Своя доставка

| # | Колонка | Поле | Редакт. | Отличие от FBO |
|---|---------|------|---------|----------------|
| 1-12 | Как FBO | | | |
| 13 | ~~КС, %~~ | — | — | **Нет** |
| 14 | ~~КС, ₽~~ | — | — | **Нет** |
| 15 | **Своя доставка** | `own_delivery_cost` | ✅ | **Ручной ввод** |
| 16 | **Свои возвраты** | `return_logistics_cost` | ✅ | **Ручной ввод** |
| 17-20 | Как FBO | | | |
| 21 | ~~Хранение~~ | — | — | **Нет** |
| 22-28 | Как FBO | | | |

### EDBS — Экспресс-доставка

| # | Колонка | Поле | Редакт. | Отличие от FBO |
|---|---------|------|---------|----------------|
| 1-12 | Как FBO | | | |
| 13 | ~~КС, %~~ | — | — | **Нет** |
| 14 | ~~КС, ₽~~ | — | — | **Нет** |
| 15 | **Своя доставка** | `own_delivery_cost` | ✅ | **Ручной ввод** |
| 16 | **Свои возвраты** | `return_logistics_cost` | ✅ | **Ручной ввод** |
| 17-20 | Как FBO | | | |
| 21 | ~~Хранение~~ | — | — | **Нет** |
| 22-28 | Как FBO | | | |

## Редактируемые поля

| Поле | Тип | По умолчанию | Валидация |
|------|-----|--------------|-----------|
| `cost_price` | number | 0 | >= 0 |
| `spp_percent` | number | 0 | 0-100 |
| `redemption_rate` | number | 100 | 0-100 |
| `drr_percent` | number | 0 | 0-100 |
| `tax_percent` | number | 6 | 0-100 |
| `own_delivery_cost` | number | 0 | >= 0 (realFBS/Express) |
| `return_logistics_cost` | number | 0 | >= 0 (realFBS/Express) |
| `width_mm` | number | 10 | > 0 |
| `height_mm` | number | 10 | > 0 |
| `weight_g` | number | 500 | > 0 |

## API для обновления

```
PUT /api/unit-economics/wildberries/{id}
Content-Type: application/json

{
  "cost_price": 5000,
  "spp_percent": 5,
  "redemption_rate": 85,
  "drr_percent": 10,
  "tax_percent": 6,
  "own_delivery_cost": 200,  // только realFBS/Express
  "return_logistics_cost": 100  // только realFBS/Express
}
```

## Статистика (верхние карточки)

```json
{
  "stats": {
    "total_revenue": 1500000,
    "total_costs": 450000,
    "total_profit": 1050000,
    "avg_margin": 42.5
  }
}
```

| Карточка | Поле | Формат |
|----------|------|--------|
| ВЫРУЧКА | `total_revenue` | currency |
| ЗАТРАТЫ | `total_costs` | currency |
| ПРИБЫЛЬ | `total_profit` | currency |
| СРЕДНЯЯ МАРЖА | `avg_margin` | percent |

## Форматирование

```typescript
// Валюта
const formatCurrency = (value: number) => 
  new Intl.NumberFormat('ru-RU', { 
    style: 'currency', 
    currency: 'RUB',
    maximumFractionDigits: 0 
  }).format(value);

// Проценты
const formatPercent = (value: number) => 
  `${value.toFixed(2)}%`;

// Наценка
const formatMarkup = (value: number) => 
  `${value.toFixed(2)}x`;
```

## Цветовая индикация

| Условие | Цвет | Применение |
|---------|------|------------|
| `margin_percent >= 30` | 🟢 Зелёный | Прибыльный |
| `margin_percent >= 10` | 🟡 Жёлтый | Низкая маржа |
| `margin_percent < 10` | 🔴 Красный | Убыточный |
| `net_profit < 0` | 🔴 Красный | Убыток |

## Сортировка

Доступные поля для сортировки:
- `sku` (по умолчанию)
- `product_name`
- `price`
- `cost_price`
- `margin_percent`
- `net_profit`
- `commission_percent`

## Фильтры

| Фильтр | Параметр | Тип |
|--------|----------|-----|
| Поиск | `search` | string |
| Прибыльность | `profitability` | `profitable` / `unprofitable` |
| Маржа от | `margin_min` | number |
| Маржа до | `margin_max` | number |
| Цена от | `price_min` | number |
| Цена до | `price_max` | number |

## Пример запроса

```typescript
const fetchUnitEconomics = async (integrationId: number, scheme: string) => {
  const response = await fetch(
    `/api/unit-economics/wildberries?` +
    `integration_id=${integrationId}&` +
    `fulfillment_type=${scheme}&` +
    `limit=50&page=1`
  );
  return response.json();
};
```

## Пример ответа

```json
{
  "data": {
    "items": [
      {
        "id": 1,
        "sku": "2046434922837",
        "product_name": "Шуба искусственная под норку",
        "price": 16320,
        "cost_price": 5000,
        "markup_multiplier": 3.26,
        "customer_price": 16320,
        "commission_percent": 15,
        "commission_amount": 2448,
        "spp_percent": 0,
        "spp_amount": 0,
        "warehouse_coefficient_percent": 0,
        "warehouse_coefficient_amount": 0,
        "logistics_cost": 60,
        "return_logistics_cost": 50,
        "redemption_rate": 85,
        "expected_return_cost": 7.5,
        "effective_logistics": 67.5,
        "storage_cost": 12,
        "total_expenses_percent": 15.5,
        "to_settlement_account": 13872,
        "drr_percent": 5,
        "drr_amount": 816,
        "tax_percent": 6,
        "tax_amount": 832.32,
        "net_profit": 7156.18,
        "margin_percent": 43.85,
        "fulfillment_type": "FBO",
        "is_actual_scheme": true
      }
    ],
    "total": 75,
    "scheme_counts": {
      "FBO": { "count": 75, "actual_count": 75 },
      "FBS": { "count": 75, "actual_count": 0 },
      "REALFBS": { "count": 75, "actual_count": 0 },
      "EXPRESS": { "count": 75, "actual_count": 0 }
    },
    "default_scheme": "FBO"
  },
  "stats": {
    "total_revenue": 1224000,
    "total_costs": 367200,
    "total_profit": 856800,
    "avg_margin": 42.5
  }
}
```
