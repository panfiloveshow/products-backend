# Модуль «Поставки Ozon FBO» — Спецификация для Frontend

## Обзор

Модуль управления поставками на склады Ozon FBO. Включает:
1. **Рекомендации** — автоматический расчёт потребности в поставках
2. **Поставки** — создание, управление жизненным циклом
3. **Настройки** — конфигурация правил расчёта

---

## API Endpoints

**Base URL:** `/api/supplies`

---

## 1. Рекомендации

### 1.1 Получить список рекомендаций

```http
GET /api/supplies/recommendations
```

**Query параметры:**

| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| `integration_id` | integer | ✅ | ID интеграции Ozon |
| `state` | string | ❌ | Фильтр по статусу: `new`, `accepted`, `rejected`, `postponed`, `in_plan`, `in_supply`, `completed`, `expired` |
| `priority` | string | ❌ | Фильтр по приоритету: `A`, `B`, `C` |
| `oos_risk` | boolean | ❌ | Только с риском OOS |
| `cluster_id` | string | ❌ | Фильтр по кластеру |
| `warehouse_id` | string | ❌ | Фильтр по складу |
| `per_page` | integer | ❌ | Кол-во на странице (1-100, default: 20) |

**Response:**

```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "integration_id": 1,
        "sku": "SKU-12345",
        "ozon_product_id": "123456789",
        "product_name": "Товар 1",
        "cluster_id": "cluster_msk",
        "cluster_name": "Москва и область",
        "warehouse_id": "wh_123",
        "warehouse_name": "Склад Москва",
        "avg_sales_7d": 5.5,
        "avg_sales_14d": 4.8,
        "avg_sales_28d": 4.2,
        "avg_sales_used": 4.8,
        "sales_window": "14d",
        "current_stock": 50,
        "in_transit": 20,
        "safety_stock": 15,
        "target_days": 14,
        "demand": 67,
        "need_raw": 12,
        "recommended_qty": 15,
        "pack_multiple": 5,
        "min_order_qty": 5,
        "priority": "A",
        "priority_score": 85.5,
        "days_of_stock": 10,
        "oos_risk": true,
        "overstock_risk": false,
        "reasons": ["Высокие продажи", "Низкий остаток"],
        "warnings": ["Риск OOS через 3 дня"],
        "recommended_create_date": "2026-01-23",
        "recommended_delivery_date": "2026-01-26",
        "lead_time_days": 3,
        "state": "new",
        "user_qty": null,
        "user_comment": null,
        "created_at": "2026-01-22T10:00:00Z",
        "updated_at": "2026-01-22T10:00:00Z"
      }
    ],
    "total": 150,
    "per_page": 20,
    "last_page": 8
  }
}
```

### 1.2 Рассчитать рекомендации

```http
POST /api/supplies/recommendations/calculate
```

**Body:**

```json
{
  "integration_id": 1,
  "cluster_id": "cluster_msk"  // опционально
}
```

**Response:**

```json
{
  "success": true,
  "data": {
    "calculated": 150,
    "saved": 145
  },
  "message": "Рассчитано 150 рекомендаций"
}
```

### 1.3 Принять рекомендацию

```http
POST /api/supplies/recommendations/{id}/accept
```

**Body:**

```json
{
  "qty": 20  // опционально, если хотим изменить количество
}
```

**Response:**

```json
{
  "success": true,
  "data": { /* обновлённая рекомендация */ },
  "message": "Рекомендация принята"
}
```

### 1.4 Отклонить рекомендацию

```http
POST /api/supplies/recommendations/{id}/reject
```

**Body:**

```json
{
  "comment": "Товар снят с продажи"  // опционально
}
```

### 1.5 Отложить рекомендацию

```http
POST /api/supplies/recommendations/{id}/postpone
```

**Body:**

```json
{
  "comment": "Ждём поставку от поставщика"  // опционально
}
```

---

## 2. Поставки

### 2.1 Получить список поставок

```http
GET /api/supplies
```

**Query параметры:**

| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| `integration_id` | integer | ✅ | ID интеграции |
| `status` | string | ❌ | Фильтр по статусу (можно через запятую) |
| `cluster_id` | string | ❌ | Фильтр по кластеру |
| `warehouse_id` | string | ❌ | Фильтр по складу |
| `date_from` | date | ❌ | Дата создания от |
| `date_to` | date | ❌ | Дата создания до |
| `per_page` | integer | ❌ | Кол-во на странице |

**Response:**

```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "integration_id": 1,
        "crm_number": "SUP-2026-000001",
        "ozon_supply_id": "ozon_123",
        "ozon_draft_id": "draft_456",
        "supply_type": "fbo",
        "supply_method": "direct",
        "delivery_scheme": null,
        "cluster_id": "cluster_msk",
        "cluster_name": "Москва и область",
        "warehouse_id": "wh_123",
        "warehouse_name": "Склад Москва",
        "timeslot_id": "slot_789",
        "timeslot_from": "2026-01-25T10:00:00Z",
        "timeslot_to": "2026-01-25T12:00:00Z",
        "planned_delivery_date": "2026-01-25",
        "items_count": 5,
        "total_quantity": 150,
        "total_boxes": 10,
        "status": "slot_booked",
        "ozon_status": "SLOT_BOOKED",
        "created_by": {
          "id": 1,
          "name": "Иван Иванов"
        },
        "responsible": {
          "id": 2,
          "name": "Пётр Петров"
        },
        "comment": "Срочная поставка",
        "created_at": "2026-01-22T10:00:00Z",
        "items": [
          {
            "id": 1,
            "sku": "SKU-12345",
            "product_name": "Товар 1",
            "planned_qty": 30,
            "packed_qty": 0,
            "status": "pending"
          }
        ]
      }
    ],
    "total": 25
  }
}
```

### 2.2 Создать поставку из рекомендаций

```http
POST /api/supplies
```

**Body:**

```json
{
  "integration_id": 1,
  "recommendation_ids": [1, 2, 3, 5, 8],
  "supply_method": "direct",  // "direct" | "crossdock" | "multi_cluster"
  "delivery_scheme": null,    // "drop_off" | "pick_up" (для crossdock)
  "cluster_id": "cluster_msk", // опционально
  "warehouse_id": "wh_123",    // опционально
  "comment": "Срочная поставка"
}
```

**Response:**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "crm_number": "SUP-2026-000001",
    "status": "draft",
    "items_count": 5,
    "total_quantity": 150,
    "items": [...]
  },
  "message": "Поставка создана"
}
```

### 2.3 Получить детали поставки

```http
GET /api/supplies/{id}
```

**Response:**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "crm_number": "SUP-2026-000001",
    "ozon_supply_id": null,
    "ozon_draft_id": null,
    "supply_type": "fbo",
    "supply_method": "direct",
    "cluster_id": "cluster_msk",
    "cluster_name": "Москва и область",
    "warehouse_id": "wh_123",
    "warehouse_name": "Склад Москва",
    "status": "draft",
    "status_label": "Черновик",
    "is_editable": true,
    "can_create_draft": true,
    "can_book_slot": false,
    "items_count": 5,
    "total_quantity": 150,
    "items": [
      {
        "id": 1,
        "sku": "SKU-12345",
        "ozon_product_id": "123456789",
        "barcode": "4600000000001",
        "product_name": "Товар 1",
        "planned_qty": 30,
        "packed_qty": 0,
        "shipped_qty": 0,
        "accepted_qty": null,
        "rejected_qty": null,
        "pack_multiple": 5,
        "status": "pending",
        "status_label": "Ожидает"
      }
    ],
    "events": [
      {
        "id": 1,
        "event_type": "created",
        "title": "Поставка создана",
        "description": "Создана из 5 рекомендаций",
        "created_at": "2026-01-22T10:00:00Z"
      }
    ],
    "created_by": { "id": 1, "name": "Иван" },
    "responsible": { "id": 2, "name": "Пётр" },
    "created_at": "2026-01-22T10:00:00Z"
  }
}
```

### 2.4 Создать черновик в Ozon

```http
POST /api/supplies/{id}/create-draft
```

**Response:**

```json
{
  "success": true,
  "data": {
    "draft_id": "draft_abc123",
    "supply": { /* обновлённая поставка */ }
  },
  "message": "Черновик создан в Ozon"
}
```

