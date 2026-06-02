# Unit Economics API v2

## Обзор

API юнит-экономики использует новую доменную архитектуру с расчётами для 3 маркетплейсов:
- **Wildberries**: FBO, FBS
- **Ozon**: FBO, FBS, RFBS, EXPRESS
- **Yandex Market**: FBY, FBS, DBS, EXPRESS

---

## Endpoints

### Получить список товаров с юнит-экономикой

```
GET /api/unit-economics/{marketplace}
```

**Query параметры:**
| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| `integration_id` | int | ✅ | ID интеграции |
| `fulfillment_type` | string | ✅ | Схема: FBO, FBS, RFBS, EXPRESS |
| `search` | string | - | Поиск по SKU/названию |
| `profitable` | boolean | - | Фильтр по прибыльности |
| `quick_filter` | string | - | Быстрый пресет: `unprofitable`, `no_sales_28d`, `low_confidence`, `high_non_locality`, `high_non_local_markup`, `data_gap` |
| `margin_min` | number | - | Минимальная маржа % |
| `margin_max` | number | - | Максимальная маржа % |
| `profit_min` | number | - | Минимальная прибыль, ₽ |
| `profit_max` | number | - | Максимальная прибыль, ₽ |
| `roi_min` | number | - | Минимальный ROI, % |
| `roi_max` | number | - | Максимальный ROI, % |
| `price_min` | number | - | Минимальная цена |
| `price_max` | number | - | Максимальная цена |
| `logistics_min` | number | - | Минимальная эфф. логистика, ₽ |
| `logistics_max` | number | - | Максимальная эфф. логистика, ₽ |
| `sales_min` | int | - | Минимум продаж, шт |
| `sales_max` | int | - | Максимум продаж, шт |
| `non_local_markup_min` | number | - | Мин. нелокальная наценка, % |
| `non_local_markup_max` | number | - | Макс. нелокальная наценка, % |
| `confidence` | string | - | Качество: `low`, `medium`, `high` |
| `locality_state` | string | - | Локальность: `local`, `non_local`, `mixed`, `no_sales` |
| `sort` | string | - | Поле сортировки (`relevance` доступен при search) |
| `sort_order` | string | - | asc/desc |
| `limit` | int | - | Лимит (1-500, default: 50) |
| `page` | int | - | Страница |

**Пример запроса:**
```bash
curl -X GET "/api/unit-economics/ozon?integration_id=1&fulfillment_type=FBO&limit=20" \
  -H "Accept: application/json"
```

**Ответ:**
```json
{
  "data": {
    "items": [
      {
        "id": 123,
        "sku": "ABC-001",
        "product_name": "Товар",
        "marketplace": "ozon",
        "fulfillment_type": "FBO",
        "price": 1500,
        "old_price": 1800,
        "commission_percent": 15,
        "commission_amount": 225,
        "logistics_cost": 69.22,
        "last_mile_cost": 25,
        "delivery_cost": 132.62,
        "expected_return_cost": 12.35,
        "effective_logistics": 144.97,
        "redemption_rate": 80,
        "cost_price": 500,
        "net_profit": 622.03,
        "margin_percent": 41.5,
        "is_actual_scheme": true,
        "is_in_promotion": false
      }
    ],
    "pagination": {
      "total": 150,
      "per_page": 20,
      "current_page": 1,
      "last_page": 8
    },
    "scheme_counts": {
      "FBO": 150,
      "FBS": 150,
      "RFBS": 150,
      "EXPRESS": 150
    },
    "actual_scheme": "FBO",
    "default_scheme": "FBO",
    "stats": {
      "total_count": 150,
      "profitable_count": 120,
      "unprofitable_count": 30,
      "avg_margin": 35.5,
      "total_revenue": 225000,
      "total_profit": 79875
    }
  }
}
```

---

### Получить один товар

```
GET /api/unit-economics/{marketplace}/{sku}
```

**Query параметры:**
| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| `integration_id` | int | ✅ | ID интеграции |
| `fulfillment_type` | string | - | Схема (default: FBO) |

**Ответ содержит данные по всем схемам:**
```json
{
  "data": { /* текущая схема */ },
  "settings": { /* настройки пользователя */ },
  "all_schemes": {
    "FBO": { /* данные FBO */ },
    "FBS": { /* данные FBS */ },
    "RFBS": { /* данные RFBS */ },
    "EXPRESS": { /* данные EXPRESS */ }
  }
}
```

---

### Расчёт юнит-экономики

```
POST /api/unit-economics/calculate/{marketplace}
```

**Body:**
```json
{
  "sku": "ABC-001",
  "integration_id": 1,
  "fulfillment_type": "FBO",
  "price": 1500,
  "length": 20,
  "width": 15,
  "height": 10,
  "weight": 0.5,
  "cost_price": 500,
  "commission_rate": 15,
  "redemption_rate": 80,
  "delivery_coefficient": 1.48
}
```

**Ответ (новый формат v2):**
```json
{
  "data": {
    "sku": "ABC-001",
    "marketplace": "ozon",
    "fulfillment_type": "FBO",
    "price": 1500,
    "revenue": 1500,
    "total_costs": 877.5,
    "net_profit": 622.5,
    "margin_percent": 41.5,
    "margin_absolute": 622.5,
    "roi": 124.5,
    "commission_percent": 15,
    "acquiring_percent": 1.5,
    "costs": {
      "commission": 225,
      "acquiring": 22.5,
      "logistics": 105,
      "last_mile": 25,
      "processing_fee": 0,
      "delivery_cost": 130,
      "storage_cost": 0,
      "expected_return_cost": 0,
      "cost_price": 500,
      "marketplace_costs": 377.5,
      "product_costs": 500,
      "total_costs": 877.5
    },
    "is_profitable": true,
    "has_cost_price": true,
    "calculated_at": "2025-12-23T09:21:48+00:00"
  }
}
```

