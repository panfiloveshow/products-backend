# Юнит-экономика — Реализация Backend

## Обзор

Реализована полноценная система юнит-экономики для трёх маркетплейсов:
- **Wildberries**
- **Ozon**
- **Яндекс Маркет**

## Источники реальных данных

| Поле | Источник | Описание |
|------|----------|----------|
| `sku`, `product_name`, `price` | `Product` | Данные товара из синхронизации |
| `sales_count` | `InventoryWarehouse.sales_30_days` | Продажи за 30 дней |
| `cost_price` | `InventoryWarehouse.cost_price` | Себестоимость (вводится пользователем) |
| `storage_cost` | `InventoryWarehouse.storage_cost_per_month` | Стоимость хранения |
| `commission_percent` | Автоопределение по категории | Комиссия маркетплейса |

## API Endpoints

### Получение данных

```
GET /api/unit-economics
GET /api/unit-economics/{marketplace}
GET /api/unit-economics/{marketplace}/{sku}
```

**Query параметры:**
- `search` — поиск по SKU или названию
- `marketplace` — фильтр по маркетплейсу
- `integration_id` — фильтр по интеграции (магазину)
- `profitability` — `all`, `profitable`, `unprofitable`
- `margin_min`, `margin_max` — диапазон маржи
- `price_min`, `price_max` — диапазон цены
- `sort` — поле сортировки
- `sort_order` — `asc` или `desc`
- `page`, `limit` — пагинация

### Синхронизация

```
POST /api/unit-economics/sync/{integrationId}
POST /api/unit-economics/sync-now/{integrationId}
```

**Параметры:**
- `period_start` — начало периода (опционально)
- `period_end` — конец периода (опционально)

`/sync` — асинхронная синхронизация через Job
`/sync-now` — синхронная синхронизация (для небольших интеграций)

### Сохранение

```
POST /api/unit-economics/save
```

**Body:** массив объектов с редактируемыми полями:
```json
[
  {
    "sku": "SKU-001",
    "marketplace": "wildberries",
    "cost_price": 1500,
    "taxes": 6,
    "spp_percent": 5
  }
]
```

### Калькулятор

```
POST /api/unit-economics/calculate/{marketplace}
```

### Сравнение

```
GET /api/unit-economics/comparison
GET /api/unit-economics/product-comparison
```

### Статистика

```
GET /api/unit-economics/stats
GET /api/unit-economics/stats/{marketplace}
```

### Справочники

```
GET /api/unit-economics/commissions/{marketplace}
GET /api/unit-economics/tariffs/{marketplace}
```

## Формулы расчёта

### Общие

```
revenue = price × sales_count
total_costs = cost_price × sales_count + marketplace_fees
gross_profit = revenue - total_costs
net_profit = gross_profit - advertising_cost
margin_percent = (net_profit / revenue) × 100
roi_percent = (net_profit / total_costs) × 100
```

### Wildberries

```
commission = price × wb_commission_percent / 100
storage = volume_liters × storage_tariff × storage_days
logistics = logistics_cost × sales_count
spp = price × spp_percent / 100
ks = price × ks_percent / 100

total_fees = commission + storage + logistics + spp + ks
```

### Ozon

```
commission = price × commission_percent / 100 (FBO или FBS)
last_mile = last_mile_cost × sales_count
acquiring = price × acquiring_percent / 100

total_fees = commission + last_mile + acquiring + storage + packaging
```

### Яндекс Маркет

```
referral_fee = price × referral_fee_percent / 100
fby_total = fby_placement + fby_pickup_transfer + fby_delivery + fby_middle_mile

total_fees = referral_fee + fby_total
```

## Файлы

### Модели
- `app/Models/UnitEconomics.php` — модель юнит-экономики

### Сервисы
- `app/Services/UnitEconomicsService.php` — расчёты и синхронизация

### Контроллеры
- `app/Http/Controllers/Api/UnitEconomicsController.php` — API endpoints

### Jobs
- `app/Jobs/SyncUnitEconomicsJob.php` — фоновая синхронизация

### Миграции
- `2025_01_01_000009_create_unit_economics_table.php` — создание таблицы
- `2025_12_16_140000_add_integration_id_to_unit_economics.php` — добавление integration_id

## Использование

### 1. Синхронизация товаров и остатков

Сначала синхронизируйте товары и остатки:
```
POST /api/products/sync/{marketplace}
POST /api/inventory/sync/{marketplace}
```

### 2. Синхронизация юнит-экономики

После синхронизации товаров запустите расчёт юнит-экономики:
```
POST /api/unit-economics/sync-now/{integrationId}
```

### 3. Редактирование себестоимости

Пользователь может редактировать себестоимость через:
```
POST /api/unit-economics/save
```

При изменении себестоимости автоматически пересчитываются все метрики.

## Важные замечания

1. **Себестоимость** — вводится пользователем, не берётся из API маркетплейсов
2. **Продажи** — берутся из `InventoryWarehouse.sales_30_days` (синхронизируются через `getSalesBySku()`)
3. **Комиссии** — определяются автоматически по категории товара
4. **Хранение** — берётся из `InventoryWarehouse.storage_cost_per_month` или рассчитывается по формуле

## Флаги в marketplace_data

```json
{
  "has_cost_price": true,   // Себестоимость заполнена
  "has_sales_data": true    // Есть данные о продажах
}
```

Фронтенд может использовать эти флаги для отображения предупреждений:
- Если `has_cost_price: false` — показать "Заполните себестоимость"
- Если `has_sales_data: false` — показать "Нет данных о продажах"