### 2.5 Получить доступные слоты

```http
GET /api/supplies/{id}/timeslots
```

**Query параметры:**

| Параметр | Тип | Описание |
|----------|-----|----------|
| `use_cache` | boolean | Использовать кэш (default: true) |

**Response:**

```json
{
  "success": true,
  "data": {
    "timeslots": [
      {
        "timeslot_id": "slot_001",
        "date": "2026-01-25",
        "time_from": "10:00",
        "time_to": "12:00",
        "datetime_from": "2026-01-25T10:00:00Z",
        "datetime_to": "2026-01-25T12:00:00Z",
        "is_available": true,
        "capacity": 100,
        "remaining_capacity": 75
      },
      {
        "timeslot_id": "slot_002",
        "date": "2026-01-25",
        "time_from": "14:00",
        "time_to": "16:00",
        "datetime_from": "2026-01-25T14:00:00Z",
        "datetime_to": "2026-01-25T16:00:00Z",
        "is_available": true,
        "capacity": 100,
        "remaining_capacity": 50
      }
    ],
    "best_slot": {
      "slot": { /* лучший слот */ },
      "score": 95,
      "reasons": {
        "days_from_target": 2,
        "weekday": 5,
        "time": "10:00"
      }
    },
    "total": 12
  }
}
```

### 2.6 Забронировать слот

```http
POST /api/supplies/{id}/book-slot
```

**Body:**

```json
{
  "timeslot_id": "slot_001"
}
```

**Response:**

```json
{
  "success": true,
  "data": { /* обновлённая поставка */ },
  "message": "Слот забронирован"
}
```

### 2.7 Действия с поставкой

```http
POST /api/supplies/{id}/start-preparing   // Начать сборку
POST /api/supplies/{id}/ready-to-ship     // Готово к отгрузке
POST /api/supplies/{id}/ship              // Отгружено
POST /api/supplies/{id}/cancel            // Отменить
POST /api/supplies/{id}/sync-status       // Синхронизировать статус
```

**Cancel Body:**

```json
{
  "reason": "Причина отмены"  // опционально
}
```

### 2.8 Получить события поставки

```http
GET /api/supplies/{id}/events
```

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "event_type": "created",
      "title": "Поставка создана",
      "description": "Создана из 5 рекомендаций",
      "old_value": null,
      "new_value": null,
      "initiated_by": "user",
      "user": { "id": 1, "name": "Иван" },
      "created_at": "2026-01-22T10:00:00Z"
    },
    {
      "id": 2,
      "event_type": "draft_created",
      "title": "Черновик создан в Ozon",
      "new_value": "draft_abc123",
      "api_response_code": 200,
      "api_duration_ms": 450,
      "created_at": "2026-01-22T10:05:00Z"
    }
  ]
}
```

### 2.9 Статистика поставок

```http
GET /api/supplies/stats
```

**Query параметры:**

| Параметр | Тип | Описание |
|----------|-----|----------|
| `integration_id` | integer | ID интеграции |
| `period` | string | Период: `7d`, `14d`, `30d`, `90d` |

**Response:**

```json
{
  "success": true,
  "data": {
    "period": "30d",
    "total": 45,
    "by_status": {
      "drafts": 5,
      "in_progress": 8,
      "in_transit": 3,
      "completed": 25,
      "cancelled": 2,
      "errors": 2
    },
    "total_items": 1250,
    "avg_lead_time_hours": 72.5
  }
}
```

---

## 3. Настройки

### 3.1 Получить настройки

```http
GET /api/supplies/settings?integration_id=1
```

**Response:**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "integration_id": 1,
    "default_sales_window": "14d",
    "target_days_a": 21,
    "target_days_b": 14,
    "target_days_c": 7,
    "safety_stock_days": 3,
    "safety_stock_percent": 10,
    "safety_stock_mode": "days",
    "default_lead_time_days": 3,
    "min_order_qty": 1,
    "default_pack_multiple": 1,
    "oos_risk_days": 3,
    "overstock_days": 60,
    "preferred_weekdays": [1, 2, 3, 4, 5],
    "preferred_time_from": "10:00",
    "preferred_time_to": "16:00",
    "max_supplies_per_day": 3,
    "max_items_per_supply": 100,
    "auto_book_slot": false,
    "notify_no_slots": true,
    "notify_oos_risk": true,
    "notify_stuck_supply": true,
    "notify_api_errors": true,
    "excluded_skus": [],
    "is_active": true
  }
}
```

