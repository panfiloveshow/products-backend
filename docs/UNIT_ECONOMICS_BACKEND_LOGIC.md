# Логика расчёта юнит-экономики Ozon (Backend)

> **Версия:** 4.1  
> **Дата:** 17.12.2025  
> **Тарифы:** Ozon FBO/FBS с 10.12.2025  
> **Обновление:** Добавлена логика Premium аккаунта и приоритеты % выкупа

---

## 📊 Общая схема расчёта

```
┌─────────────────────────────────────────────────────────────────────┐
│                        ВХОДНЫЕ ДАННЫЕ                                │
├─────────────────────────────────────────────────────────────────────┤
│  Товар:           price, volume_liters, weight                       │
│  Интеграция:      integration_id → Sellico API → credentials        │
│  API Ozon:        индекс локализации, комиссии, возвраты            │
│  Склад:           sales_30_days, turnover_days, fulfillment_type    │
└─────────────────────────────────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      РАСЧЁТ ЗАТРАТ (FBO)                            │
├─────────────────────────────────────────────────────────────────────┤
│  1. Комиссия = price × commission_percent%                          │
│  2. Базовая логистика = тариф по объёму (см. таблицу)               │
│  3. Логистика с коэф. = базовая × logistics_coefficient             │
│  4. Доп. комиссия = price × additional_commission_percent%          │
│  5. Последняя миля = 25₽ (фиксировано)                              │
│  6. Эквайринг = price × 1.5%                                        │
│  7. Хранение = тариф × turnover_days                                │
│  8. Возвраты = (базовая + 15₽) × (100% - redemption_rate%)          │
└─────────────────────────────────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                        ИТОГОВЫЕ ПОКАЗАТЕЛИ                          │
├─────────────────────────────────────────────────────────────────────┤
│  total_costs = сумма всех затрат × sales_count                      │
│  revenue = price × sales_count                                       │
│  gross_profit = revenue - total_costs                                │
│  net_profit = gross_profit - cost_price × sales_count               │
│  margin_percent = (net_profit / revenue) × 100%                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 🔑 Источники данных

### 1. Индекс локализации (НОВОЕ!)

**API:** `POST /v1/analytics/average-delivery-time/summary`

**Что получаем:**
```json
{
  "average_delivery_time": 38,        // Среднее время доставки (часы)
  "perfect_delivery_time": 29,        // Рекомендуемое время
  "current_tariff": {
    "tariff_value": 144,              // Коэффициент × 100 (1.44)
    "fee": 2.2                        // Доп. % от цены
  }
}
```

**Как используется:**
- `avg_delivery_time_hours` = 38 (из API)
- `logistics_coefficient` = 1.44 (из API)
- `additional_commission_percent` = 2.2% (из API)

**⚠️ Важно:** Индекс локализации **уникален для каждого магазина** и **меняется ежедневно**!

---

### 2. Credentials интеграций

**Источник:** Sellico API (`/api/workspaces/{id}/integrations`)

**Логика получения:**
```
1. Проверяем локальную таблицу integrations
2. Если нет → запрашиваем Sellico API по integration_id
3. Если нет → используем глобальные из .env
```

---

### 3. Комиссии маркетплейса

**API:** `POST /v4/product/info/prices`

**Поля:**
- `fbo_commission_percent` — комиссия FBO (обычно 15-20%)
- `fbs_commission_percent` — комиссия FBS (обычно 20-25%)

---

### 4. Процент выкупа (ОБНОВЛЕНО!)

**Приоритет источников данных:**

| Приоритет | Источник | Условие | API/Поле |
|-----------|----------|---------|----------|
| 1 | **API аналитики** | Premium аккаунт | `POST /v1/analytics/data` → `delivered_units`, `returns`, `cancellations` |
| 2 | **Ручной ввод** | Не Premium + задано | `integrations.manual_redemption_rate` |
| 3 | **Fallback** | Не Premium + не задано | Расчёт через заказы/возвраты |
| 4 | **Дефолт** | Нет данных | 100% |

**Определение Premium статуса:**
```php
// OzonService::checkPremiumStatus()
// Запрос к /v1/analytics/data с метриками ordered_units, delivered_units
// Если API возвращает данные → Premium
// Если ошибка 403/401 или пустые данные → Не Premium
```

**Кэширование Premium статуса:**
- Поле `integrations.is_premium` (boolean)
- Поле `integrations.premium_checked_at` (timestamp)
- Перепроверка каждые 24 часа

**Расчёт из API аналитики (Premium):**
```
redemption_rate = (delivered_units - returns) / ordered_units × 100%
```

**Fallback расчёт (Не Premium):**
```
orders = /v2/posting/fbo/list + /v2/posting/fbs/list
returns = /v1/returns/list
redemption_rate = (orders - returns) / orders × 100%
```

**API endpoints для управления:**
```
GET  /api/integrations/{id}/premium-status
POST /api/integrations/{id}/redemption-rate  { "redemption_rate": 85.5 }
```

---

## 📐 Тарифы логистики FBO (декабрь 2025)

### Базовый тариф по объёму

| Объём | Тариф |
|-------|-------|
| 0-1 л | 46.77₽ |
| 1-3 л | +10.17₽ за литр |
| 3-190 л | +15.25₽ за литр |
| 190-1000 л | +6.10₽ за литр |
| >1000 л | 7859.86₽ фиксировано |
| Товары до 300₽ | 17.28₽/л |

### Коэффициенты времени доставки

| Время (ч) | Коэффициент | Доп. % от цены |
|-----------|-------------|----------------|
| ≤29 | 1.000 | 0.00% |
| 30 | 1.050 | 0.25% |
| 35 | 1.320 | 1.60% |
| 38 | 1.440 | 2.20% |
| 40 | 1.510 | 2.55% |
| 44 | 1.630 | 3.15% |
| 50 | 1.760 | 3.80% |
| ≥61 | 1.800 | 4.00% |

### Фиксированные тарифы

| Услуга | Стоимость |
|--------|-----------|
| Последняя миля | 25₽ |
| Обработка возврата | 15₽ |
| Эквайринг | 1.5% от цены |

---

## 📦 Поля API ответа

### Базовые данные

| Поле | Тип | Описание | Источник |
|------|-----|----------|----------|
| `sku` | string | Артикул товара | Product |
| `price` | decimal | Цена продажи | Product |
| `cost_price` | decimal | Себестоимость | InventoryWarehouse |
| `sales_count` | integer | Продажи за 30 дней | InventoryWarehouse |
| `fulfillment_type` | string | FBO/FBS/RFBS/DBS | InventoryWarehouse |

### Индекс локализации (из API Ozon)

| Поле | Тип | Описание | Источник |
|------|-----|----------|----------|
| `avg_delivery_time_hours` | integer | Среднее время доставки | **API Ozon** |
| `logistics_coefficient` | decimal | Коэффициент к базовому тарифу | **API Ozon** |
| `additional_commission_percent` | decimal | Доп. % от цены товаров | **API Ozon** |
| `additional_commission_amount` | decimal | Сумма доп. комиссии | Расчёт |

### Логистика

| Поле | Тип | Описание | Источник |
|------|-----|----------|----------|
| `base_logistics_cost` | decimal | Базовый тариф по объёму | Расчёт |
| `logistics_with_coefficient` | decimal | Базовый × коэффициент | Расчёт |
| `logistics_cost` | decimal | Полная логистика | Расчёт |
| `last_mile_cost` | decimal | Последняя миля (25₽) | Фиксировано |

### Комиссии

| Поле | Тип | Описание | Источник |
|------|-----|----------|----------|
| `commission_percent` | decimal | % комиссии маркетплейса | API Ozon |
| `commission_amount` | decimal | Сумма комиссии | Расчёт |
| `acquiring_percent` | decimal | % эквайринга (1.5%) | Фиксировано |
| `acquiring_amount` | decimal | Сумма эквайринга | Расчёт |

### Возвраты

| Поле | Тип | Описание | Источник |
|------|-----|----------|----------|
| `redemption_rate` | decimal | % выкупа | API Ozon |
| `orders_count` | integer | Всего заказов | API Ozon |
| `returns_count` | integer | Возвратов | API Ozon |
| `return_logistics_cost` | decimal | Обратная логистика | Расчёт |

### Стоимость за единицу (для фронтенда!)

| Поле | Тип | Описание |
|------|-----|----------|
| `logistics_per_unit` | decimal | Логистика за 1 шт |
| `last_mile_per_unit` | decimal | Последняя миля за 1 шт (25₽) |
| `commission_per_unit` | decimal | Комиссия за 1 шт |
| `acquiring_per_unit` | decimal | Эквайринг за 1 шт |
| `storage_per_unit` | decimal | Хранение за 1 шт |
| `total_costs_per_unit` | decimal | Все затраты за 1 шт |
| `net_profit_per_unit` | decimal | Прибыль за 1 шт |

### Итоги

| Поле | Тип | Описание |
|------|-----|----------|
| `revenue` | decimal | Выручка (price × sales_count) |
| `total_costs` | decimal | Все затраты |
| `gross_profit` | decimal | Валовая прибыль |
| `net_profit` | decimal | Чистая прибыль |
| `margin_percent` | decimal | Маржа % |
| `roi_percent` | decimal | ROI % |

---

## 🔄 Синхронизация

### Команда

```bash
php artisan unit-economics:sync --marketplace=ozon
```

### Что происходит

1. Получаем список `integration_id` из таблицы `products`
2. Для каждой интеграции:
   - Получаем credentials из Sellico API
   - Получаем индекс локализации из API Ozon
   - Получаем фактические затраты из заказов
   - Рассчитываем юнит-экономику для каждого товара
   - Сохраняем в таблицу `unit_economics`

### Вывод в консоль

```
Found 3 integrations for ozon
Syncing integration_id=19...
  Credentials получены из Sellico API
  Индекс локализации: 38ч, коэф: 1.44, доп.%: 2.2
