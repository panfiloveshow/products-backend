# Юнит-экономика Wildberries — Схемы работы

> **Версия:** 1.1  
> **Дата:** 22.12.2025

## Схемы работы Wildberries

| Схема | Код | Описание | Логистика |
|-------|-----|----------|-----------|
| **FBW** | `FBW` | Fulfillment by Wildberries | Товар на складе WB, логистика WB |
| **FBS** | `FBS` | Fulfillment by Seller | Товар на своём складе, доставка через WB |
| **DBS** | `DBS` | Delivery by Seller | Своя доставка по всей России |
| **EDBS** | `EDBS` | Express DBS | Экспресс-доставка своими силами |
| **DBW** | `DBW` | Delivery by WB courier | Доставка курьером WB от склада продавца |

## Колонки юнит-экономики WB

### Общие колонки (все схемы)

| Колонка | Поле в БД | Тип | Источник | Описание |
|---------|-----------|-----|----------|----------|
| Артикул | `sku` | string | API | SKU/Баркод товара |
| Наименование | `product_name` | string | API | Название товара |
| Ширина, см | `width_mm` | decimal | API/Ручной | Ширина упаковки |
| Высота, см | `height_mm` | decimal | API/Ручной | Высота упаковки |
| Длина, см | `length_mm` | decimal | API/Ручной | Длина упаковки |
| Вес, г | `weight_g` | decimal | API/Ручной | Вес товара |
| Объём, л | `volume_liters` | decimal | Расчёт | Объём = Д×Ш×В / 1000 |
| **Себестоимость** | `cost_price` | decimal | **Ручной** | Себестоимость товара |
| Действующая цена | `price` | decimal | API | Цена продажи на WB |
| Наценка, x | `markup_multiplier` | decimal | Расчёт | Цена / Себестоимость |
| Цена покупателя | `customer_price` | decimal | API/Расчёт | Цена с учётом СПП |
| Комиссия, % | `commission_percent` | decimal | API | Комиссия WB (0.5-29.5%) |
| Комиссия, ₽ | `commission_amount` | decimal | Расчёт | Цена × Комиссия% |
| **СПП, %** | `spp_percent` | decimal | **Ручной** | Скидка постоянного покупателя |
| СПП, ₽ | `spp_amount` | decimal | Расчёт | Цена × СПП% |
| **% выкупа** | `redemption_rate` | decimal | **Ручной** | Процент выкупа (по умолчанию 100%) |

### Колонки FBW (Склад WB)

| Колонка | Поле в БД | Тип | Источник | Описание |
|---------|-----------|-----|----------|----------|
| КС, % | `warehouse_coefficient_percent` | decimal | API | Коэффициент склада |
| КС, ₽ | `warehouse_coefficient_amount` | decimal | Расчёт | Логистика × КС% |
| Логистика | `logistics_cost` | decimal | Расчёт | Базовая логистика по объёму |
| Логистика + КС | `logistics_with_warehouse` | decimal | Расчёт | Логистика × (1 + КС%) |
| Обратная логистика | `return_logistics_cost` | decimal | Расчёт | 50₽ за единицу (FBO) |
| Ожид. возвраты | `expected_return_cost` | decimal | Расчёт | Обр.логистика × (100 - %выкупа) |
| Эфф. логистика | `effective_logistics` | decimal | Расчёт | Логистика + Ожид.возвраты |

### Колонки FBS (Свой склад, доставка через WB)

| Колонка | Поле в БД | Тип | Источник | Описание |
|---------|-----------|-----|----------|----------|
| Логистика | `logistics_cost` | decimal | Расчёт | Базовая логистика FBS |
| Обратная логистика | `return_logistics_cost` | decimal | Расчёт | 128₽ + 9.5₽/л (FBS на ПВЗ) |
| Ожид. возвраты | `expected_return_cost` | decimal | Расчёт | Обр.логистика × (100 - %выкупа) |
| Эфф. логистика | `effective_logistics` | decimal | Расчёт | Логистика + Ожид.возвраты |

### Колонки DBS (Своя доставка)

| Колонка | Поле в БД | Тип | Источник | Описание |
|---------|-----------|-----|----------|----------|
| **Своя доставка** | `own_delivery_cost` | decimal | **Ручной** | Стоимость своей доставки |
| **Свои возвраты** | `return_logistics_cost` | decimal | **Ручной** | Стоимость обработки возвратов |
| Ожид. возвраты | `expected_return_cost` | decimal | Расчёт | Свои возвраты × (100 - %выкупа) |
| Эфф. логистика | `effective_logistics` | decimal | Расчёт | Своя доставка + Ожид.возвраты |

### Колонки EDBS (Экспресс-доставка)

| Колонка | Поле в БД | Тип | Источник | Описание |
|---------|-----------|-----|----------|----------|
| **Своя доставка** | `own_delivery_cost` | decimal | **Ручной** | Стоимость экспресс-доставки |
| **Свои возвраты** | `return_logistics_cost` | decimal | **Ручной** | Стоимость обработки возвратов |
| Ожид. возвраты | `expected_return_cost` | decimal | Расчёт | Свои возвраты × (100 - %выкупа) |
| Эфф. логистика | `effective_logistics` | decimal | Расчёт | Своя доставка + Ожид.возвраты |

