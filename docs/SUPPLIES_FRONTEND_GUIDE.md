# Раздел "Поставки" — Инструкция для фронтенда

> **Обновлено:** Добавлена интеграция с аналитикой доставки Ozon (кластеры, время доставки, рекомендации): Раздел "Поставки"

## Обзор

Раздел "Поставки" позволяет:
- Просматривать автоматические рекомендации по пополнению запасов
- Создавать и управлять планами поставок
- Синхронизировать слоты приёмки с маркетплейсами
- Оптимизировать логистику (выбор транспорта, склада)

**Поддерживаемые маркетплейсы:** Wildberries, Ozon

---

## API Endpoints

### Базовый URL
```
/api
```

---

## 1. Рекомендации по поставкам (Supply Recommendations)

### 1.1 Получить список рекомендаций

```http
GET /api/supply-recommendations
```

**Query параметры:**
| Параметр | Тип | Описание |
|----------|-----|----------|
| `marketplace` | string | Фильтр по маркетплейсу: `wildberries` \| `ozon` |
| `integration_id` | number | Фильтр по интеграции |
| `priority` | string | Фильтр по приоритету: `urgent` \| `high` \| `medium` \| `low` |
| `active_only` | boolean | Только активные (default: true) |
| `limit` | number | Лимит на страницу (default: 20) |
| `page` | number | Номер страницы |

**Response:**
```typescript
interface SupplyRecommendationsResponse {
  message: string;
  data: {
    current_page: number;
    data: SupplyRecommendation[];
    total: number;
    per_page: number;
    last_page: number;
  };
  stats: {
    total: number;
    active: number;
    by_priority: {
      urgent: number;
      high: number;
      medium: number;
      low: number;
    };
    used: number;
    dismissed: number;
    with_deadline: number;
    overdue: number;
  };
}

interface SupplyRecommendation {
  id: string;
  integration_id: number;
  marketplace: 'wildberries' | 'ozon';
  warehouse_id: string | null;
  warehouse_name: string | null;
  priority: 'urgent' | 'high' | 'medium' | 'low';
  title: string;
  description: string | null;
  reason: string | null;
  critical_items: CriticalItem[];
  recommended_items: RecommendedItem[];
  total_items: number;
  total_quantity: number;
  total_cost: number;
  total_volume: number;
  total_weight: number;
  estimated_delivery_cost: number | null;
  estimated_storage_cost: number | null;
  estimated_profit: number | null;
  deadline: string | null; // ISO date
  seasonal_factors: string[] | null;
  is_used: boolean;
  used_in_shipment_id: string | null;
  used_at: string | null;
  is_dismissed: boolean;
  dismissed_at: string | null;
  dismissed_reason: string | null;
  created_at: string;
  updated_at: string;
}

interface CriticalItem {
  sku: string;
  days_of_stock: number;
  recommended_quantity: number;
  estimated_cost: number | null;
  priority: string;
  reason: string | null;
}

interface RecommendedItem {
  sku: string;
  quantity: number;
  cost_price: number | null;
  total_cost: number | null;
  volume_per_unit: number | null;
  weight_per_unit: number | null;
  priority: string;
}
```

### 1.2 Получить детали рекомендации

```http
GET /api/supply-recommendations/{id}
```

**Response:**
```typescript
interface SupplyRecommendationDetailResponse {
  message: string;
  data: {
    recommendation: SupplyRecommendation;
    trucks: TruckOption[];
  };
}

interface TruckOption {
  key: string;
  name: string;
  volume_capacity: number;
  weight_capacity: number;
  volume_utilization: number;
  weight_utilization: number;
  utilization: number;
  cost_base: number;
  cost_per_km: number;
  is_optimal: boolean;
  is_multiple?: boolean;
  trucks_needed?: number;
}
```

### 1.3 Сгенерировать рекомендации

```http
POST /api/supply-recommendations/generate
```

**Request Body:**
```json
{
  "integration_id": 1
}
```

**Response:**
```typescript
interface GenerateResponse {
  message: string;
  data: {
    count: number;
    recommendations: SupplyRecommendation[];
  };
}
```

### 1.4 Применить рекомендацию (создать поставку)

```http
POST /api/supply-recommendations/{id}/apply
```

**Response:**
```typescript
interface ApplyResponse {
  message: string;
  data: {
    shipment: Shipment;
    recommendation: SupplyRecommendation;
  };
}
```

### 1.5 Отклонить рекомендацию

```http
POST /api/supply-recommendations/{id}/dismiss
```

**Request Body:**
```json
{
  "reason": "Товар снят с продажи"
}
```

### 1.6 Рекомендации по складам

```http
GET /api/supply-recommendations/by-warehouse
```

**Query параметры:**
| Параметр | Тип | Описание |
|----------|-----|----------|
| `marketplace` | string | Фильтр по маркетплейсу |
| `integration_id` | number | Фильтр по интеграции |

**Response:**
```typescript
interface ByWarehouseResponse {
  message: string;
  data: WarehouseGroup[];
}

interface WarehouseGroup {
  warehouse_id: string;
  warehouse_name: string | null;
  marketplace: string;
  recommendations_count: number;
  total_items: number;
  total_quantity: number;
  total_cost: number;
  urgent_count: number;
  high_count: number;
  recommendations: SupplyRecommendation[];
}
```