Syncing integration_id=13...
  Credentials получены из Sellico API
  Индекс локализации: 44ч, коэф: 1.63, доп.%: 3.15
Total synced: 234, Total errors: 0
```

---

## ⚠️ Важные замечания для фронтенда

### 1. Не делить на sales_count!

```typescript
// ❌ НЕПРАВИЛЬНО (NaN при sales_count = 0)
const logisticsPerUnit = item.logistics_cost / item.sales_count;

// ✅ ПРАВИЛЬНО (используйте готовые поля)
const logisticsPerUnit = item.logistics_per_unit;
const totalCostsPerUnit = item.total_costs_per_unit;
const netProfitPerUnit = item.net_profit_per_unit;
```

### 2. Индекс локализации — это НЕ настройка!

Раньше `avg_delivery_time_hours` нужно было вводить вручную.  
**Теперь он получается автоматически из API Ozon** при каждой синхронизации.

### 3. Отображение доп. комиссии

Поле `additional_commission_percent` (например +2.2%) — это **"Процент от цены товаров"** из ЛК Ozon.

```tsx
// Пример отображения
<TableCell>
  <Typography>+{item.additional_commission_percent}%</Typography>
</TableCell>
```

### 4. Цветовая индикация

| Показатель | 🟢 Хорошо | 🟡 Средне | 🔴 Плохо |
|------------|-----------|----------|----------|
| Маржа | > 30% | 15-30% | < 15% |
| Выкуп | > 80% | 50-80% | < 50% |
| Коэффициент | 1.0-1.2 | 1.2-1.5 | > 1.5 |
| Время доставки | ≤29ч | 30-45ч | >45ч |

---

## 📁 Файлы backend

| Файл | Описание |
|------|----------|
| `app/Services/UnitEconomicsService.php` | Расчёт юнит-экономики |
| `app/Services/Marketplace/OzonService.php` | API Ozon (индекс локализации) |
| `app/Services/SellicoApiService.php` | Получение credentials |
| `app/Console/Commands/SyncUnitEconomicsCommand.php` | Синхронизация |
| `app/Http/Controllers/Api/UnitEconomicsController.php` | API endpoints |
| `app/Models/UnitEconomics.php` | Модель данных |

---

## 🧪 Проверка данных

```bash
# Проверить данные в базе
php artisan tinker --execute='
$unit = App\Models\UnitEconomics::where("marketplace", "ozon")->first();
print "SKU: " . $unit->sku . "\n";
print "avg_delivery_time_hours: " . $unit->avg_delivery_time_hours . "ч\n";
print "logistics_coefficient: " . $unit->logistics_coefficient . "\n";
print "additional_commission_percent: " . $unit->additional_commission_percent . "%\n";
print "net_profit_per_unit: " . $unit->net_profit_per_unit . "₽\n";
'
```

---

## 📞 Контакты

При вопросах по логике расчётов обращайтесь к backend-разработчику.
