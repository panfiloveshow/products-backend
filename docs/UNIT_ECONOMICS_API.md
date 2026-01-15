# Unit Economics API - Ozon (Универсальная модель)

## Обзор

API юнит-экономики поддерживает все схемы работы с Ozon:
- **FBO** (Fulfillment by Ozon) — хранение и доставка Ozon
- **FBS** (Fulfillment by Seller) — хранение продавца, доставка Ozon
- **realFBS / DBS** — полностью своя логистика

---

## Endpoint

```
GET /api/unit-economics/ozon?integration_id={id}
```

### Параметры запроса

| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| `integration_id` | integer | Да | ID интеграции |
| `limit` | integer | Нет | Лимит записей (по умолчанию 50) |
| `page` | integer | Нет | Страница пагинации |
| `search` | string | Нет | Поиск по SKU или названию |
| `fulfillment_type` | string | Нет | Фильтр по схеме: FBO, FBS, RFBS, EXPRESS |
| `profitable` | boolean | Нет | Только прибыльные товары |

---

## Структура ответа

```json
{
  "data": {
    "items": [...],
    "total": 89,
    "scheme_counts": {
      "FBO": {"count": 89, "actual_count": 89},
      "FBS": {"count": 89, "actual_count": 0},
      "RFBS": {"count": 89, "actual_count": 0},
      "EXPRESS": {"count": 89, "actual_count": 0}
    }
  },
  "stats": {...}
}
```

### Поле `scheme_counts`

Содержит количество товаров по каждой схеме работы:
- `count` — общее количество записей для схемы
- `actual_count` — количество товаров, которые **фактически** работают по этой схеме

**Использование для табов:**
```typescript
// Показать количество товаров в табе
const fboCount = data.scheme_counts.FBO?.count || 0;

// Подсветить таб с фактической схемой
const isActualScheme = data.scheme_counts.FBO?.actual_count > 0;
```

---

## Структура элемента items