### 1.7 Статистика рекомендаций

```http
GET /api/supply-recommendations/stats
```

**Query параметры:**
| Параметр | Тип | Описание |
|----------|-----|----------|
| `integration_id` | number | Фильтр по интеграции |

---

## 2. Планы поставок (Supply Plans)

### 2.1 Получить список планов

```http
GET /api/supply-plans
```

**Query параметры:**
| Параметр | Тип | Описание |
|----------|-----|----------|
| `marketplace` | string | Фильтр по маркетплейсу |
| `status` | string | Фильтр по статусу |
| `integration_id` | number | Фильтр по интеграции |
| `limit` | number | Лимит на страницу |
| `page` | number | Номер страницы |

**Response:**
```typescript
interface SupplyPlansResponse {
  message: string;
  data: {
    current_page: number;
    data: SupplyPlan[];
    total: number;
    per_page: number;
    last_page: number;
  };
}

interface SupplyPlan {
  id: string;
  name: string;
  description: string | null;
  period_start: string; // ISO date
  period_end: string;   // ISO date
  integration_id: number | null;
  marketplace: 'wildberries' | 'ozon';
  target_days_of_stock: number;
  safety_stock_days: number;
  total_items: number;
  total_quantity: number;
  total_cost: number;
  total_volume: number;
  total_weight: number;
  estimated_storage_cost: number | null;
  estimated_profit: number | null;
  estimated_roi: number | null;
  status: 'draft' | 'approved' | 'in_progress' | 'completed' | 'cancelled';
  created_by: string | null;
  created_by_name: string | null;
  approved_by: string | null;
  approved_by_name: string | null;
  approved_at: string | null;
  created_at: string;
  updated_at: string;
}
```

### 2.2 Создать план

```http
POST /api/supply-plans
```

**Request Body:**
```json
{
  "name": "План на январь 2026",
  "description": "Пополнение запасов после новогодних праздников",
  "period_start": "2026-01-15",
  "period_end": "2026-01-31",
  "integration_id": 1,
  "marketplace": "wildberries",
  "target_days_of_stock": 30,
  "safety_stock_days": 7
}
```

### 2.3 Получить детали плана

```http
GET /api/supply-plans/{id}
```

### 2.4 Обновить план

```http
PUT /api/supply-plans/{id}
```

### 2.5 Удалить план

```http
DELETE /api/supply-plans/{id}
```

### 2.6 Рассчитать оптимальный состав

```http
GET /api/supply-plans/{id}/calculate
```

**Response:**
```typescript
interface CalculateResponse {
  message: string;
  data: {
    items: CalculationItem[];
    totals: {
      total_items: number;
      total_quantity: number;
      total_cost: number | null;
      total_volume: number | null;
      total_weight: number | null;
      by_priority: {
        urgent: number;
        high: number;
        medium: number;
        low: number;
      };
    };
    trucks: TruckOption[];
  };
}

interface CalculationItem {
  sku: string;
  optimal_quantity: number;
  reorder_point: number;
  safety_stock: number;
  days_of_stock: number;
  priority: 'urgent' | 'high' | 'medium' | 'low';
  total_cost: number | null;
  total_volume: number | null;
  total_weight: number | null;
  reason: string | null;
  needs_reorder: boolean;
}
```

### 2.7 Утвердить план

```http
POST /api/supply-plans/{id}/approve
```

### 2.8 Отменить план

```http
POST /api/supply-plans/{id}/cancel
```

---

## 3. Слоты приёмки (Warehouse Slots)

### 3.1 Получить список слотов

```http
GET /api/warehouse-slots
```

**Query параметры:**
| Параметр | Тип | Описание |
|----------|-----|----------|
| `marketplace` | string | Фильтр по маркетплейсу |
| `warehouse_id` | string | Фильтр по складу |
| `available_only` | boolean | Только доступные (default: false) |
| `upcoming_only` | boolean | Только будущие (default: true) |
| `date_from` | string | Дата от (Y-m-d) |
| `date_to` | string | Дата до (Y-m-d) |
| `limit` | number | Лимит на страницу |

**Response:**
```typescript
interface WarehouseSlotsResponse {
  message: string;
  data: {
    current_page: number;
    data: WarehouseSlot[];
    total: number;
  };
}

interface WarehouseSlot {
  id: string;
  marketplace: 'wildberries' | 'ozon';
  warehouse_id: string;
  warehouse_name: string | null;
  external_slot_id: string | null;
  date: string; // ISO date
  time_from: string; // HH:mm:ss
  time_to: string;   // HH:mm:ss
  coefficient: number | null; // КС для WB
  is_available: boolean;
  capacity: number | null;
  capacity_used: number;
  boxes_limit: number | null;
  pallets_limit: number | null;
  booked_by_shipment_id: string | null;
  booked_at: string | null;
  synced_at: string | null;
  created_at: string;
  updated_at: string;
}
```

### 3.2 Получить список складов

```http
GET /api/warehouse-slots/warehouses
```

**Query параметры:**
| Параметр | Тип | Описание |
|----------|-----|----------|
| `integration_id` | number | **Обязательно.** ID интеграции |

