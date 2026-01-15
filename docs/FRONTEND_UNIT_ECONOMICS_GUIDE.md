# Инструкция для фронтенда: Unit Economics Ozon

> **Версия:** 4.0  
> **Дата обновления:** 17.12.2025  
> **Тарифы:** Ozon FBO/FBS с 10.12.2025

## Обзор

API юнит-экономики предоставляет детальный расчёт затрат и прибыли для товаров на Ozon.
Поддерживаются все схемы работы: **FBO**, **FBS**, **realFBS/DBS**.

### 🆕 Что изменилось (17.12.2025)

1. **Индекс локализации теперь из API** — `avg_delivery_time_hours`, `logistics_coefficient`, `additional_commission_percent` получаются автоматически из API Ozon
2. **Credentials из Sellico API** — не нужно хранить API ключи локально
3. **Новые поля `*_per_unit`** — готовые значения за единицу товара

---

## API Endpoints

### 1. Получить юнит-экономику товаров

```
GET /api/unit-economics/ozon?integration_id={id}
```

**Параметры:**
| Параметр | Тип | Описание |
|----------|-----|----------|
| `integration_id` | integer | ID интеграции (обязательный) |
| `limit` | integer | Лимит записей (по умолчанию 50) |
| `page` | integer | Страница пагинации |
| `search` | string | Поиск по SKU или названию |
| `fulfillment_type` | string | Фильтр: FBO, FBS, RFBS, DBS |
| `profitable` | boolean | Только прибыльные товары |

### 2. Обновить настройки интеграции

```
PUT /api/integrations/{id}
```

**Body:**
```json
{
  "settings": {
    "avg_delivery_time_hours": 35
  }
}
```

---

## Структура ответа

```typescript
interface UnitEconomicsResponse {
  data: {
    items: UnitEconomicsItem[];
    pagination: Pagination;
    summary: Summary;
  };
}

interface UnitEconomicsItem {
  id: number;
  sku: string;
  product_name: string;
  marketplace: 'ozon';
  integration_id: number;
  
  // === БАЗОВЫЕ ДАННЫЕ ===
  price: string;                      // Цена продажи
  cost_price: string;                 // Себестоимость
  sales_count: number;                // Продажи за 30 дней
  
  // === СХЕМА РАБОТЫ ===
  fulfillment_type: 'FBO' | 'FBS' | 'RFBS' | 'DBS';
  
  // === ГАБАРИТЫ ===
  volume_liters: string | null;       // Объём в литрах
  volume_weight: string | null;       // Объёмный вес (объём / 5)
  actual_weight: string | null;       // Фактический вес в кг
  
  // === КОМИССИЯ ===
  commission_percent: string | null;  // % комиссии
  commission_amount: string | null;   // Сумма комиссии
  
  // === ЛОГИСТИКА (тарифы декабрь 2025) ===
  avg_delivery_time_hours: number;    // Среднее время доставки (ч)
  base_logistics_cost: string | null; // Базовый тариф по объёму
  logistics_coefficient: string;      // Коэффициент времени (1.0-1.8)
  additional_commission_percent: string; // Доп. % от цены (0-4%)
  additional_commission_amount: string;  // Доп. комиссия в ₽
  logistics_with_coefficient: string; // Базовый × коэффициент
  logistics_cost: string | null;      // Полная логистика
  processing_cost: string | null;     // Обработка отправления (FBS)
  last_mile_cost: string | null;      // Последняя миля (25₽)
  
  // === ХРАНЕНИЕ (FBO) ===
  storage_cost: string | null;        // Стоимость хранения
  turnover_days: number | null;       // Оборачиваемость в днях
  litrobonus: string | null;          // Литробонусы
  
  // === ВОЗВРАТЫ ===
  redemption_rate: string | null;     // % выкупа
  orders_count: number | null;        // Всего заказов
  returns_count: number | null;       // Возвратов
  return_logistics_cost: string | null; // Обратная логистика
  return_processing_cost: string | null; // Обработка возврата (15₽)
  
  // === ЭКВАЙРИНГ ===
  acquiring_percent: string | null;   // % эквайринга (до 1.5%)
  acquiring_amount: string | null;    // Сумма эквайринга
  
  // === СВОЯ ЛОГИСТИКА (realFBS/DBS) ===
  own_delivery_cost: string | null;   // Своя доставка
  ozon_compensation: string | null;   // Компенсация от Ozon
  
  // === СТОИМОСТЬ ЗА ЕДИНИЦУ (НОВЫЕ ПОЛЯ!) ===
  logistics_per_unit: string | null;      // Логистика за 1 шт (базовый тариф)
  last_mile_per_unit: string;             // Последняя миля за 1 шт (25₽)
  commission_per_unit: string | null;     // Комиссия за 1 шт
  acquiring_per_unit: string | null;      // Эквайринг за 1 шт
  storage_per_unit: string | null;        // Хранение за 1 шт
  total_costs_per_unit: string | null;    // Все затраты за 1 шт
  net_profit_per_unit: string | null;     // Прибыль за 1 шт
  
  // === ИТОГИ (общие суммы) ===
  revenue: string;                    // Выручка (общая)
  total_costs: string;                // Все затраты (общая сумма)
  gross_profit: string;               // Валовая прибыль
  net_profit: string;                 // Чистая прибыль (общая)
  margin_percent: string;             // Маржа %
  roi_percent: string;                // ROI %
}
```