```json
{
  "data": {
    "items": [
      {
        "id": 1,
        "sku": "A1",
        "product_name": "Товар 1",
        "marketplace": "ozon",
        "integration_id": 19,
        
        // === БАЗОВЫЕ ДАННЫЕ ===
        "price": "501.00",
        "cost_price": "0.00",
        "sales_count": 1020,
        
        // === СХЕМА РАБОТЫ ===
        "fulfillment_type": "FBO",  // FBO | FBS | RFBS | EXPRESS
        "is_actual_scheme": true,   // Фактическая схема работы товара
        
        // === АКЦИИ ===
        "is_in_promotion": true,           // Товар участвует в акции
        "promotion_discount": "58.00",     // Скидка в %
        "seller_price": "545.00",          // Базовая цена (без акции)
        "marketing_seller_price": "229.00", // Цена с учётом акции
        
        // === ГАБАРИТЫ ===
        "volume_liters": "0.37",      // Объём в литрах
        "volume_weight": "0.07",      // Объёмный вес (объём / 5)
        "actual_weight": "0.10",      // Фактический вес в кг
        
        // === КОМИССИЯ ===
        "commission_percent": "31.00",    // % комиссии (из API Ozon)
        "commission_amount": "158416.20", // Сумма комиссии
        
        // === ЛОГИСТИКА ===
        "logistics_cost": "64260.00",     // Логистика до сортировки
        "processing_cost": "0.00",        // Обработка отправления (только FBS)
        "last_mile_cost": "28106.10",     // Последняя миля (5.5%, макс 500₽)
        
        // === ХРАНЕНИЕ (только FBO) ===
        "storage_cost": "0.00",           // Стоимость хранения
        "turnover_days": 30,              // Оборачиваемость в днях
        "litrobonus": "0.00",             // Литробонусы (компенсация)
        
        // === ВОЗВРАТЫ ===
        "redemption_rate": "100.00",      // % выкупа
        "orders_count": 568,              // Всего заказов
        "returns_count": 0,               // Возвратов
        "return_logistics_cost": "0.00",  // Обратная логистика
        
        // === ЭКВАЙРИНГ ===
        "acquiring_percent": "1.50",      // % эквайринга
        "acquiring_amount": "7665.30",    // Сумма эквайринга
        
        // === СВОЯ ЛОГИСТИКА (realFBS/DBS) ===
        "own_delivery_cost": "0.00",      // Своя доставка
        "ozon_compensation": "0.00",      // Компенсация от Ozon
        
        // === РЕКЛАМА ===
        "advertising_cost": null,         // Затраты на рекламу
        "drr_percent": null,              // ДРР (доля рекл. расходов)
        
        // === НАЛОГИ И НАША ЧАСТЬ ===
        "tax_percent": "6.00",            // Налоги % (УСН по умолчанию 6%)
        "vat_percent": "0.00",            // НДС % (по умолчанию 0%)
        "tax_amount": "30.06",            // Сумма налога за единицу
        "vat_amount": "0.00",             // Сумма НДС за единицу
        "our_share_percent": "0.00",      // Наша часть % (произвольный %)
        
        // === НА РАСЧЁТНЫЙ СЧЁТ ===
        "to_settlement_account": "204.00", // Деньги на РС (БЕЗ себестоимости)
        
        // === ИТОГИ ===
        "revenue": "511020.00",           // Выручка
        "total_costs": "258447.60",       // Все затраты
        "gross_profit": "252572.40",      // Валовая прибыль
        "net_profit": "252572.40",        // Чистая прибыль
        "margin_percent": "49.43",        // Маржа %
        "roi_percent": "97.73",           // ROI %
        
        // === ДОПОЛНИТЕЛЬНО (из связанного товара) ===
        "dimensions": {
          "length": 55,
          "width": 45,
          "height": 150,
          "weight": 100,
          "volume": 0.37
        },
        "commissions": {
          "fbo": {"percent": 31, "value": 155.31, "delivery_amount": 25, "return_amount": 46.77},
          "fbs": {"percent": 34, "value": 170.34, "delivery_amount": 25, "return_amount": 81.34},
          "rfbs": {"percent": 33, "value": 165.33},
          "fbp": {"percent": 33, "value": 165.33}
        },
        "redemption": {
          "redemption_rate": 100,
          "orders_count": 568,
          "returns_count": 0,
          "delivered_count": 568
        }
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 50,
      "total": 89,
      "last_page": 2
    },
    "summary": {
      "total_revenue": "15000000.00",
      "total_profit": "5000000.00",
      "avg_margin": "33.33",
      "profitable_count": 75,
      "unprofitable_count": 14
    }
  }
}
```

---

## Формулы расчёта

### FBO (Fulfillment by Ozon)

```
Затраты = Комиссия + Логистика + Последняя миля + Хранение + Эквайринг + Возвраты

Где:
├── Комиссия = price × commission_percent%
├── Логистика = Тариф по объёмному весу (63₽ + 12₽ за каждый литр сверх 1л)
├── Последняя миля = min(price × 5.5%, 500₽)
├── Хранение:
│   ├── До 160 дней: 0₽
│   ├── 161-180 дней: 0.75₽/л
│   └── Более 180 дней: 1.5₽/л
├── Эквайринг = price × 1.5%
└── Возвраты = (1 - redemption_rate) × return_logistics_cost
```

### FBS (Fulfillment by Seller)

```
Затраты = Комиссия + Обработка + Логистика + Последняя миля + Эквайринг + Возвраты

Где:
├── Комиссия = price × commission_percent% (выше чем FBO)
├── Обработка = 5-30₽ за отправление
├── Логистика = Тариф по объёмному весу (76₽ + 12₽ за каждый литр сверх 1л)
├── Последняя миля = min(price × 5.5%, 500₽)
├── Эквайринг = price × 1.5%
└── Возвраты = (1 - redemption_rate) × return_logistics_cost
```

### realFBS / DBS