**Response:**
```typescript
interface WarehousesResponse {
  message: string;
  data: Warehouse[];
}

interface Warehouse {
  id: string;
  name: string;
  address: string | null;
  city: string | null;
  region?: string | null;
  accepts_cargo?: boolean;
  accepts_qr?: boolean;
  work_time?: string | null;
  cargo_types?: string[];
  // Ozon specific
  is_rfbs?: boolean;
  has_entrusted_acceptance?: boolean;
  first_mile_type?: string | null;
  status?: string | null;
}
```

### 3.3 Синхронизировать слоты с маркетплейса

```http
POST /api/warehouse-slots/sync
```

**Request Body:**
```json
{
  "integration_id": 1,
  "warehouse_id": "507",
  "date_from": "2026-01-10",
  "date_to": "2026-01-24"
}
```

**Response:**
```typescript
interface SyncSlotsResponse {
  message: string;
  data: {
    synced: number;
    created: number;
    updated: number;
  };
}
```

### 3.4 Забронировать слот

```http
POST /api/warehouse-slots/{id}/book
```

**Request Body:**
```json
{
  "shipment_id": "uuid-of-shipment"
}
```

### 3.5 Освободить слот

```http
POST /api/warehouse-slots/{id}/release
```

---

## 4. Существующие Shipments API (расширенные)

Существующий API `/api/shipments` остаётся без изменений, но модель `Shipment` теперь имеет дополнительные поля:

```typescript
interface Shipment {
  // ... существующие поля ...
  supply_plan_id: string | null;      // Связь с планом поставок
  external_supply_id: string | null;  // ID поставки в маркетплейсе
  external_status: string | null;     // Статус в маркетплейсе
  synced_at: string | null;           // Время синхронизации
}
```

---

## 5. UI Компоненты — Рекомендации

### 5.1 Страница "Поставки" (SuppliesPage)

**Структура:**
```
SuppliesPage/
├── SuppliesHeader           # Заголовок + кнопки действий
├── SuppliesStats            # Карточки статистики
├── SuppliesFilters          # Фильтры (маркетплейс, интеграция, приоритет)
├── SuppliesTabs             # Вкладки: Рекомендации | Планы | Слоты
│   ├── RecommendationsTab
│   │   ├── RecommendationsList
│   │   └── RecommendationCard
│   ├── PlansTab
│   │   ├── PlansList
│   │   └── PlanCard
│   └── SlotsTab
│       ├── SlotsCalendar
│       └── SlotCard
└── Modals/
    ├── CreatePlanModal
    ├── RecommendationDetailModal
    ├── ApplyRecommendationModal
    └── BookSlotModal
```

### 5.2 Карточка рекомендации (RecommendationCard)

```tsx
interface RecommendationCardProps {
  recommendation: SupplyRecommendation;
  onApply: (id: string) => void;
  onDismiss: (id: string, reason?: string) => void;
  onViewDetails: (id: string) => void;
}
```

**Отображаемые данные:**
- Приоритет (цветной бейдж: 🔴 urgent, 🟡 high, 🟢 medium, ⚪ low)
- Заголовок
- Склад (если есть)
- Количество товаров / единиц
- Общая стоимость
- Дедлайн (если есть, с подсветкой если просрочен)
- Кнопки: "Применить", "Отклонить", "Подробнее"

### 5.3 Цветовая схема приоритетов

```typescript
const priorityColors = {
  urgent: { bg: '#FEE2E2', text: '#DC2626', border: '#FECACA' },
  high: { bg: '#FEF3C7', text: '#D97706', border: '#FDE68A' },
  medium: { bg: '#DBEAFE', text: '#2563EB', border: '#BFDBFE' },
  low: { bg: '#F3F4F6', text: '#6B7280', border: '#E5E7EB' },
};
```

### 5.4 Статистика (SuppliesStats)

```tsx
interface StatsCardProps {
  title: string;
  value: number;
  icon: ReactNode;
  color: string;
  onClick?: () => void;
}

// Карточки:
// 1. Срочные рекомендации (urgent) - красный
// 2. Требуют внимания (high) - оранжевый
// 3. Активные планы - синий
// 4. Просроченные дедлайны - красный
```

---

## 6. Примеры запросов

### 6.1 Загрузка страницы "Поставки"

```typescript
async function loadSuppliesPage(integrationId?: number) {
  const [recommendations, stats] = await Promise.all([
    fetch(`/api/supply-recommendations?active_only=true&limit=10${
      integrationId ? `&integration_id=${integrationId}` : ''
    }`).then(r => r.json()),
    
    fetch(`/api/supply-recommendations/stats${
      integrationId ? `?integration_id=${integrationId}` : ''
    }`).then(r => r.json()),
  ]);
  
  return { recommendations: recommendations.data, stats: stats.data };
}
```

### 6.2 Генерация рекомендаций

```typescript
async function generateRecommendations(integrationId: number) {
  const response = await fetch('/api/supply-recommendations/generate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ integration_id: integrationId }),
  });
  
  const data = await response.json();
  
  if (response.ok) {
    toast.success(`Создано ${data.data.count} рекомендаций`);
  } else {
    toast.error(data.message);
  }
  
  return data;
}
```