### 3.2 Обновить настройки

```http
PUT /api/supplies/settings
```

**Body:**

```json
{
  "integration_id": 1,
  "target_days_a": 28,
  "oos_risk_days": 2,
  "auto_book_slot": true,
  "preferred_weekdays": [1, 2, 3, 4, 5]
}
```

---

## 4. Статусы и константы

### 4.1 Статусы поставки

| Статус | Label | Описание | Цвет |
|--------|-------|----------|------|
| `draft` | Черновик | Создана в CRM | `gray` |
| `draft_ozon` | Черновик Ozon | Создана в Ozon | `blue` |
| `slot_pending` | Ожидает слот | Нужно выбрать слот | `yellow` |
| `slot_booked` | Слот забронирован | Слот выбран | `cyan` |
| `preparing` | Сборка | Идёт сборка | `orange` |
| `ready_to_ship` | Готово к отгрузке | Собрано | `lime` |
| `shipped` | Отгружено | Отправлено | `blue` |
| `in_transit` | В пути | Едет на склад | `purple` |
| `at_warehouse` | На приёмке | На складе Ozon | `indigo` |
| `accepted_partial` | Принято частично | Есть расхождения | `yellow` |
| `accepted_full` | Принято полностью | Всё ок | `green` |
| `closed` | Закрыто | Завершено | `gray` |
| `cancelled` | Отменено | Отменена | `red` |
| `error` | Ошибка | Ошибка API | `red` |

### 4.2 Приоритеты рекомендаций

| Приоритет | Критерий | Цвет |
|-----------|----------|------|
| `A` | Выручка ≥ 100 000 ₽/мес | `red` |
| `B` | Выручка ≥ 30 000 ₽/мес | `yellow` |
| `C` | Выручка < 30 000 ₽/мес | `gray` |

### 4.3 Статусы рекомендаций

| Статус | Label |
|--------|-------|
| `new` | Новая |
| `accepted` | Принята |
| `rejected` | Отклонена |
| `postponed` | Отложена |
| `in_plan` | В плане |
| `in_supply` | В поставке |
| `completed` | Выполнена |
| `expired` | Устарела |

### 4.4 Типы поставок

| Тип | Описание |
|-----|----------|
| `direct` | Прямая поставка на склад |
| `crossdock` | Через кросс-док точку |
| `multi_cluster` | Мультикластерная |

---

## 5. UI Компоненты (рекомендации)

### 5.1 Страница «Рекомендации»

```
┌─────────────────────────────────────────────────────────────────┐
│ Рекомендации на поставку                    [Рассчитать]        │
├─────────────────────────────────────────────────────────────────┤
│ Фильтры: [Кластер ▼] [Приоритет ▼] [Статус ▼] [☐ Только OOS]   │
├─────────────────────────────────────────────────────────────────┤
│ ☐ │ SKU        │ Товар      │ Остаток │ Продажи │ Рек. кол-во │ │
│───┼────────────┼────────────┼─────────┼─────────┼─────────────┼─│
│ ☐ │ SKU-001 🔴 │ Товар 1    │ 50      │ 5.5/день│ 30          │⋮│
│ ☐ │ SKU-002 🟡 │ Товар 2    │ 120     │ 3.2/день│ 20          │⋮│
│ ☐ │ SKU-003 ⚪ │ Товар 3    │ 200     │ 1.1/день│ 10          │⋮│
├─────────────────────────────────────────────────────────────────┤
│ Выбрано: 3 позиции, 60 шт.        [Создать поставку]            │
└─────────────────────────────────────────────────────────────────┘
```

### 5.2 Страница «Поставки»