```
Затраты = Комиссия + Своя логистика - Компенсация Ozon + Эквайринг

Где:
├── Комиссия = price × commission_percent%
├── Своя логистика = own_delivery_cost (СДЭК, Почта России и т.д.)
├── Компенсация = ozon_compensation (до 799₽ за КГТ)
└── Эквайринг = price × 1.5%
```

---

## Множественные схемы работы (декабрь 2025)

Теперь для каждого товара создаются записи для **всех 4 схем работы** (FBO, FBS, RFBS, EXPRESS), чтобы пользователь мог сравнить и выбрать оптимальную.

### Новые поля

| Поле | Тип | Описание |
|------|-----|----------|
| `is_actual_scheme` | boolean | Фактическая схема работы товара (true = товар реально работает по этой схеме) |
| `is_in_promotion` | boolean | Товар участвует в акции |
| `promotion_discount` | decimal | Скидка в % |
| `seller_price` | decimal | Базовая цена продавца (без акции) |
| `marketing_seller_price` | decimal | Цена с учётом маркетинговых акций |

### Логика работы

1. **Синхронизация**: для каждого товара создаются 4 записи (FBO, FBS, RFBS, EXPRESS)
2. **Фактическая схема**: определяется из API остатков Ozon, помечается `is_actual_scheme = true`
3. **Предварительный расчёт**: пользователь может сравнить все схемы и выбрать оптимальную
4. **Действующая цена**: поле `price` содержит цену с учётом акций (`marketing_seller_price`)

### Пример: товар A39 по всем схемам

```json
[
  {"fulfillment_type": "FBO", "margin_percent": "-19.34", "is_actual_scheme": true},
  {"fulfillment_type": "FBS", "margin_percent": "52.84", "is_actual_scheme": false},
  {"fulfillment_type": "RFBS", "margin_percent": "52.07", "is_actual_scheme": false},
  {"fulfillment_type": "EXPRESS", "margin_percent": "71.50", "is_actual_scheme": false}
]
```

### Фильтрация на фронтенде

```typescript
// Показать только фактические схемы
const actualItems = items.filter(item => item.is_actual_scheme);

// Показать все схемы для сравнения
const allSchemes = items.filter(item => item.sku === selectedSku);

// Показать товары в акциях
const promotionItems = items.filter(item => item.is_in_promotion);
```

---

## Отображение на фронтенде

### Рекомендуемая структура карточки товара

```
┌─────────────────────────────────────────────────────────────┐
│ SKU: A1                                    [FBO] ← Badge    │
│ Товар 1                                                     │
├─────────────────────────────────────────────────────────────┤
│ Цена: 501₽              Продажи: 1020 шт                    │
│ Выкуп: 100%             Оборачиваемость: 30 дней            │
├─────────────────────────────────────────────────────────────┤
│ ЗАТРАТЫ                                                     │
│ ├── Комиссия (31%)          155.31₽                         │
│ ├── Логистика               63.00₽                          │
│ ├── Последняя миля          27.56₽                          │
│ ├── Хранение                0.00₽                           │
│ ├── Эквайринг (1.5%)        7.52₽                           │
│ └── Возвраты                0.00₽                           │
│ ─────────────────────────────────────                       │
│ ИТОГО:                      253.39₽                         │
├─────────────────────────────────────────────────────────────┤
│ Выручка: 501₽                                               │
│ Прибыль: 247.61₽            Маржа: 49.4%                    │
└─────────────────────────────────────────────────────────────┘
```

### Цветовая индикация

| Показатель | Зелёный | Жёлтый | Красный |
|------------|---------|--------|---------|
| Маржа | > 30% | 15-30% | < 15% |
| Выкуп | > 80% | 50-80% | < 50% |
| Оборачиваемость | < 60 дней | 60-120 дней | > 120 дней |
| ROI | > 50% | 20-50% | < 20% |

### Фильтры

```tsx
// Рекомендуемые фильтры
const filters = {
  fulfillment_type: ['FBO', 'FBS', 'RFBS', 'DBS'],
  profitability: ['profitable', 'unprofitable', 'all'],
  margin_range: { min: 0, max: 100 },
  redemption_range: { min: 0, max: 100 },
  turnover_range: { min: 0, max: 365 },
};
```