---

### Комиссии маркетплейса

```
GET /api/unit-economics/commissions/{marketplace}
```

**Ответ:**
```json
{
  "data": {
    "marketplace": "ozon",
    "categories": {
      "Женская одежда": 20,
      "Мужская одежда": 20,
      "Смартфоны": 5,
      "Наушники": 12,
      "default": 15
    },
    "acquiring_rate": 1.5
  }
}
```

---

### Тарифы маркетплейса

```
GET /api/unit-economics/tariffs/{marketplace}
```

**Ответ:**
```json
{
  "data": {
    "marketplace": "ozon",
    "schemes": ["FBO", "FBS", "RFBS", "EXPRESS"],
    "tariffs": {
      "FBO": {
        "scheme": "FBO",
        "volume_tariffs": [
          {"max_volume": 0.1, "rate": 46.77},
          {"max_volume": 0.2, "rate": 50.50},
          ...
        ],
        "last_mile_max": 25,
        "acquiring_rate": 1.5,
        "has_coefficient": true
      },
      "FBS": { ... },
      "RFBS": { ... },
      "EXPRESS": { ... }
    }
  }
}
```

---

### Обновить настройки товара

```
PUT /api/unit-economics/settings/{sku}
```

**Body:**
```json
{
  "integration_id": 1,
  "cost_price": 500,
  "drr_percent": 5,
  "our_share_percent": 0,
  "tax_percent": 6,
  "vat_percent": 0,
  "redemption_rate_override": 85
}
```

**Ответ:**
```json
{
  "message": "Settings updated and cache recalculated",
  "settings": { /* обновлённые настройки */ },
  "cache": {
    "FBO": { /* пересчитанные данные */ },
    "FBS": { /* пересчитанные данные */ }
  }
}
```

---

### Массовое обновление настроек

```
PUT /api/unit-economics/settings/bulk
```

**Body:**
```json
{
  "integration_id": 1,
  "items": [
    {"sku": "ABC-001", "cost_price": 500},
    {"sku": "ABC-002", "cost_price": 300, "drr_percent": 5}
  ]
}
```

---

### Принудительный пересчёт

```
POST /api/unit-economics/recalculate/{integrationId}
```

**Ответ:**
```json
{
  "message": "Cache recalculated",
  "stats": {
    "total": 150,
    "success": 148,
    "errors": 2,
    "schemes": {
      "FBO": 148,
      "FBS": 148,
      "RFBS": 148,
      "EXPRESS": 148
    }
  }
}
```

---

## Структура данных

### Поля товара (UnitEconomicsCache)

| Поле | Тип | Описание |
|------|-----|----------|
| `sku` | string | Артикул товара |
| `product_name` | string | Название |
| `marketplace` | string | ozon, wildberries, yandex_market |
| `fulfillment_type` | string | FBO, FBS, RFBS, EXPRESS |
| `price` | number | Цена продажи |
| `old_price` | number | Старая цена (до скидки) |
| `cost_price` | number | Себестоимость |
| `commission_percent` | number | % комиссии |
| `commission_amount` | number | Сумма комиссии ₽ |
| `acquiring_percent` | number | % эквайринга |
| `acquiring_amount` | number | Сумма эквайринга ₽ |
| `logistics_cost` | number | Стоимость логистики ₽ |
| `last_mile_cost` | number | Последняя миля ₽ |
| `delivery_cost` | number | Общая доставка ₽ |
| `expected_return_cost` | number | Ожид. стоимость возвратов ₽ |
| `effective_logistics` | number | Эффективная логистика ₽ |
| `redemption_rate` | number | % выкупа |
| `net_profit` | number | Чистая прибыль ₽ |
| `margin_percent` | number | Маржа % |
| `roi_percent` | number | ROI % |
| `is_actual_scheme` | boolean | Фактическая схема магазина |
| `is_in_promotion` | boolean | Участие в акции |
| `data_version` | int | Версия расчёта (2 = новая архитектура) |

---

## Схемы работы по маркетплейсам

### Ozon
| Схема | Описание |
|-------|----------|
| FBO | Склад Ozon, логистика Ozon |
| FBS | Ваш склад, логистика Ozon |
| RFBS | realFBS Standard — своя логистика по всей России |
| EXPRESS | realFBS Express — экспресс 0-25 км от склада |

### Wildberries
| Схема | Описание |
|-------|----------|
| FBO | Склад WB, логистика WB |
| FBS | Ваш склад, логистика WB |

### Yandex Market
| Схема | Описание |
|-------|----------|
| FBY | Fulfillment by Yandex |
| FBS | Свой склад, логистика Яндекса |
| DBS | Своя доставка |
| EXPRESS | Экспресс-доставка |

---

## Изменения v2

1. **Новая структура расчёта** — результат содержит объект `costs` с детальной разбивкой
2. **data_version = 2** — для записей, рассчитанных новой архитектурой
3. **Поддержка всех 4 схем Ozon** — включая RFBS и EXPRESS
4. **Типизированные DTO** — CalculationInput, CostBreakdown, UnitEconomicsResult
5. **Fallback на legacy** — при ошибках используется старый расчёт для совместимости
