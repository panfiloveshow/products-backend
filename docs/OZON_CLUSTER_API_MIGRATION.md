# Миграция на кластерную модель Ozon FBO (с 16.02.2026)

## Обзор изменений

С 16 февраля 2026 года Ozon переходит на кластерную модель для кросс-докинговых FBO поставок:

- **warehouse_id** остаётся актуальным только для прямых поставок
- **macrolocal_cluster_id** становится основным идентификатором для кросс-дока
- Таймслоты предоставляются для кластера, а не для склада
- Заявка формируется на кластер, а не на конкретный склад

## Новые API Endpoints

### Кластеры

```
POST /api/ozon/clusters/list
{
  "integration_id": 1
}

Response:
{
  "success": true,
  "data": {
    "clusters": [
      {
        "id": "cluster_123",
        "name": "Московский кластер",
        "region": "Москва",
        "warehouses_count": 5,
        "is_active": true
      }
    ]
  }
}
```

### Прямая поставка (Direct)

Доставка товаров напрямую на выбранный склад.

```
POST /api/ozon/draft/direct/create
{
  "integration_id": 1,
  "macrolocal_cluster_id": "cluster_123",
  "items": [
    {"sku": "SKU-001", "quantity": 10},
    {"sku": "SKU-002", "quantity": 5}
  ]
}

Response:
{
  "success": true,
  "data": {
    "draft_id": "draft_456",
    "status": "draft",
    "supply_method": "direct",
    "macrolocal_cluster_id": "cluster_123",
    "created_at": "2026-01-22T10:00:00Z"
  }
}
```

### Кросс-док поставка (Crossdock)

Отгрузка через транзитный пункт с последующей доставкой на конечный склад FBO.

**Drop Off (отвезти на точку):**
```
POST /api/ozon/draft/crossdock/create
{
  "integration_id": 1,
  "macrolocal_cluster_id": "cluster_123",
  "delivery_scheme": "drop_off",
  "point_id": "point_456",
  "point_type": "PVZ",
  "items": [...]
}
```

**Pick Up (забор со склада продавца):**
```
POST /api/ozon/draft/crossdock/create
{
  "integration_id": 1,
  "macrolocal_cluster_id": "cluster_123",
  "delivery_scheme": "pick_up",
  "seller_warehouse_id": "seller_wh_789",
  "items": [...]
}
```

### Мультикластерная поставка (Multi-cluster)

Создание нескольких поставок на разные склады FBO в рамках одной заявки.

```
POST /api/ozon/draft/multi-cluster/create
{
  "integration_id": 1,
  "cluster_ids": ["cluster_1", "cluster_2", "cluster_3"],
  "delivery_scheme": "drop_off",
  "point_id": "point_456",
  "point_type": "PVZ",
  "items": [...]
}
```

### Статус и расчёты черновика (v2)

```
POST /api/ozon/draft/v2/info
{
  "integration_id": 1,
  "draft_id": "draft_456"
}

Response:
{
  "success": true,
  "data": {
    "draft_id": "draft_456",
    "status": "calculated",
    "errors": [],
    "warehouses": [
      {
        "warehouse_id": "wh_001",
        "warehouse_name": "Склад Москва-1",
        "cluster_id": "cluster_123",
        "items_count": 2,
        "total_quantity": 15,
        "estimated_cost": 1500.00,
        "is_available": true
      }
    ]
  }
}
```

### Таймслоты для черновика (v2)

```
POST /api/ozon/draft/v2/timeslots
{
  "integration_id": 1,
  "draft_id": "draft_456",
  "warehouse_id": "wh_001"
}

Response:
{
  "success": true,
  "data": {
    "timeslots": [
      {
        "id": "slot_789",
        "warehouse_id": "wh_001",
        "date": "2026-01-25",
        "time_from": "10:00",
        "time_to": "12:00",
        "from_datetime": "2026-01-25T10:00:00Z",
        "to_datetime": "2026-01-25T12:00:00Z",
        "is_available": true,
        "capacity": 100
      }
    ],
    "draft_id": "draft_456",
    "warehouse_id": "wh_001"
  }
}
```

### Создание поставки из черновика (v2)