---

## TypeScript типы

```typescript
interface UnitEconomicsItem {
  id: number;
  sku: string;
  product_name: string;
  marketplace: 'ozon';
  integration_id: number;
  
  // Базовые
  price: string;
  cost_price: string;
  sales_count: number;
  
  // Схема работы
  fulfillment_type: 'FBO' | 'FBS' | 'RFBS' | 'DBS';
  
  // Габариты
  volume_liters: string | null;
  volume_weight: string | null;
  actual_weight: string | null;
  
  // Комиссия
  commission_percent: string | null;
  commission_amount: string | null;
  
  // Логистика
  logistics_cost: string | null;
  processing_cost: string | null;
  last_mile_cost: string | null;
  
  // Хранение
  storage_cost: string | null;
  turnover_days: number | null;
  litrobonus: string | null;
  
  // Возвраты
  redemption_rate: string | null;
  orders_count: number | null;
  returns_count: number | null;
  return_logistics_cost: string | null;
  
  // Эквайринг
  acquiring_percent: string | null;
  acquiring_amount: string | null;
  
  // Своя логистика
  own_delivery_cost: string | null;
  ozon_compensation: string | null;
  
  // Реклама
  advertising_cost: string | null;
  drr_percent: string | null;
  
  // Налоги и наша часть
  tax_percent: string | null;           // Налоги % (УСН по умолчанию 6%)
  vat_percent: string | null;           // НДС % (по умолчанию 0%)
  tax_amount: string | null;            // Сумма налога за единицу
  vat_amount: string | null;            // Сумма НДС за единицу
  our_share_percent: string | null;     // Наша часть % (произвольный %)
  
  // На расчётный счёт
  to_settlement_account: string | null; // Деньги на РС (БЕЗ себестоимости)
  
  // Итоги
  revenue: string;
  total_costs: string;
  gross_profit: string;
  net_profit: string;
  margin_percent: string;
  roi_percent: string;
  
  // Дополнительно
  dimensions: Dimensions;
  commissions: OzonCommissions;
  redemption: RedemptionData;
}

interface Dimensions {
  length: number | null;
  width: number | null;
  height: number | null;
  weight: number | null;
  volume: number | null;
}

interface OzonCommissions {
  fbo: CommissionSchema | null;
  fbs: CommissionSchema | null;
  rfbs: CommissionSchema | null;
  fbp: CommissionSchema | null;
}

interface CommissionSchema {
  percent: number;
  value: number;
  delivery_amount?: number;
  return_amount?: number;
}

interface RedemptionData {
  redemption_rate: number;
  orders_count: number;
  returns_count: number;
  delivered_count: number;
}
```

---

## Примеры использования

### React компонент

```tsx
import { useQuery } from '@tanstack/react-query';

function UnitEconomicsTable({ integrationId }: { integrationId: number }) {
  const { data, isLoading } = useQuery({
    queryKey: ['unit-economics', integrationId],
    queryFn: () => 
      fetch(`/api/unit-economics/ozon?integration_id=${integrationId}`)
        .then(res => res.json()),
  });

  if (isLoading) return <Spinner />;

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>SKU</TableHead>
          <TableHead>Схема</TableHead>
          <TableHead>Цена</TableHead>
          <TableHead>Комиссия</TableHead>
          <TableHead>Логистика</TableHead>
          <TableHead>Выкуп</TableHead>
          <TableHead>Прибыль</TableHead>
          <TableHead>Маржа</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {data.data.items.map((item: UnitEconomicsItem) => (
          <TableRow key={item.id}>
            <TableCell>{item.sku}</TableCell>
            <TableCell>
              <Badge variant={item.fulfillment_type === 'FBO' ? 'default' : 'secondary'}>
                {item.fulfillment_type}
              </Badge>
            </TableCell>
            <TableCell>{formatCurrency(item.price)}</TableCell>
            <TableCell>{item.commission_percent}%</TableCell>
            <TableCell>{formatCurrency(item.logistics_cost)}</TableCell>
            <TableCell>
              <span className={getRedemptionColor(item.redemption_rate)}>
                {item.redemption_rate}%
              </span>
            </TableCell>
            <TableCell className={Number(item.net_profit) > 0 ? 'text-green-600' : 'text-red-600'}>
              {formatCurrency(item.net_profit)}
            </TableCell>
            <TableCell>
              <span className={getMarginColor(item.margin_percent)}>
                {item.margin_percent}%
              </span>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}

function getMarginColor(margin: string): string {
  const m = Number(margin);
  if (m > 30) return 'text-green-600';
  if (m > 15) return 'text-yellow-600';
  return 'text-red-600';
}

function getRedemptionColor(rate: string): string {
  const r = Number(rate);
  if (r > 80) return 'text-green-600';
  if (r > 50) return 'text-yellow-600';
  return 'text-red-600';
}
```