```
┌─────────────────────────────────────────────────────────────────┐
│ Поставки                                    [+ Новая поставка]  │
├─────────────────────────────────────────────────────────────────┤
│ Фильтры: [Статус ▼] [Склад ▼] [Дата от] [Дата до]              │
├─────────────────────────────────────────────────────────────────┤
│ Номер         │ Склад      │ Позиций │ Кол-во │ Статус   │ Дата │
│───────────────┼────────────┼─────────┼────────┼──────────┼──────│
│ SUP-2026-0001 │ Москва     │ 5       │ 150    │ 🔵 Слот  │ 22.01│
│ SUP-2026-0002 │ СПб        │ 3       │ 80     │ 🟢 Принят│ 21.01│
│ SUP-2026-0003 │ Казань     │ 8       │ 200    │ ⚪ Черн. │ 22.01│
└─────────────────────────────────────────────────────────────────┘
```

### 5.3 Детали поставки

```
┌─────────────────────────────────────────────────────────────────┐
│ Поставка SUP-2026-0001                      Статус: Слот забр.  │
├─────────────────────────────────────────────────────────────────┤
│ Склад: Москва (cluster_msk)                                     │
│ Слот: 25.01.2026 10:00-12:00                                    │
│ Позиций: 5 | Количество: 150 шт.                                │
├─────────────────────────────────────────────────────────────────┤
│ Действия: [Начать сборку] [Отменить]                            │
├─────────────────────────────────────────────────────────────────┤
│ Позиции:                                                        │
│ SKU        │ Товар           │ План │ Собрано │ Статус          │
│────────────┼─────────────────┼──────┼─────────┼─────────────────│
│ SKU-001    │ Товар 1         │ 30   │ 0       │ Ожидает         │
│ SKU-002    │ Товар 2         │ 50   │ 0       │ Ожидает         │
├─────────────────────────────────────────────────────────────────┤
│ История:                                                        │
│ 22.01 10:05 │ Слот забронирован │ slot_001                      │
│ 22.01 10:02 │ Черновик создан   │ draft_abc123                  │
│ 22.01 10:00 │ Поставка создана  │ Иван Иванов                   │
└─────────────────────────────────────────────────────────────────┘
```

---

## 6. Workflow поставки

```
1. Выбрать рекомендации → POST /api/supplies (создать поставку)
2. Создать черновик → POST /api/supplies/{id}/create-draft
3. Получить слоты → GET /api/supplies/{id}/timeslots
4. Забронировать слот → POST /api/supplies/{id}/book-slot
5. Начать сборку → POST /api/supplies/{id}/start-preparing
6. Готово к отгрузке → POST /api/supplies/{id}/ready-to-ship
7. Отгрузить → POST /api/supplies/{id}/ship
8. Ждать приёмку (статус обновится автоматически через sync)
```

---

## 7. Обработка ошибок

Все ошибки возвращаются в формате:

```json
{
  "success": false,
  "message": "Текст ошибки для пользователя"
}
```

HTTP коды:
- `200` — успех
- `201` — создано
- `422` — ошибка валидации или бизнес-логики
- `404` — не найдено
- `500` — серверная ошибка

---

## 8. Примеры TypeScript типов