---

## Настройки интеграции

### Поле `avg_delivery_time_hours` (ОБНОВЛЕНО!)

~~Раньше это значение нужно было вводить вручную.~~

**Теперь индекс локализации получается автоматически из API Ozon!**

При каждой синхронизации backend вызывает:
```
POST /v1/analytics/average-delivery-time/summary
```

И получает:
- `avg_delivery_time_hours` — среднее время доставки (например 38ч)
- `logistics_coefficient` — коэффициент к базовому тарифу (например 1.44)
- `additional_commission_percent` — доп. % от цены товаров (например 2.2%)

**⚠️ Важно:** Индекс локализации **уникален для каждого магазина** и **меняется ежедневно**!

### Таблица коэффициентов

| Время (ч) | Коэффициент | Доп. % от цены |
|-----------|-------------|----------------|
| ≤29 | 1.000 | 0.00% |
| 30 | 1.050 | 0.25% |
| 35 | 1.320 | 1.60% |
| 40 | 1.510 | 2.55% |
| 45 | 1.660 | 3.30% |
| 50 | 1.760 | 3.80% |
| ≥61 | 1.800 | 4.00% |

---

## Компоненты для фронтенда

### 1. Настройки интеграции (поле времени доставки)

```tsx
import { useState } from 'react';
import { TextField, Slider, Typography, Box, Alert } from '@mui/material';

interface DeliveryTimeSettingsProps {
  integrationId: number;
  initialValue?: number;
  onSave: (value: number) => Promise<void>;
}

export function DeliveryTimeSettings({ 
  integrationId, 
  initialValue = 29, 
  onSave 
}: DeliveryTimeSettingsProps) {
  const [value, setValue] = useState(initialValue);
  const [saving, setSaving] = useState(false);

  const getCoefficient = (hours: number) => {
    if (hours <= 29) return { coef: 1.0, percent: 0 };
    if (hours <= 35) return { coef: 1.32, percent: 1.6 };
    if (hours <= 40) return { coef: 1.51, percent: 2.55 };
    if (hours <= 50) return { coef: 1.76, percent: 3.8 };
    return { coef: 1.8, percent: 4.0 };
  };

  const { coef, percent } = getCoefficient(value);

  const handleSave = async () => {
    setSaving(true);
    try {
      await onSave(value);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Box sx={{ p: 2, border: '1px solid #e0e0e0', borderRadius: 2 }}>
      <Typography variant="h6" gutterBottom>
        Среднее время доставки FBO
      </Typography>
      
      <Alert severity="info" sx={{ mb: 2 }}>
        Посмотрите в ЛК Ozon → Аналитика → Логистика
      </Alert>

      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
        <TextField
          type="number"
          value={value}
          onChange={(e) => setValue(Number(e.target.value))}
          label="Часы"
          size="small"
          sx={{ width: 100 }}
          inputProps={{ min: 1, max: 100 }}
        />
        
        <Slider
          value={value}
          onChange={(_, v) => setValue(v as number)}
          min={20}
          max={70}
          sx={{ flex: 1 }}
        />
      </Box>

      <Box sx={{ mt: 2, display: 'flex', gap: 3 }}>
        <Typography variant="body2" color="text.secondary">
          Коэффициент: <strong>{coef.toFixed(2)}×</strong>
        </Typography>
        <Typography variant="body2" color="text.secondary">
          Доп. комиссия: <strong>+{percent}%</strong>
        </Typography>
      </Box>

      <Button 
        variant="contained" 
        onClick={handleSave} 
        disabled={saving}
        sx={{ mt: 2 }}
      >
        {saving ? 'Сохранение...' : 'Сохранить'}
      </Button>
    </Box>
  );
}
```