### 6.3 Применение рекомендации

```typescript
async function applyRecommendation(recommendationId: string) {
  const response = await fetch(`/api/supply-recommendations/${recommendationId}/apply`, {
    method: 'POST',
  });
  
  const data = await response.json();
  
  if (response.ok) {
    toast.success('Поставка создана');
    // Перенаправить на страницу поставки
    navigate(`/shipments/${data.data.shipment.id}`);
  }
  
  return data;
}
```

### 6.4 Синхронизация слотов

```typescript
async function syncSlots(integrationId: number, warehouseId: string) {
  const dateFrom = new Date().toISOString().split('T')[0];
  const dateTo = new Date(Date.now() + 14 * 24 * 60 * 60 * 1000)
    .toISOString().split('T')[0];
  
  const response = await fetch('/api/warehouse-slots/sync', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      integration_id: integrationId,
      warehouse_id: warehouseId,
      date_from: dateFrom,
      date_to: dateTo,
    }),
  });
  
  const data = await response.json();
  
  if (response.ok) {
    toast.success(`Синхронизировано ${data.data.synced} слотов`);
  }
  
  return data;
}
```

---

## 7. Типы транспорта

```typescript
const truckTypes = [
  { key: 'gazelle', name: 'Газель', volume: 9, weight: 1500 },
  { key: 'gazelle_long', name: 'Газель-Long', volume: 16, weight: 1500 },
  { key: 'bychok', name: 'Бычок', volume: 22, weight: 3000 },
  { key: 'fura_5t', name: 'Фура 5т', volume: 36, weight: 5000 },
  { key: 'fura_10t', name: 'Фура 10т', volume: 54, weight: 10000 },
  { key: 'fura_20t', name: 'Фура 20т', volume: 82, weight: 20000 },
];
```

При отображении рекомендации транспорта:
- Показывать оптимальный вариант (где `is_optimal: true`)
- Показывать % загрузки по объёму и весу
- Если `is_multiple: true` — показать количество машин

---

## 8. Особенности маркетплейсов

### Wildberries

- **Коэффициент приёмки (КС):** Отображается в слотах. Чем ниже — тем выгоднее.
- **Создание поставки:** Через API нельзя. Показывать ссылку на ЛК WB.
- **Бронирование слота:** Через API нельзя. Показывать ссылку на ЛК WB.

### Ozon

- **Полная интеграция:** Можно создавать поставки, бронировать слоты через API.
- **Кнопка "Создать в Ozon":** При применении рекомендации можно сразу создать поставку в Ozon.

---

## 9. Навигация

Добавить в сайдбар:

```tsx
{
  title: 'Поставки',
  icon: <TruckIcon />,
  path: '/supplies',
  badge: urgentCount > 0 ? urgentCount : undefined,
  badgeColor: 'error',
}
```

---

## 10. Локализация (i18n)

```json
{
  "supplies": {
    "title": "Поставки",
    "recommendations": "Рекомендации",
    "plans": "Планы",
    "slots": "Слоты приёмки",
    "generate": "Сгенерировать",
    "apply": "Применить",
    "dismiss": "Отклонить",
    "priority": {
      "urgent": "Срочно",
      "high": "Высокий",
      "medium": "Средний",
      "low": "Низкий"
    },
    "stats": {
      "urgent": "Срочные",
      "active": "Активные",
      "overdue": "Просроченные",
      "used": "Использованные"
    },
    "messages": {
      "generated": "Создано {{count}} рекомендаций",
      "applied": "Поставка создана",
      "dismissed": "Рекомендация отклонена",
      "synced": "Синхронизировано {{count}} слотов"
    }
  }
}
```

---

## 11. Ошибки API

| Код | Описание |
|-----|----------|
| 422 | Валидация не пройдена / Действие невозможно |
| 404 | Ресурс не найден |
| 500 | Ошибка сервера |

**Формат ошибки:**
```json
{
  "message": "Описание ошибки"
}
```

---

## 12. Чеклист реализации

- [ ] Создать страницу `/supplies`
- [ ] Реализовать компонент `RecommendationCard`
- [ ] Реализовать компонент `SuppliesStats`
- [ ] Реализовать вкладку "Рекомендации"
- [ ] Реализовать вкладку "Планы"
- [ ] Реализовать вкладку "Слоты"
- [ ] Добавить модальное окно деталей рекомендации
- [ ] Добавить модальное окно создания плана
- [ ] Добавить кнопку "Сгенерировать рекомендации"
- [ ] Добавить бейдж в сайдбар с количеством срочных рекомендаций
- [ ] Добавить локализацию
- [ ] Добавить тесты

---

## 🆕 Аналитика доставки Ozon (Кластеры)

Новый API для получения данных, аналогичных разделу "Аналитика → География продаж → Среднее время доставки" в личном кабинете Ozon.

### Endpoints

#### GET /api/ozon/delivery-analytics
Общая аналитика по времени доставки.