---

## Синхронизация данных

Данные синхронизируются автоматически при синхронизации товаров.

Ручная синхронизация:
```bash
php artisan unit-economics:sync --marketplace=ozon
```

---

## Источники данных

| Поле | Источник |
|------|----------|
| `commission_percent` | Ozon API `/v3/product/info/list` → `commissions` |
| `redemption_rate` | Ozon API `/v2/posting/fbo/list` + `/v2/returns/company/fbo` |
| `volume_liters` | Характеристики товара (габариты) |
| `base_logistics_cost` | Расчёт по тарифам декабрь 2025 |
| `logistics_coefficient` | Коэффициент времени доставки (1.0-1.8) |
| `last_mile_cost` | Фиксировано 25₽ (с 01.06.2025) |
| `storage_cost` | Расчёт по оборачиваемости |

---

## Тарифы FBO (декабрь 2025)

### Базовые тарифы логистики

**Товары от 301₽:**
| Объём | Тариф |
|-------|-------|
| 0-1 л | 46.77₽ (базовая ставка) |
| 1-3 л | +10.17₽ за каждый доп. литр |
| 3-190 л | +15.25₽ за каждый доп. литр |
| 190-1000 л | +6.10₽ за каждый доп. литр |
| >1000 л | 7859.86₽ фиксировано |

**Товары до 300₽:** 17.28₽ за каждый литр

### Коэффициент времени доставки

| Время (ч) | Коэффициент | Доп. % |
|-----------|-------------|--------|
| ≤29 | 1.000 | 0.00% |
| 35 | 1.320 | 1.60% |
| 40 | 1.510 | 2.55% |
| 50 | 1.760 | 3.80% |
| ≥61 | 1.800 | 4.00% |

### Последняя миля
- **С 01.06.2025:** Фиксировано **25₽** за заказ

### Обратная логистика
- Базовый тариф (без коэффициента) + 15₽ обработка

---

## Новые поля API (декабрь 2025)

```typescript
interface FBOLogisticsData {
  // Время доставки
  avg_delivery_time_hours: number;    // Среднее время доставки (ч)
  
  // Базовый тариф
  base_logistics_cost: number;        // Базовый тариф по объёму
  
  // Коэффициенты
  logistics_coefficient: number;      // Коэффициент времени (1.0-1.8)
  additional_commission_percent: number; // Доп. % от цены (0-4%)
  additional_commission_amount: number;  // Доп. комиссия в ₽
  logistics_with_coefficient: number; // Базовый × коэффициент
  
  // Итого
  logistics_cost: number;             // Полная логистика
  last_mile_cost: number;             // Последняя миля (25₽)
  
  // Возвраты
  return_logistics_cost: number;      // Обратная логистика
  return_processing_cost: number;     // Обработка возврата (15₽)
}
```

---

## Версия

- **API Version:** 3.0
- **Дата обновления:** 16.12.2025
- **Тарифы:** Ozon FBO с 10.12.2025