### Итоговые колонки (все схемы)

| Колонка | Поле в БД | Тип | Описание |
|---------|-----------|-----|----------|
| Всего затрат, % | `total_expenses_percent` | decimal | Сумма всех % затрат |
| Всего затрат, ₽ | `total_costs` | decimal | Сумма всех затрат |
| На р/с | `to_settlement_account` | decimal | Цена - Комиссия - СПП |
| **ДРР, %** | `drr_percent` | decimal | **Ручной** — Доля рекламных расходов |
| ДРР, ₽ | `drr_amount` | decimal | Расчёт: Цена × ДРР% |
| **Налог, %** | `tax_percent` | decimal | **Ручной** — Налог (6% УСН) |
| Налог, ₽ | `tax_amount` | decimal | Расчёт: На р/с × Налог% |
| Чистая прибыль | `net_profit` | decimal | На р/с - Себест. - ДРР - Налог - Логистика |
| Маржа, % | `margin_percent` | decimal | Чистая прибыль / Цена × 100 |

## Тарифы логистики WB (с 15.09.2025)

### FBO — Базовая логистика по объёму

```
До 1л:
  - 0-0.5л: 23₽
  - 0.5-1л: 32₽

Более 1л:
  - Первый литр: 46₽
  - Каждый доп. литр: +14₽
```

### FBO — Обратная логистика
- **50₽** за единицу (фиксировано)

### FBS — Логистика
- Базовая: аналогично FBO
- Обратная: **128₽ + 9.5₽/л** (на ПВЗ)

### Коэффициент склада (КС)
Зависит от склада WB, получается через API:
- `GET /api/tariffs/v1/acceptance/coefficients`
- Поля: `storageCoef`, `deliveryCoef`, `deliveryBaseLiter`, `deliveryAdditionalLiter`

## API Endpoints WB для тарифов

### Тарифы поставок
```
GET https://common-api.wildberries.ru/api/tariffs/v1/acceptance/coefficients
```

Возвращает:
- `coefficient` — коэффициент приёмки
- `storageCoef` — коэффициент хранения
- `deliveryCoef` — коэффициент доставки
- `deliveryBaseLiter` — базовый тариф за литр
- `deliveryAdditionalLiter` — доп. тариф за литр

### Тарифы возвратов
```
GET https://common-api.wildberries.ru/api/v1/tariffs/return?date=YYYY-MM-DD
```

Возвращает тарифы на возврат товаров продавцу по складам.

## Редактируемые поля (Ручной ввод)

| Поле | Описание | По умолчанию |
|------|----------|--------------|
| `cost_price` | Себестоимость | 0 |
| `spp_percent` | СПП % | 0 |
| `redemption_rate` | % выкупа | 100 |
| `drr_percent` | ДРР % | 0 |
| `tax_percent` | Налог % | 6 |
| `own_delivery_cost` | Своя доставка (realFBS/Express) | 0 |
| `return_logistics_cost` | Свои возвраты (realFBS/Express) | 0 |

## Формулы расчёта

### Наценка
```php
$markupMultiplier = $costPrice > 0 ? $price / $costPrice : 0;
```

### Комиссия
```php
$commissionAmount = $price * $commissionPercent / 100;
```

### СПП
```php
$sppAmount = $price * $sppPercent / 100;
```

### На расчётный счёт
```php
$toSettlementAccount = $price - $commissionAmount - $sppAmount;
```

### Логистика FBO
```php
function calculateWbLogistics($volumeLiters) {
    if ($volumeLiters <= 0.5) return 23;
    if ($volumeLiters <= 1) return 32;
    return 46 + ($volumeLiters - 1) * 14;
}
```

### Ожидаемые возвраты
```php
$returnRate = (100 - $redemptionRate) / 100;
$expectedReturnCost = $returnLogisticsCost * $returnRate;
```

### Эффективная логистика
```php
$effectiveLogistics = $logisticsCost + $expectedReturnCost;
```

### Чистая прибыль
```php
$netProfit = $toSettlementAccount - $costPrice - $drrAmount - $taxAmount - $effectiveLogistics;
```

### Маржа
```php
$marginPercent = $price > 0 ? ($netProfit / $price) * 100 : 0;
```

## Пример расчёта FBO

**Входные данные:**
- Цена: 16,320₽
- Себестоимость: 5,000₽
- Объём: 2л
- Комиссия: 15%
- СПП: 0%
- % выкупа: 85%
- ДРР: 5%
- Налог: 6%

**Расчёт:**
```
Комиссия = 16,320 × 15% = 2,448₽
СПП = 0₽
На р/с = 16,320 - 2,448 = 13,872₽

Логистика = 46 + (2-1) × 14 = 60₽
Обратная логистика = 50₽
Ожид. возвраты = 50 × 15% = 7.5₽
Эфф. логистика = 60 + 7.5 = 67.5₽

ДРР = 16,320 × 5% = 816₽
Налог = 13,872 × 6% = 832.32₽

Чистая прибыль = 13,872 - 5,000 - 816 - 832.32 - 67.5 = 7,156.18₽
Маржа = 7,156.18 / 16,320 × 100 = 43.85%
```