```typescript
// Запрос
GET /api/ozon/delivery-analytics?integration_id=15&delivery_schema=ALL&supply_period=EIGHT_WEEKS

// Ответ
{
  "message": "Success",
  "data": {
    "data": [...],
    "total": {
      "average_delivery_time": 45,
      "average_delivery_time_status": "Medium",
      "recommended_supply": 18579,
      "orders_total": 201,
      "orders_fast": 105,
      "orders_fast_percent": 52,
      "orders_medium": 78,
      "orders_medium_percent": 38,
      "orders_long": 18,
      "orders_long_percent": 8,
      "lost_profit": 5877036
    }
  }
}
```

#### GET /api/ozon/delivery-analytics/recommendations
Рекомендации по поставкам на основе аналитики доставки (как на скриншоте Ozon).

```typescript
// Запрос
GET /api/ozon/delivery-analytics/recommendations?integration_id=15

// Ответ
{
  "message": "Success",
  "data": [
    {
      "sku": "A1",
      "product_id": 1423433655,
      "name": "Название товара",
      "delivery_schema": "ALL",
      "average_delivery_time": 38,
      "average_delivery_time_status": "Medium",
      "recommended_supply": 957,
      "orders_total": 46,
      "orders_fast": 12,
      "orders_fast_percent": 26,
      "orders_medium": 34,
      "orders_medium_percent": 73,
      "orders_long": 0,
      "orders_long_percent": 0,
      "impact_share": 30,
      "attention_level": "ATTENTION_HI",
      "lost_profit": 1763110,
      "clusters": {
        "154": {
          "cluster_id": 154,
          "delivery_time_fbo": 60,
          "delivery_time_fbs": 60,
          "delivery_time_status": "Medium",
          "orders_count": 39,
          "orders_percent": 84
        }
      }
    }
  ],
  "stats": {
    "total": 89,
    "by_attention": {
      "high": 12,
      "medium": 35,
      "low": 42
    },
    "total_recommended_supply": 18579,
    "total_lost_profit": 5877036,
    "average_delivery_time": 45.2,
    "average_delivery_time_hours": 45.2,  // ⚠️ ЧАСЫ, не дни!
    "average_delivery_time_days": 1.9,    // Дни (для удобства)
    "overall_attention_level": "ATTENTION_MEDIUM",
    "overall_orders_total": 201,
    "overall_orders_fast": 105,
    "overall_orders_medium": 78,
    "overall_orders_long": 18,
    "overall_impact_share": 100
  }
}

> **⚠️ ВАЖНО: Время доставки в ЧАСАХ!**
> 
> Ozon API возвращает время доставки в **часах**, не в днях!
> - `average_delivery_time` — часы (для обратной совместимости)
> - `average_delivery_time_hours` — часы
> - `average_delivery_time_days` — дни (часы / 24)
>
> Пример: `average_delivery_time: 50.2` означает **50.2 часа** (~2.1 дня)
>
> Если Ozon API не возвращает детальные данные по товарам (`data: []`), 
> статистика берётся из общих данных (`overall_*` поля). Это может происходить 
> если у продавца недостаточная история продаж или нет данных по кластерам.
```

#### GET /api/ozon/delivery-analytics/clusters
Список кластеров доставки.

```typescript
GET /api/ozon/delivery-analytics/clusters?integration_id=15
```

#### GET /api/ozon/delivery-analytics/by-clusters ⭐ НОВЫЙ
**Аналитика по кластерам доставки** — данные как на скриншоте Ozon (вкладка "По кластерам").

```typescript
// Запрос
GET /api/ozon/delivery-analytics/by-clusters?integration_id=13

// Ответ
{
  "message": "Success",
  "summary": {
    "average_delivery_time_hours": 44,  // Время в ЧАСАХ
    "average_delivery_time_status": "MEDIUM",
    "impact_share": 100,
    "lost_profit": 55917,               // Переплата за логистику
    "recommended_supply": 4686,
    "attention_level": "LOW",
    "orders": {
      "total": 2377,
      "long": { "value": 754, "percent": 32 },
      "medium": { "value": 408, "percent": 17 },
      "fast": { "value": 1215, "percent": 51 }
    }
  },
  "clusters": [
    {
      "cluster_id": 154,
      "cluster_name": "Москва, МО и Дальние регионы",
      "average_delivery_time_hours": 47,
      "average_delivery_time_status": "MEDIUM",
      "impact_share": 0,
      "exact_impact_share": 42.2054,    // Точная доля влияния
      "lost_profit": 23599,
      "recommended_supply": 1226,
      "attention_level": "HI",
      "orders": {
        "total": 937,
        "long": { "value": 434, "percent": 46 },
        "medium": { "value": 0, "percent": 0 },
        "fast": { "value": 503, "percent": 54 }
      },
      "shipping_clusters": [            // Кластеры отгрузки
        {
          "cluster_id": 154,
          "cluster_name": "Москва, МО и Дальние регионы",
          "orders_count": 501,
          "orders_percent": 53,
          "delivery_time_fbo": 28,
          "delivery_time_fbs": 0,
          "delivery_time_status": "FAST"
        },
        {
          "cluster_id": 2,
          "cluster_name": "Санкт-Петербург и СЗО",
          "orders_count": 60,
          "orders_percent": 6,
          "delivery_time_fbo": 60,
          "delivery_time_fbs": 0,
          "delivery_time_status": "LONG"
        }
      ]
    }
  ],
  "cluster_names": {
    "2": "Санкт-Петербург и СЗО",
    "7": "Дальний Восток",
    "154": "Москва, МО и Дальние регионы"
  }
}
```

