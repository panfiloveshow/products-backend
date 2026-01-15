# Архитектура юнит-экономики v2

> **Версия:** 2.0  
> **Дата:** 19.12.2025

## Обзор

Новая архитектура юнит-экономики разделяет данные на 3 слоя:

```
┌─────────────────────────────────────────────────────────────┐
│                      СЛОЙ 1: ИСТОЧНИКИ                       │
│  products.ozon_data (комиссии, габариты, выкуп из API)      │
│  Обновляется: при синхронизации товаров                     │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                   СЛОЙ 2: НАСТРОЙКИ ПОЛЬЗОВАТЕЛЯ            │
│  unit_economics_settings (себестоимость, налоги, РК%)       │
│  Обновляется: вручную пользователем                         │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                   СЛОЙ 3: РАСЧЁТНЫЕ ДАННЫЕ                  │
│  unit_economics_cache (прибыль, маржа, ROI)                 │
│  Обновляется: при изменении слоёв 1 или 2                   │
└─────────────────────────────────────────────────────────────┘
```

## Преимущества

| Критерий | API v1 (legacy) | API v2 (cache) |
|----------|-----------------|----------------|
| Скорость ответа | ~60ms (пересчёт) | ~35ms (из кэша) |
| Нагрузка на сервер | Высокая | Низкая |
| Актуальность данных | Всегда актуальны | Актуальны после синхронизации |
| Хранение | 1 запись на товар | 4 записи на товар (по схемам) |

## Таблицы БД

### `unit_economics_settings` — настройки пользователя

```sql
CREATE TABLE unit_economics_settings (
    id BIGINT PRIMARY KEY,
    integration_id BIGINT NOT NULL,
    sku VARCHAR(255) NOT NULL,
    
    -- Ручные данные
    cost_price DECIMAL(12,2) DEFAULT 0,        -- Себестоимость
    drr_percent DECIMAL(5,2) DEFAULT 0,        -- РК %
    our_share_percent DECIMAL(5,2) DEFAULT 0,  -- Наша часть %
    tax_percent DECIMAL(5,2) DEFAULT 6,        -- Налог %
    vat_percent DECIMAL(5,2) DEFAULT 0,        -- НДС %
    redemption_rate_override DECIMAL(5,2) NULL, -- Переопределение % выкупа
    
    UNIQUE(integration_id, sku)
);
```

### `unit_economics_cache` — кэш расчётов

```sql
CREATE TABLE unit_economics_cache (
    id BIGINT PRIMARY KEY,
    integration_id BIGINT NOT NULL,
    product_id BIGINT NOT NULL,
    sku VARCHAR(255) NOT NULL,
    fulfillment_type ENUM('FBO', 'FBS', 'RFBS', 'EXPRESS'),
    
    -- Все расчётные поля...
    commission_percent, logistics_cost, net_profit, margin_percent, ...
    
    calculated_at TIMESTAMP,
    
    UNIQUE(integration_id, sku, fulfillment_type)
);
```

## API Endpoints

### Получение данных (из кэша)

```
GET /api/v2/unit-economics/{marketplace}
```

**Параметры:**
| Параметр | Тип | Описание |
|----------|-----|----------|
| `integration_id` | integer | ID интеграции (обязательный) |
| `fulfillment_type` | string | FBO, FBS, RFBS, EXPRESS (обязательный) |
| `search` | string | Поиск по SKU или названию |
| `profitable` | boolean | Только прибыльные товары |
| `margin_min` | number | Минимальная маржа % |
| `margin_max` | number | Максимальная маржа % |
| `sort` | string | Поле сортировки |
| `limit` | integer | Лимит (по умолчанию 50) |
| `page` | integer | Страница |

**Ответ:**
```json
{
  "data": {
    "items": [...],
    "pagination": {
      "total": 101,
      "per_page": 50,
      "current_page": 1,
      "last_page": 3
    },
    "scheme_counts": {
      "FBO": 101,
      "FBS": 101,
      "RFBS": 101,
      "EXPRESS": 101
    },
    "stats": {
      "total_count": 101,
      "profitable_count": 85,
      "unprofitable_count": 16,
      "avg_margin": 42.5,
      "total_revenue": 1500000,
      "total_profit": 450000
    }
  }
}
```