```
POST /api/ozon/draft/v2/supply/create
{
  "integration_id": 1,
  "draft_id": "draft_456",
  "warehouse_id": "wh_001",
  "timeslot_id": "slot_789"
}

Response:
{
  "success": true,
  "data": {
    "success": true,
    "draft_id": "draft_456",
    "warehouse_id": "wh_001",
    "timeslot_id": "slot_789",
    "supply_order_id": 123456789,
    "created_at": "2026-01-22T10:30:00Z"
  },
  "message": "Поставка создана"
}
```

### Статус создания поставки (v2)

```
POST /api/ozon/draft/v2/supply/status
{
  "integration_id": 1,
  "draft_id": "draft_456"
}
```

### Склады FBO

```
POST /api/ozon/warehouses/fbo/list
{
  "integration_id": 1
}

Response:
{
  "success": true,
  "data": {
    "warehouses": [
      {
        "id": "wh_001",
        "name": "Склад Москва-1",
        "type": "FBO",
        "address": "...",
        "city": "Москва",
        "region": "Московская область",
        "cluster_id": "cluster_123",
        "cluster_name": "Московский кластер",
        "is_active": true
      }
    ]
  }
}
```

### Склады продавца (для Pick Up)

```
POST /api/ozon/warehouses/seller/list
{
  "integration_id": 1
}
```

### Грузоместа в поставке (бета)

```
POST /api/ozon/cargoes/get
{
  "integration_id": 1,
  "supply_order_id": 123456789
}
```

## Новые поля в БД

### Таблица `shipments`

| Поле | Тип | Описание |
|------|-----|----------|
| macrolocal_cluster_id | varchar(50) | ID макролокального кластера Ozon |
| cluster_name | varchar(200) | Название кластера |
| supply_method | enum | direct, crossdock, multi_cluster |
| delivery_scheme | enum | drop_off, pick_up |

### Таблица `supply_plans`

| Поле | Тип | Описание |
|------|-----|----------|
| macrolocal_cluster_id | varchar(50) | ID макролокального кластера |
| cluster_name | varchar(200) | Название кластера |
| warehouse_id | varchar(50) | ID склада (для прямых поставок) |
| warehouse_name | varchar(200) | Название склада |
| supply_method | enum | direct, crossdock, multi_cluster |
| delivery_scheme | enum | drop_off, pick_up |

## Обратная совместимость

Legacy endpoints продолжают работать:
- `POST /api/ozon/draft/create` — использует warehouse_id
- `POST /api/ozon/draft/info`
- `POST /api/ozon/draft/timeslots`
- `POST /api/ozon/draft/supply/create`
- `POST /api/ozon/warehouses`

## Рекомендации для фронтенда

1. **До 16.02.2026**: можно использовать как старые, так и новые endpoints
2. **После 16.02.2026**: для кросс-дока обязательно использовать новые endpoints с cluster_id
3. **Прямые поставки**: можно продолжать использовать warehouse_id

### Пример флоу создания кросс-док поставки:

```typescript
// 1. Получить список кластеров
const clusters = await api.post('/ozon/clusters/list', { integration_id });

// 2. Получить склады FBO для выбора точки Drop Off
const warehouses = await api.post('/ozon/warehouses/fbo/list', { integration_id });

// 3. Создать черновик кросс-док поставки
const draft = await api.post('/ozon/draft/crossdock/create', {
  integration_id,
  macrolocal_cluster_id: selectedCluster.id,
  delivery_scheme: 'drop_off',
  point_id: selectedWarehouse.id,
  point_type: selectedWarehouse.type,
  items: cartItems
});

// 4. Получить расчёты по складам в кластере
const info = await api.post('/ozon/draft/v2/info', {
  integration_id,
  draft_id: draft.data.draft_id
});

// 5. Выбрать склад и получить таймслоты
const timeslots = await api.post('/ozon/draft/v2/timeslots', {
  integration_id,
  draft_id: draft.data.draft_id,
  warehouse_id: selectedWarehouse.id
});

// 6. Создать поставку
const supply = await api.post('/ozon/draft/v2/supply/create', {
  integration_id,
  draft_id: draft.data.draft_id,
  warehouse_id: selectedWarehouse.id,
  timeslot_id: selectedTimeslot.id
});
```

## Ссылки

- [Официальная документация Ozon](https://dev.ozon.ru/news/647-Izmeneniia-v-metodakh-Seller-API-pri-rabote-s-postavkami-FBO/)
- Миграция: `2026_01_22_131500_add_macrolocal_cluster_id_to_tables.php`