**Колонки таблицы (как на Ozon):**
| Колонка | Поле API |
|---------|----------|
| Кластер доставки | `cluster_name` |
| Ср. время доставки | `average_delivery_time_hours` (в часах!) |
| Доля влияния | `exact_impact_share` (%) |
| Переплата за логистику | `lost_profit` (₽) |
| Рекомендуемая поставка | `recommended_supply` (шт) |
| Заказано товаров (Всего) | `orders.total` |
| Долго | `orders.long.value` / `orders.long.percent` |
| Средне | `orders.medium.value` / `orders.medium.percent` |
| Быстро | `orders.fast.value` / `orders.fast.percent` |
| Кластера отгрузки | `shipping_clusters[]` |

#### GET /api/ozon/delivery-analytics/details
Детальная аналитика по конкретному кластеру.

```typescript
GET /api/ozon/delivery-analytics/details?integration_id=15&cluster_id=154
```

### Параметры

| Параметр | Тип | Описание |
|----------|-----|----------|
| `integration_id` | int | **Обязательный.** ID интеграции Ozon |
| `delivery_schema` | string | `ALL`, `FBO`, `FBS`. По умолчанию: `ALL` |
| `supply_period` | string | `FOUR_WEEKS`, `EIGHT_WEEKS`. По умолчанию: `EIGHT_WEEKS` |
| `attention_level` | string | Фильтр: `LOW`, `ATTENTION_MEDIUM`, `ATTENTION_HI` |

### Уровни внимания (attention_level)

| Уровень | Описание | Цвет |
|---------|----------|------|
| `ATTENTION_HI` | Требует срочного внимания | 🔴 Красный |
| `ATTENTION_MEDIUM` | Требует внимания | 🟡 Жёлтый |
| `LOW` | Всё в порядке | 🟢 Зелёный |

### Статусы времени доставки

| Статус | Описание |
|--------|----------|
| `Fast` | Быстрая доставка (до 2 дней) |
| `Medium` | Средняя доставка (2-5 дней) |
| `Long` | Долгая доставка (более 5 дней) |

#### GET /api/ozon/delivery-analytics/by-products ⭐ НОВЫЙ
**Аналитика по товарам с разбивкой по кластерам отгрузки** — данные как на скриншоте Ozon (вкладка "По товарам").

Каждый товар показывает продажи по кластерам отгрузки (откуда отгружается): Новосибирск, Москва, Уфа, Казань и т.д.

```typescript
// Запрос
GET /api/ozon/delivery-analytics/by-products?integration_id=13

// Ответ
{
  "message": "Success",
  "summary": {
    "average_delivery_time_hours": 45,
    "average_delivery_time_status": "MEDIUM",
    "impact_share": 100,
    "lost_profit": 82849,
    "recommended_supply": 4822,
    "attention_level": "LOW",
    "orders": {
      "total": 1556,
      "long": { "value": 473, "percent": 30 },
      "medium": { "value": 189, "percent": 12 },
      "fast": { "value": 894, "percent": 57 }
    }
  },
  "products": [
    {
      "delivery_cluster_id": 154,
      "delivery_cluster_name": "Новосибирск",
      "sku": "A1",
      "product_id": 1423433655,
      "name": "Термобумага для мини-принтера 57 мм",
      "delivery_schema": "ALL",
      "average_delivery_time_hours": 67,
      "average_delivery_time_status": "LONG",
      "impact_share": 10,
      "exact_impact_share": 10.5,
      "lost_profit": 8701,
      "recommended_supply": 317,
      "attention_level": "ATTENTION_HI",
      "orders": {
        "total": 84,
        "long": { "value": 46, "percent": 55 },
        "medium": { "value": 4, "percent": 5 },
        "fast": { "value": 34, "percent": 40 }
      },
      "shipping_clusters": [
        {
          "cluster_id": 154,
          "cluster_name": "Новосибирск",
          "orders_count": 34,
          "orders_percent": 40,
          "delivery_time_fbo": 28,
          "delivery_time_fbs": null,
          "delivery_time_status": "FAST"
        },
        {
          "cluster_id": 2,
          "cluster_name": "Москва, МО и Дальние регионы",
          "orders_count": 4,
          "orders_percent": 5,
          "delivery_time_fbo": 60,
          "delivery_time_fbs": null,
          "delivery_time_status": "LONG"
        }
      ]
    }
  ],
  "stats": {
    "total_products": 89,
    "by_attention": {
      "high": 12,
      "medium": 35,
      "low": 42
    },
    "total_lost_profit": 82849,
    "total_recommended_supply": 4822
  },
  "cluster_names": {
    "2": "Москва, МО и Дальние регионы",
    "154": "Новосибирск",
    "7": "Уфа"
  }
}
```