### Обновление настроек

```
PUT /api/v2/unit-economics/settings/{sku}
```

**Body:**
```json
{
  "integration_id": 13,
  "cost_price": 500,
  "drr_percent": 10,
  "tax_percent": 6,
  "redemption_rate_override": 85
}
```

**Важно:** После обновления настроек автоматически пересчитывается кэш для этого товара (все 4 схемы).

### Массовое обновление

```
PUT /api/v2/unit-economics/settings/bulk
```

**Body:**
```json
{
  "integration_id": 13,
  "items": [
    { "sku": "SKU-001", "cost_price": 500 },
    { "sku": "SKU-002", "cost_price": 300, "drr_percent": 5 }
  ]
}
```

### Принудительный пересчёт

```
POST /api/v2/unit-economics/recalculate/{integrationId}
```

Пересчитывает кэш для всех товаров интеграции. Используйте после массовых изменений.

### Статистика кэша

```
GET /api/v2/unit-economics/cache-stats/{integrationId}
```

## Автоматический пересчёт

Кэш автоматически пересчитывается при:

1. **Синхронизации товаров** — `SyncProductsJob` запускает `RecalculateUnitEconomicsCacheJob`
2. **Изменении настроек** — `PUT /settings/{sku}` триггерит пересчёт

## Миграция с v1 на v2

### Для фронтенда:

1. Изменить endpoint: `/api/unit-economics/ozon` → `/api/v2/unit-economics/ozon`
2. Добавить обязательный параметр `fulfillment_type`
3. Использовать `PUT /settings/{sku}` для обновления настроек

### Пример React Query:

```tsx
// Получение данных
const { data } = useQuery({
  queryKey: ['unit-economics-v2', integrationId, fulfillmentType],
  queryFn: () => fetch(`/api/v2/unit-economics/ozon?integration_id=${integrationId}&fulfillment_type=${fulfillmentType}`),
  staleTime: 5 * 60 * 1000, // 5 минут кэш
});

// Обновление настроек
const mutation = useMutation({
  mutationFn: (data) => fetch(`/api/v2/unit-economics/settings/${sku}`, {
    method: 'PUT',
    body: JSON.stringify({ integration_id: integrationId, ...data }),
  }),
  onSuccess: () => {
    queryClient.invalidateQueries(['unit-economics-v2', integrationId]);
  },
});
```

## Редактируемые поля

| Поле | Редактируемое | Источник |
|------|---------------|----------|
| `cost_price` | ✅ Да | Ручной ввод |
| `drr_percent` | ✅ Да | Ручной ввод |
| `our_share_percent` | ✅ Да | Ручной ввод |
| `tax_percent` | ✅ Да | Ручной ввод |
| `vat_percent` | ✅ Да | Ручной ввод |
| `redemption_rate_override` | ✅ Да | Переопределение API |
| `price` | ❌ Нет | API Ozon |
| `commission_percent` | ❌ Нет | API Ozon |
| `logistics_cost` | ❌ Нет | Расчёт по тарифам |
| `net_profit` | ❌ Нет | Расчёт |
| `margin_percent` | ❌ Нет | Расчёт |

## Файлы

- `app/Models/UnitEconomicsSettings.php` — модель настроек
- `app/Models/UnitEconomicsCache.php` — модель кэша
- `app/Services/UnitEconomicsCacheService.php` — сервис пересчёта
- `app/Http/Controllers/Api/UnitEconomicsCacheController.php` — контроллер API v2
- `app/Jobs/RecalculateUnitEconomicsCacheJob.php` — Job для фонового пересчёта
- `database/migrations/2025_12_19_150000_create_unit_economics_settings_table.php`
- `database/migrations/2025_12_19_150100_create_unit_economics_cache_table.php`