### 2. Карточка затрат товара

```tsx
import { Card, CardContent, Typography, Box, Chip, Divider } from '@mui/material';

interface CostBreakdownCardProps {
  item: UnitEconomicsItem;
}

export function CostBreakdownCard({ item }: CostBreakdownCardProps) {
  const formatCurrency = (value: string | null) => {
    if (!value) return '—';
    return `${Number(value).toLocaleString('ru-RU')} ₽`;
  };

  const getMarginColor = (margin: string) => {
    const m = Number(margin);
    if (m > 30) return 'success';
    if (m > 15) return 'warning';
    return 'error';
  };

  return (
    <Card>
      <CardContent>
        {/* Заголовок */}
        <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 2 }}>
          <Box>
            <Typography variant="h6">{item.sku}</Typography>
            <Typography variant="body2" color="text.secondary">
              {item.product_name}
            </Typography>
          </Box>
          <Chip 
            label={item.fulfillment_type} 
            color={item.fulfillment_type === 'FBO' ? 'primary' : 'secondary'}
            size="small"
          />
        </Box>

        {/* Базовые данные */}
        <Box sx={{ display: 'flex', gap: 3, mb: 2 }}>
          <Box>
            <Typography variant="caption" color="text.secondary">Цена</Typography>
            <Typography variant="h6">{formatCurrency(item.price)}</Typography>
          </Box>
          <Box>
            <Typography variant="caption" color="text.secondary">Продажи</Typography>
            <Typography variant="h6">{item.sales_count} шт</Typography>
          </Box>
          <Box>
            <Typography variant="caption" color="text.secondary">Выкуп</Typography>
            <Typography variant="h6">{item.redemption_rate}%</Typography>
          </Box>
        </Box>

        <Divider sx={{ my: 2 }} />

        {/* Детализация затрат */}
        <Typography variant="subtitle2" gutterBottom>Затраты на единицу</Typography>
        
        <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
          <CostRow 
            label={`Комиссия (${item.commission_percent}%)`} 
            value={item.commission_amount} 
            perUnit={Number(item.commission_amount) / Math.max(item.sales_count, 1)}
          />
          
          {item.fulfillment_type === 'FBO' && (
            <>
              <CostRow 
                label={`Логистика (×${item.logistics_coefficient})`} 
                value={item.logistics_cost}
                perUnit={Number(item.logistics_cost) / Math.max(item.sales_count, 1)}
              />
              <CostRow 
                label="Доп. комиссия" 
                value={item.additional_commission_amount}
                perUnit={Number(item.additional_commission_amount) / Math.max(item.sales_count, 1)}
              />
            </>
          )}
          
          {item.fulfillment_type === 'FBS' && (
            <>
              <CostRow 
                label="Логистика" 
                value={item.logistics_cost}
                perUnit={Number(item.logistics_cost) / Math.max(item.sales_count, 1)}
              />
              <CostRow 
                label="Обработка" 
                value={item.processing_cost}
                perUnit={Number(item.processing_cost) / Math.max(item.sales_count, 1)}
              />
            </>
          )}
          
          <CostRow 
            label="Последняя миля" 
            value={item.last_mile_cost}
            perUnit={25}
          />
          
          <CostRow 
            label="Эквайринг (1.5%)" 
            value={item.acquiring_amount}
            perUnit={Number(item.acquiring_amount) / Math.max(item.sales_count, 1)}
          />
          
          {Number(item.return_logistics_cost) > 0 && (
            <CostRow 
              label="Возвраты" 
              value={item.return_logistics_cost}
              perUnit={Number(item.return_logistics_cost) / Math.max(item.sales_count, 1)}
              color="error"
            />
          )}
        </Box>

        <Divider sx={{ my: 2 }} />

        {/* Итоги */}
        <Box sx={{ display: 'flex', justifyContent: 'space-between' }}>
          <Box>
            <Typography variant="caption" color="text.secondary">Выручка</Typography>
            <Typography variant="h6">{formatCurrency(item.revenue)}</Typography>
          </Box>
          <Box>
            <Typography variant="caption" color="text.secondary">Затраты</Typography>
            <Typography variant="h6" color="error.main">
              {formatCurrency(item.total_costs)}
            </Typography>
          </Box>
          <Box>
            <Typography variant="caption" color="text.secondary">Прибыль</Typography>
            <Typography 
              variant="h6" 
              color={Number(item.net_profit) > 0 ? 'success.main' : 'error.main'}
            >
              {formatCurrency(item.net_profit)}
            </Typography>
          </Box>
        </Box>

        {/* Маржа */}
        <Box sx={{ mt: 2, textAlign: 'center' }}>
          <Chip 
            label={`Маржа: ${item.margin_percent}%`}
            color={getMarginColor(item.margin_percent)}
            size="medium"
          />
        </Box>
      </CardContent>
    </Card>
  );
}

function CostRow({ 
  label, 
  value, 
  perUnit,
  color = 'text.primary' 
}: { 
  label: string; 
  value: string | null; 
  perUnit: number;
  color?: string;
}) {
  return (
    <Box sx={{ display: 'flex', justifyContent: 'space-between' }}>
      <Typography variant="body2" color="text.secondary">{label}</Typography>
      <Box sx={{ textAlign: 'right' }}>
        <Typography variant="body2" color={color}>
          {perUnit.toFixed(2)} ₽/шт
        </Typography>
      </Box>
    </Box>
  );
}
```