```typescript
// Рекомендация
interface SupplyRecommendation {
  id: number;
  integration_id: number;
  sku: string;
  ozon_product_id: string | null;
  product_name: string | null;
  cluster_id: string | null;
  cluster_name: string | null;
  warehouse_id: string | null;
  warehouse_name: string | null;
  avg_sales_7d: number;
  avg_sales_14d: number;
  avg_sales_28d: number;
  avg_sales_used: number;
  sales_window: '7d' | '14d' | '28d';
  current_stock: number;
  in_transit: number;
  safety_stock: number;
  target_days: number;
  demand: number;
  need_raw: number;
  recommended_qty: number;
  pack_multiple: number;
  min_order_qty: number;
  priority: 'A' | 'B' | 'C';
  priority_score: number;
  days_of_stock: number;
  oos_risk: boolean;
  overstock_risk: boolean;
  reasons: string[];
  warnings: string[];
  recommended_create_date: string | null;
  recommended_delivery_date: string | null;
  lead_time_days: number;
  state: RecommendationState;
  user_qty: number | null;
  user_comment: string | null;
  created_at: string;
  updated_at: string;
}

type RecommendationState = 
  | 'new' 
  | 'accepted' 
  | 'rejected' 
  | 'postponed' 
  | 'in_plan' 
  | 'in_supply' 
  | 'completed' 
  | 'expired';

// Поставка
interface Supply {
  id: number;
  integration_id: number;
  crm_number: string;
  ozon_supply_id: string | null;
  ozon_draft_id: string | null;
  supply_type: 'fbo' | 'fbs' | 'realfbs';
  supply_method: 'direct' | 'crossdock' | 'multi_cluster';
  delivery_scheme: 'drop_off' | 'pick_up' | null;
  cluster_id: string | null;
  cluster_name: string | null;
  warehouse_id: string | null;
  warehouse_name: string | null;
  timeslot_id: string | null;
  timeslot_from: string | null;
  timeslot_to: string | null;
  planned_delivery_date: string | null;
  items_count: number;
  total_quantity: number;
  total_boxes: number;
  status: SupplyStatus;
  status_label: string;
  is_editable: boolean;
  can_create_draft: boolean;
  can_book_slot: boolean;
  items: SupplyItem[];
  events?: SupplyEvent[];
  created_by: User | null;
  responsible: User | null;
  comment: string | null;
  created_at: string;
  updated_at: string;
}

type SupplyStatus = 
  | 'draft'
  | 'draft_ozon'
  | 'slot_pending'
  | 'slot_booked'
  | 'preparing'
  | 'ready_to_ship'
  | 'shipped'
  | 'in_transit'
  | 'at_warehouse'
  | 'accepted_partial'
  | 'accepted_full'
  | 'closed'
  | 'cancelled'
  | 'error';

// Позиция поставки
interface SupplyItem {
  id: number;
  supply_id: number;
  sku: string;
  ozon_product_id: string | null;
  barcode: string | null;
  product_name: string | null;
  planned_qty: number;
  packed_qty: number;
  shipped_qty: number;
  accepted_qty: number | null;
  rejected_qty: number | null;
  pack_multiple: number;
  status: 'pending' | 'packed' | 'shipped' | 'accepted' | 'rejected';
  status_label: string;
}

// Событие поставки
interface SupplyEvent {
  id: number;
  supply_id: number;
  event_type: string;
  title: string | null;
  description: string | null;
  old_value: string | null;
  new_value: string | null;
  initiated_by: 'user' | 'system' | 'api';
  user: User | null;
  created_at: string;
}

// Таймслот
interface Timeslot {
  timeslot_id: string;
  date: string;
  time_from: string;
  time_to: string;
  datetime_from: string;
  datetime_to: string;
  is_available: boolean;
  capacity: number | null;
  remaining_capacity: number | null;
}

// Настройки
interface SupplySettings {
  id: number;
  integration_id: number;
  default_sales_window: '7d' | '14d' | '28d';
  target_days_a: number;
  target_days_b: number;
  target_days_c: number;
  safety_stock_days: number;
  safety_stock_percent: number;
  safety_stock_mode: 'days' | 'percent' | 'max';
  default_lead_time_days: number;
  min_order_qty: number;
  default_pack_multiple: number;
  oos_risk_days: number;
  overstock_days: number;
  preferred_weekdays: number[];
  preferred_time_from: string | null;
  preferred_time_to: string | null;
  max_supplies_per_day: number;
  max_items_per_supply: number;
  auto_book_slot: boolean;
  notify_no_slots: boolean;
  notify_oos_risk: boolean;
  notify_stuck_supply: boolean;
  notify_api_errors: boolean;
  excluded_skus: string[];
  is_active: boolean;
}
```

---

## 9. Важные замечания

1. **Порядок действий:** Нельзя забронировать слот без черновика в Ozon
2. **Статусы:** Некоторые действия доступны только в определённых статусах (см. `is_editable`, `can_create_draft`, `can_book_slot`)
3. **OOS риск:** Рекомендации с `oos_risk: true` требуют приоритетного внимания
4. **Кэш слотов:** Слоты кэшируются на 30 минут, можно принудительно обновить через `use_cache=false`
5. **События:** Все действия логируются в events для аудита