**Колонки таблицы (как на Ozon):**
| Колонка | Поле API |
|---------|----------|
| Кластер доставки + Название товара | `delivery_cluster_name` + `name` |
| Ср. время доставки | `average_delivery_time_hours` (в часах!) |
| Доля влияния | `exact_impact_share` (%) |
| Переплата за логистику | `lost_profit` (₽) |
| Рекомендуемая поставка на 28 дней | `recommended_supply` (шт) |
| Заказано товаров (Всего) | `orders.total` |
| Долго | `orders.long.value` / `orders.long.percent` |
| Средне | `orders.medium.value` / `orders.medium.percent` |
| Быстро | `orders.fast.value` / `orders.fast.percent` |
| **Кластера отгрузки** | `shipping_clusters[]` — распределение продаж по складам |

**Кластера отгрузки (shipping_clusters):**
Показывает откуда отгружается товар и сколько заказов приходится на каждый склад:
- `cluster_name` — название кластера (Новосибирск, Москва, Уфа...)
- `orders_count` — количество заказов с этого склада
- `orders_percent` — процент от общего количества заказов
- `delivery_time_fbo` / `delivery_time_fbs` — время доставки FBO/FBS
- `delivery_time_status` — статус времени (FAST/MEDIUM/LONG)

---

### 🎯 Реализация UI как на Ozon Seller

На Ozon Seller есть **две вкладки**:
1. **"По кластерам"** — данные по регионам доставки (Москва, СПб, Краснодар...)
2. **"По товарам"** — данные по отдельным товарам (SKU) с разбивкой по кластерам отгрузки

#### Структура UI

```
┌─────────────────────────────────────────────────────────────────────────┐
│  [По кластерам]  [По товарам]                                           │
├─────────────────────────────────────────────────────────────────────────┤
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐   │
│  │ Требуют      │ │ Средний      │ │ Ср. время    │ │ Упущенная    │   │
│  │ внимания     │ │ приоритет    │ │ доставки     │ │ прибыль      │   │
│  │     0        │ │     0        │ │   38 ч       │ │  61 278 ₽    │   │
│  └──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘   │
├─────────────────────────────────────────────────────────────────────────┤
│  Кластер доставки    │ Ср.время │ Доля   │ Упущ.приб │ Рек.пост │ Заказы│
│  ────────────────────┼──────────┼────────┼───────────┼──────────┼───────│
│  Дальний Восток      │   80 ч   │ 20.9%  │  12 782 ₽ │  108 шт  │  52   │
│  Москва, МО          │   32 ч   │ 10.9%  │   6 683 ₽ │ 1103 шт  │ 359   │
│  Краснодар           │   40 ч   │ 10.7%  │   6 586 ₽ │  385 шт  │  67   │
│  Новосибирск         │   51 ч   │  7.2%  │   4 439 ₽ │  162 шт  │  33   │
└─────────────────────────────────────────────────────────────────────────┘
```

#### Какой endpoint использовать

| Вкладка | Endpoint | Описание |
|---------|----------|----------|
| **По кластерам** | `/api/ozon/delivery-analytics/by-clusters` | Данные по регионам доставки |
| **По товарам** | `/api/ozon/delivery-analytics/by-products` ⭐ | Данные по SKU с кластерами отгрузки |

#### Пример React компонента

