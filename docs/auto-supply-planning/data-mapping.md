# Auto Supply Planning — Data Mapping

## Источники данных (таблицы Sellico)

### 1. Остатки (stocks)
**Таблица**: `inventory_warehouses`
| Поле | Тип | Описание |
|------|-----|----------|
| `sku` | string | Артикул товара (offer_id для Ozon, barcode для WB) |
| `warehouse_id` | string | ID склада маркетплейса |
| `warehouse_name` | string | Название склада |
| `integration_id` | int | ID интеграции Sellico |
| `marketplace` | string | ozon / wildberries |
| `quantity` | int | Доступный остаток |
| `reserved` | int | Зарезервировано |
| `in_transit` | int | В пути на склад |

### 2. Продажи (sales_daily)
**Таблица**: `inventory_warehouses`
| Поле | Тип | Описание |
|------|-----|----------|
| `sales_7_days` | int | Продажи за 7 дней |
| `sales_14_days` | int | Продажи за 14 дней |
| `sales_30_days` | int | Продажи за 30 дней |
| `average_daily_sales` | decimal | Среднедневные продажи |

### 3. Метаданные SKU (sku_meta)
**Таблица**: `products`
| Поле | Тип | Описание |
|------|-----|----------|
| `sku` | string | Для Ozon = offer_id, для WB = barcode (EAN) |
| `name` | string | Название товара |
| `barcode` | string | Штрихкод (для WB = основной идентификатор) |
| `marketplace` | string | ozon / wildberries |
| `integration_id` | int | ID интеграции |
| `ozon_data` | json | Данные Ozon (offer_id, product_id, sku и т.д.) |
| `wb_data` | json | Данные WB (nmID, vendorCode и т.д.) |

### 4. WB Barcode Mapping
**Таблица**: `products` (marketplace = wildberries)
- `sku` хранит barcode (EAN) — это основной идентификатор WB
- `barcode` дублирует то же значение
- Маппинг: `offer_id (sku)` → `barcode` = одно и то же для WB

### 5. Ozon Offer ID Mapping
**Таблица**: `products` (marketplace = ozon)
- `sku` хранит offer_id (артикул продавца)
- `ozon_data.sku` — числовой SKU Ozon (fbo_sku)
- Маппинг для экспорта: `sku` = `offer_id` = артикул в XLSX

---

## Алгоритм расчёта

### EWMA (Exponentially Weighted Moving Average)
```
α = 0.35 (параметр сглаживания)
short_avg = sales_7_days / 7
long_avg  = sales_30_days / 30
ewma_daily = α × short_avg + (1 - α) × long_avg
```

### Формула потребности
```
demand = ewma_daily × (target_days + safety_days + lead_time_days)
qty_raw = demand - current_stock - in_transit
qty_rounded = max(0, ceil(qty_raw))
```

### Risk Level
| Уровень | Условие (days_of_stock) |
|---------|------------------------|
| critical | ≤ 3 дней |
| high | ≤ 7 дней |
| medium | ≤ 14 дней |
| low | > 14 дней |

### Data Quality Score
```
score = (lines_with_sales_data / total_lines) × 100
```
Где `lines_with_sales_data` = строки с `sales_30_days > 0`

---

## Экспорт XLSX

### Ozon
| Колонка | Источник |
|---------|----------|
| артикул | `products.sku` (= offer_id) |
| имя (необязательно) | `products.name` |
| количество | `SUM(qty_rounded)` GROUP BY offer_id |

### Wildberries
| Колонка | Источник |
|---------|----------|
| Баркод | `products.barcode` |
| Количество | `SUM(qty_rounded)` GROUP BY barcode |

**Валидация WB**: если `barcode` = NULL или несколько разных barcode для одного offer_id → ошибка, строка исключается.