### 3. Сравнение схем FBO/FBS/DBS

```tsx
import { Table, TableBody, TableCell, TableHead, TableRow, Paper } from '@mui/material';

interface SchemeComparisonProps {
  price: number;
  volumeLiters: number;
  avgDeliveryTimeHours: number;
  commissions: {
    fbo: number;
    fbs: number;
    rfbs: number;
  };
}

export function SchemeComparison({ 
  price, 
  volumeLiters, 
  avgDeliveryTimeHours,
  commissions 
}: SchemeComparisonProps) {
  // Расчёт базовой логистики FBO (декабрь 2025)
  const calculateFboLogistics = (volume: number) => {
    const v = Math.ceil(volume);
    if (v <= 1) return 46.77;
    let cost = 46.77;
    if (v > 1) cost += Math.min(v - 1, 2) * 10.17;
    if (v > 3) cost += Math.min(v - 3, 187) * 15.25;
    if (v > 190) cost += (v - 190) * 6.10;
    return cost;
  };

  // Коэффициент времени доставки
  const getCoefficient = (hours: number) => {
    if (hours <= 29) return { coef: 1.0, addPercent: 0 };
    if (hours <= 35) return { coef: 1.32, addPercent: 1.6 };
    if (hours <= 40) return { coef: 1.51, addPercent: 2.55 };
    if (hours <= 50) return { coef: 1.76, addPercent: 3.8 };
    return { coef: 1.8, addPercent: 4.0 };
  };

  const { coef, addPercent } = getCoefficient(avgDeliveryTimeHours);
  const baseLogistics = calculateFboLogistics(volumeLiters);
  const lastMile = 25;

  const schemes = [
    {
      name: 'FBO',
      commission: price * commissions.fbo / 100,
      logistics: baseLogistics * coef + price * addPercent / 100,
      lastMile,
      processing: 0,
      total: 0,
    },
    {
      name: 'FBS',
      commission: price * commissions.fbs / 100,
      logistics: baseLogistics + 10, // FBS чуть дороже
      lastMile,
      processing: 25,
      total: 0,
    },
    {
      name: 'realFBS',
      commission: price * commissions.rfbs / 100,
      logistics: 0,
      lastMile: 0,
      processing: 0,
      ownDelivery: 200,
      total: 0,
    },
  ];

  schemes.forEach(s => {
    s.total = s.commission + s.logistics + s.lastMile + s.processing + (s.ownDelivery || 0);
  });

  const minTotal = Math.min(...schemes.map(s => s.total));

  return (
    <Paper sx={{ p: 2 }}>
      <Typography variant="h6" gutterBottom>
        Сравнение схем работы
      </Typography>
      
      <Table size="small">
        <TableHead>
          <TableRow>
            <TableCell>Схема</TableCell>
            <TableCell align="right">Комиссия</TableCell>
            <TableCell align="right">Логистика</TableCell>
            <TableCell align="right">Посл. миля</TableCell>
            <TableCell align="right">Обработка</TableCell>
            <TableCell align="right"><strong>Итого</strong></TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {schemes.map(s => (
            <TableRow 
              key={s.name}
              sx={{ 
                backgroundColor: s.total === minTotal ? 'success.light' : 'inherit',
                '& td': { fontWeight: s.total === minTotal ? 'bold' : 'normal' }
              }}
            >
              <TableCell>{s.name}</TableCell>
              <TableCell align="right">{s.commission.toFixed(2)} ₽</TableCell>
              <TableCell align="right">
                {s.ownDelivery ? `${s.ownDelivery} ₽ (своя)` : `${s.logistics.toFixed(2)} ₽`}
              </TableCell>
              <TableCell align="right">{s.lastMile} ₽</TableCell>
              <TableCell align="right">{s.processing} ₽</TableCell>
              <TableCell align="right"><strong>{s.total.toFixed(2)} ₽</strong></TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Paper>
  );
}
```