```tsx
import { useState } from 'react';

type ViewMode = 'clusters' | 'products';

const OzonAnalyticsPage = ({ integrationId }: { integrationId: number }) => {
  const [viewMode, setViewMode] = useState<ViewMode>('clusters');
  
  return (
    <div>
      {/* Вкладки */}
      <div className="tabs">
        <button 
          className={viewMode === 'clusters' ? 'active' : ''}
          onClick={() => setViewMode('clusters')}
        >
          По кластерам
        </button>
        <button 
          className={viewMode === 'products' ? 'active' : ''}
          onClick={() => setViewMode('products')}
        >
          По товарам
        </button>
      </div>
      
      {/* Контент */}
      {viewMode === 'clusters' ? (
        <ClustersTable integrationId={integrationId} />
      ) : (
        <ProductsTable integrationId={integrationId} />
      )}
    </div>
  );
};

// Таблица по кластерам (как на Ozon)
const ClustersTable = ({ integrationId }: { integrationId: number }) => {
  const { data } = useQuery(['ozon-clusters', integrationId], () =>
    fetch(`/api/ozon/delivery-analytics/by-clusters?integration_id=${integrationId}`)
      .then(r => r.json())
  );
  
  return (
    <div>
      {/* Карточки статистики */}
      <div className="stats-cards">
        <StatCard label="Ср. время доставки" value={`${data?.summary?.average_delivery_time_hours} ч`} />
        <StatCard label="Упущенная прибыль" value={`${data?.summary?.lost_profit?.toLocaleString()} ₽`} />
        <StatCard label="Рек. поставка" value={`${data?.summary?.recommended_supply} шт`} />
      </div>
      
      {/* Таблица кластеров */}
      <table>
        <thead>
          <tr>
            <th>Кластер доставки</th>
            <th>Ср. время</th>
            <th>Доля влияния</th>
            <th>Упущ. прибыль</th>
            <th>Рек. поставка</th>
            <th>Заказы (Б/С/Д)</th>
          </tr>
        </thead>
        <tbody>
          {data?.clusters?.map((cluster: any) => (
            <tr key={cluster.cluster_id}>
              <td>{cluster.cluster_name}</td>
              <td>{cluster.average_delivery_time_hours} ч</td>
              <td>{cluster.exact_impact_share.toFixed(1)}%</td>
              <td>{cluster.lost_profit.toLocaleString()} ₽</td>
              <td>{cluster.recommended_supply} шт</td>
              <td>
                <span className="fast">{cluster.orders.fast.value}</span>
                <span className="medium">{cluster.orders.medium.value}</span>
                <span className="long">{cluster.orders.long.value}</span>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

// Таблица по товарам с кластерами отгрузки (как на скриншоте Ozon)
const ProductsTable = ({ integrationId }: { integrationId: number }) => {
  const { data } = useQuery(['ozon-products', integrationId], () =>
    fetch(`/api/ozon/delivery-analytics/by-products?integration_id=${integrationId}`)
      .then(r => r.json())
  );
  
  // Получаем уникальные кластеры отгрузки для заголовков колонок
  const allShippingClusters = new Map<number, string>();
  data?.products?.forEach((item: any) => {
    item.shipping_clusters?.forEach((sc: any) => {
      allShippingClusters.set(sc.cluster_id, sc.cluster_name);
    });
  });
  const shippingClusterIds = Array.from(allShippingClusters.keys());
  
  return (
    <div>
      {/* Карточки статистики */}
      <div className="stats-cards">
        <StatCard label="Ср. время доставки" value={`${data?.summary?.average_delivery_time_hours} ч`} />
        <StatCard label="Упущенная прибыль" value={`${data?.summary?.lost_profit?.toLocaleString()} ₽`} />
        <StatCard label="Рек. поставка" value={`${data?.summary?.recommended_supply} шт`} />
        <StatCard label="Заказов" value={data?.summary?.orders?.total} />
      </div>
      
      <table>
        <thead>
          <tr>
            <th>Кластер доставки + Товар</th>
            <th>Ср. время</th>
            <th>Доля влияния</th>
            <th>Переплата</th>
            <th>Рек. поставка</th>
            <th>Заказы</th>
            {/* Колонки кластеров отгрузки */}
            {shippingClusterIds.map(id => (
              <th key={id}>{allShippingClusters.get(id)}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data?.products?.map((item: any) => {
            // Создаём map для быстрого доступа к данным кластеров отгрузки
            const shippingMap = new Map(
              item.shipping_clusters?.map((sc: any) => [sc.cluster_id, sc])
            );
            
            return (
              <tr key={`${item.delivery_cluster_id}_${item.sku}`}>
                <td>
                  <div className="cluster-name">{item.delivery_cluster_name}</div>
                  <div className="product-name">{item.name}</div>
                  <div className="sku">{item.sku}</div>
                </td>
                <td>
                  <span className={`status-${item.average_delivery_time_status?.toLowerCase()}`}>
                    {item.average_delivery_time_hours} ч
                  </span>
                </td>
                <td>{item.exact_impact_share?.toFixed(1)}%</td>
                <td>{item.lost_profit?.toLocaleString()} ₽</td>
                <td>{item.recommended_supply} шт</td>
                <td>
                  <div>Всего: {item.orders?.total}</div>
                  <div className="orders-breakdown">
                    <span className="fast">Б: {item.orders?.fast?.percent}%</span>
                    <span className="medium">С: {item.orders?.medium?.percent}%</span>
                    <span className="long">Д: {item.orders?.long?.percent}%</span>
                  </div>
                </td>
                {/* Данные по кластерам отгрузки */}
                {shippingClusterIds.map(clusterId => {
                  const sc = shippingMap.get(clusterId) as any;
                  return (
                    <td key={clusterId}>
                      {sc ? (
                        <span className={`status-${sc.delivery_time_status?.toLowerCase()}`}>
                          {sc.orders_percent}%
                        </span>
                      ) : '—'}
                    </td>
                  );
                })}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
};
```

### Пример использования (старый)

```typescript
// Получить рекомендации Ozon по кластерам
const fetchOzonRecommendations = async (integrationId: number) => {
  const response = await fetch(
    `/api/ozon/delivery-analytics/recommendations?integration_id=${integrationId}`
  );
  const data = await response.json();
  
  // Товары требующие внимания
  const urgent = data.data.filter(
    (item: any) => item.attention_level === 'ATTENTION_HI'
  );
  
  console.log(`Срочных: ${urgent.length}`);
  console.log(`Упущенная прибыль: ${data.stats.total_lost_profit} ₽`);
  
  return data;
};
```

### Интеграция с существующими рекомендациями

Данные из `/api/ozon/delivery-analytics/recommendations` можно объединить с `/api/supply-recommendations` для более полной картины:

```typescript
// Объединение рекомендаций
const mergeRecommendations = (
  supplyRecs: SupplyRecommendation[],
  ozonAnalytics: OzonDeliveryItem[]
) => {
  return supplyRecs.map(rec => {
    const ozonData = ozonAnalytics.find(o => o.sku === rec.sku);
    return {
      ...rec,
      ozon_delivery_time: ozonData?.average_delivery_time,
      ozon_attention_level: ozonData?.attention_level,
      ozon_lost_profit: ozonData?.lost_profit,
      ozon_recommended_supply: ozonData?.recommended_supply,
    };
  });
};
```