---

## ⚠️ ВАЖНО: Использование полей per_unit

**Проблема:** Если фронтенд делит `logistics_cost / sales_count`, то при `sales_count = 0` получается NaN ("не число").

**Решение:** Используйте готовые поля `*_per_unit`:

```typescript
// ❌ НЕПРАВИЛЬНО (может дать NaN)
const logisticsPerUnit = item.logistics_cost / item.sales_count;

// ✅ ПРАВИЛЬНО (всегда число)
const logisticsPerUnit = item.logistics_per_unit ?? item.base_logistics_cost ?? 0;
const lastMilePerUnit = item.last_mile_per_unit ?? 25;
const commissionPerUnit = item.commission_per_unit ?? 0;
const totalCostsPerUnit = item.total_costs_per_unit ?? 0;
const netProfitPerUnit = item.net_profit_per_unit ?? 0;
```

**Новые поля (добавлены 16.12.2025):**
| Поле | Описание | Значение по умолчанию |
|------|----------|----------------------|
| `logistics_per_unit` | Базовая логистика за 1 шт | Расчёт по тарифам |
| `last_mile_per_unit` | Последняя миля за 1 шт | 25.00 ₽ |
| `commission_per_unit` | Комиссия за 1 шт | — |
| `acquiring_per_unit` | Эквайринг за 1 шт | — |
| `storage_per_unit` | Хранение за 1 шт | — |
| `total_costs_per_unit` | Все затраты за 1 шт | — |
| `net_profit_per_unit` | Прибыль за 1 шт | — |

---

## Цветовая индикация

| Показатель | 🟢 Зелёный | 🟡 Жёлтый | 🔴 Красный |
|------------|-----------|----------|-----------|
| Маржа | > 30% | 15-30% | < 15% |
| Выкуп | > 80% | 50-80% | < 50% |
| Оборачиваемость | < 60 дней | 60-120 дней | > 120 дней |
| ROI | > 50% | 20-50% | < 20% |
| Коэффициент | 1.0-1.2 | 1.2-1.5 | > 1.5 |

---

## Источники данных

| Поле | Источник | Автоматически |
|------|----------|---------------|
| `commission_percent` | Ozon API `/v4/product/info/prices` | ✅ Да |
| `redemption_rate` | Ozon API `/v1/returns/list` | ✅ Да |
| `volume_liters` | Характеристики товара | ✅ Да |
| `avg_delivery_time_hours` | **Ozon API** `/v1/analytics/average-delivery-time/summary` | ✅ Да |
| `logistics_coefficient` | **Ozon API** (индекс локализации) | ✅ Да |
| `additional_commission_percent` | **Ozon API** (индекс локализации) | ✅ Да |
| `base_logistics_cost` | Расчёт по тарифам декабрь 2025 | ✅ Да |
| `last_mile_cost` | Фиксировано 25₽ | ✅ Да |
| `acquiring_percent` | Фиксировано 1.5% | ✅ Да |

---

## Полная документация логики

Для детального понимания логики расчётов см. файл:
**`docs/UNIT_ECONOMICS_BACKEND_LOGIC.md`**

---

## Версия

- **API Version:** 4.0
- **Дата:** 17.12.2025
- **Тарифы:** Ozon FBO/FBS с 10.12.2025
- **Индекс локализации:** Автоматически из API Ozon
