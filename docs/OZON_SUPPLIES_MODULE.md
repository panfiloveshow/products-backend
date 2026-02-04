# Модуль «Поставки Ozon FBO»

## Обзор

Модуль автоматизирует управление поставками на склады Ozon FBO:
- Автоматический расчёт рекомендаций на поставку
- Создание и управление поставками
- Интеграция с Ozon API (черновики, слоты, статусы)
- Мониторинг жизненного цикла поставки
- Аналитика и уведомления

## 📦 Создание поставки FBO — эталонный порядок в ЛК Ozon (25 шагов)

FBO → **Заявки на поставку**

| # | Шаг в ЛК Ozon | API Endpoint | Метод |
|---|---------------|--------------|-------|
| 1 | FBO - Заявки на поставку | `GET /api/supplies` | index |
| 2 | Создать поставку | — | UI |
| 3 | Выбираем кластер (ставим галочку) | `GET /api/supplies/clusters` | getClusters |
| 4 | Товары в заявке | `GET /api/supplies/clusters/{id}/products?tab=in_supply` | getClusterProducts |
| 5 | Добавить | — | UI |
| 6 | Выбираем товар | — | UI |
| 7 | Проставляем количество | — | UI |
| 8 | Сохранить | `POST /api/supplies/clusters/{id}/add-products` | addClusterProducts |
| 9 | Способ доставки | — | UI |
| 10 | Выбираем ПВЗ или СЦ | `POST /api/supplies/clusters/{id}/delivery` | setClusterDeliveryMethod |
| 11 | Сохранить | (в том же endpoint) | — |
| 12 | Далее | — | UI |
| 13 | Выбираем склад | `POST /api/supplies/clusters/{id}/warehouse` | setClusterWarehouse |
| 14 | Дата и время | — | UI |
| 15 | Выбираем слот | `GET /api/supplies/slots` | getSlots |
| 16 | Подтвердить | — | UI |
| 17 | Создать поставку | `POST /api/supplies/create-with-slot` | createWithSlot |
| 18 | Укажите количество грузомест | — | UI |
| 19 | Добавить грузоместа | `POST /api/supplies/{id}/packages` | store |
| 20 | Указываем коробы/паллеты | `PUT /api/supplies/{id}/packages/{pkgId}` | update |
| 21 | Три точки (⋮) | — | UI |
| 22 | Указать состав | — | UI |
| 23 | В грузоместе - Добавить | `POST /api/supplies/{id}/packages/{pkgId}/items` | addItem |
| 24 | Годен до (срок годности) | `expiry_date` в addItem | — |
| 25 | Сохранить состав | `POST /api/supplies/{id}/packages/{pkgId}/pack` | pack |

### Пример полного flow (curl)

```bash
# 1. Получить список кластеров
GET /api/supplies/clusters?integration_id=1

# 2. Получить товары для кластера
GET /api/supplies/clusters/cluster_msk/products?integration_id=1&tab=recommendations

# 3. Добавить товары в заявку
POST /api/supplies/clusters/cluster_msk/add-products
{ "integration_id": 1, "products": [{"sku": "SKU001", "quantity": 100}] }

# 4. Выбрать способ доставки (ПВЗ)
POST /api/supplies/clusters/cluster_msk/delivery
{ "integration_id": 1, "delivery_type": "pvz" }

# 5. Выбрать склад
POST /api/supplies/clusters/cluster_msk/warehouse
{ "integration_id": 1, "warehouse_id": "22655170176000" }

# 6. Получить слоты
GET /api/supplies/slots?integration_id=1&cluster_ids[]=cluster_msk

# 7. Создать поставку со слотом
POST /api/supplies/create-with-slot
{ "integration_id": 1, "cluster_id": "cluster_msk", "slot_id": "slot_123" }

# 8. Создать грузоместо
POST /api/supplies/42/packages
{ "package_type": "box" }

# 9. Добавить товар в грузоместо
POST /api/supplies/42/packages/1/items
{ "sku": "SKU001", "quantity": 50, "expiry_date": "2026-12-31" }

# 10. Упаковать грузоместо
POST /api/supplies/42/packages/1/pack
```

## Архитектура

```
┌─────────────────────────────────────────────────────────────────┐
│                        API Endpoints                             │
│                    /api/supplies/*                               │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                     SupplyController                             │
│  - Рекомендации: список, принять, отклонить                     │
│  - Поставки: CRUD, действия, статусы                            │
│  - Слоты: список, бронирование                                  │
│  - Настройки: получить, обновить                                │
└─────────────────────────────────────────────────────────────────┘
                              │
              ┌───────────────┴───────────────┐
              ▼                               ▼
┌─────────────────────────┐     ┌─────────────────────────┐
│ SupplyRecommendation    │     │     SupplyService       │
│       Service           │     │                         │
│                         │     │ - Создание поставок     │
│ - Расчёт потребности    │     │ - Интеграция с Ozon API │
│ - ABC-приоритизация     │     │ - Управление статусами  │
│ - Сохранение рекоменд.  │     │ - Бронирование слотов   │
└─────────────────────────┘     └─────────────────────────┘
              │                               │
              ▼                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                         Models                                   │
│  Supply, SupplyItem, SupplyEvent, SupplyRecommendation,         │
│  SupplySettings, TimeslotCache, SupplyAnalytics                 │
└─────────────────────────────────────────────────────────────────┘
```

## API Endpoints

### Новый flow FBO (соответствует шагам 1-24 из ЛК Ozon)

| Шаг | Метод | Endpoint | Описание |
|-----|-------|----------|----------|
| 1-2 | GET | `/api/supplies/clusters` | Получить кластеры |
| 3-7 | POST | `/api/supplies/clusters/{clusterId}/add-products` | Добавить товары в заявку |
| 8-9 | POST | `/api/supplies/clusters/{clusterId}/delivery` | Выбрать ПВЗ или СЦ |
| 10-11 | POST | `/api/supplies/clusters/{clusterId}/warehouse` | Выбрать склад внутри кластера |
| 12-13 | GET | `/api/supplies/slots` | Получить слоты приёмки |
| 14-15 | POST | `/api/supplies/create-with-slot` | Создать поставку со слотом |
| 16-18 | POST | `/api/supplies/{id}/packages` | Создать грузоместо |
| 19-22 | POST | `/api/supplies/{id}/packages/{pkgId}/items` | Добавить товар в грузоместо |
| 23-24 | POST | `/api/supplies/{id}/packages/{pkgId}/pack` | Сохранить состав (упаковать) |

### Кластеры и слоты

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET | `/api/supplies/clusters` | Список кластеров с рекомендациями |
| GET | `/api/supplies/clusters/{clusterId}/products` | Товары кластера (рекомендации/в заявке) |
| POST | `/api/supplies/clusters/{clusterId}/add-products` | Добавить товары в заявку кластера |
| POST | `/api/supplies/clusters/{clusterId}/delivery` | Указать способ доставки (pvz/sc) |
| POST | `/api/supplies/clusters/{clusterId}/warehouse` | Указать склад внутри кластера |
| GET | `/api/supplies/slots` | Слоты приёмки по складам |
| POST | `/api/supplies/sync-slots` | Синхронизировать слоты |
| POST | `/api/supplies/create-with-slot` | Создать поставку со слотом |

### Рекомендации (legacy)

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET | `/api/supplies/recommendations` | Список рекомендаций |
| POST | `/api/supplies/recommendations/calculate` | Рассчитать рекомендации |
| POST | `/api/supplies/recommendations/{id}/accept` | Принять рекомендацию |
| POST | `/api/supplies/recommendations/{id}/reject` | Отклонить рекомендацию |
| POST | `/api/supplies/recommendations/{id}/postpone` | Отложить рекомендацию |

### Поставки CRUD

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET | `/api/supplies` | Список поставок |
| POST | `/api/supplies` | Создать поставку из рекомендаций (legacy) |
| GET | `/api/supplies/stats` | Статистика поставок |
| GET | `/api/supplies/{id}` | Детали поставки |
| GET | `/api/supplies/{id}/events` | События поставки |
| POST | `/api/supplies/{id}/create-draft` | Создать черновик в Ozon |
| GET | `/api/supplies/{id}/timeslots` | Доступные слоты |
| POST | `/api/supplies/{id}/book-slot` | Забронировать слот |
| POST | `/api/supplies/{id}/start-preparing` | Начать сборку |
| POST | `/api/supplies/{id}/ready-to-ship` | Готово к отгрузке |
| POST | `/api/supplies/{id}/ship` | Отметить отгрузку |
| POST | `/api/supplies/{id}/cancel` | Отменить поставку |
| POST | `/api/supplies/{id}/sync-status` | Синхронизировать статус |

### Грузоместа (шаги 16-24)

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET | `/api/supplies/{id}/packages` | Список грузомест |
| POST | `/api/supplies/{id}/packages` | Создать грузоместо |
| GET | `/api/supplies/{id}/packages/summary` | Сводка по грузоместам |
| POST | `/api/supplies/{id}/packages/auto-pack` | Авто-распределение товаров |
| GET | `/api/supplies/{id}/packages/{pkgId}` | Детали грузоместа |
| PUT | `/api/supplies/{id}/packages/{pkgId}` | Обновить грузоместо |
| DELETE | `/api/supplies/{id}/packages/{pkgId}` | Удалить грузоместо |
| POST | `/api/supplies/{id}/packages/{pkgId}/items` | Добавить товар (+ expiry_date) |
| DELETE | `/api/supplies/{id}/packages/{pkgId}/items/{itemId}` | Удалить товар |
| POST | `/api/supplies/{id}/packages/{pkgId}/pack` | Упаковать грузоместо |
| POST | `/api/supplies/{id}/packages/{pkgId}/label` | Этикетка грузоместа |

### Документы

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET | `/api/supplies/{id}/documents` | Список документов |
| GET | `/api/supplies/{id}/documents/{docId}` | Детали документа |
| GET | `/api/supplies/{id}/documents/{docId}/download` | Скачать документ |
| POST | `/api/supplies/{id}/labels/generate-all` | Сгенерировать все этикетки |
| POST | `/api/supplies/{id}/documents/packing-list` | Упаковочный лист |

### Настройки

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET | `/api/supplies/settings` | Получить настройки |
| PUT | `/api/supplies/settings` | Обновить настройки |

## Формула расчёта рекомендаций

```
demand = avg_sales_per_day(window) × target_days
need = max(0, demand - (stock_fbo + in_transit - safety_stock))
recommended_qty = round_to_multiple(need, pack_multiple, min_order_qty)
```

### Параметры

| Параметр | Описание | По умолчанию |
|----------|----------|--------------|
| `sales_window` | Окно продаж (7d/14d/28d) | 14d |
| `target_days_a` | Дни покрытия для категории A | 21 |
| `target_days_b` | Дни покрытия для категории B | 14 |
| `target_days_c` | Дни покрытия для категории C | 7 |
| `safety_stock_days` | Страховой запас в днях | 3 |
| `oos_risk_days` | Порог OOS риска | 3 |
| `overstock_days` | Порог перезатаривания | 60 |

## Статусы поставки

```
draft → draft_ozon → slot_pending → slot_booked → preparing → 
ready_to_ship → shipped → in_transit → at_warehouse → 
accepted_partial/accepted_full → closed
```

| Статус | Описание |
|--------|----------|
| `draft` | Черновик в CRM |
| `draft_ozon` | Черновик создан в Ozon |
| `slot_pending` | Ожидает выбора слота |
| `slot_booked` | Слот забронирован |
| `preparing` | Сборка в процессе |
| `ready_to_ship` | Готово к отгрузке |
| `shipped` | Отгружено |
| `in_transit` | В пути |
| `at_warehouse` | На приёмке |
| `accepted_partial` | Принято частично |
| `accepted_full` | Принято полностью |
| `closed` | Закрыто |
| `cancelled` | Отменено |
| `error` | Ошибка |

## Приоритеты ABC

| Приоритет | Критерий (выручка 30д) | Дни покрытия |
|-----------|------------------------|--------------|
| A | ≥ 100 000 ₽ | 21 день |
| B | ≥ 30 000 ₽ | 14 дней |
| C | < 30 000 ₽ | 7 дней |

## Jobs

### CalculateSupplyRecommendationsJob

Автоматический расчёт рекомендаций. Рекомендуется запускать ежедневно.

```php
// Запуск для конкретной интеграции
CalculateSupplyRecommendationsJob::dispatch($integrationId);

// Запуск для кластера
CalculateSupplyRecommendationsJob::dispatch($integrationId, $clusterId);
```

### SyncSupplyStatusesJob

Синхронизация статусов активных поставок из Ozon. Рекомендуется запускать каждые 15-30 минут.

```php
// Для всех интеграций
SyncSupplyStatusesJob::dispatch();

// Для конкретной интеграции
SyncSupplyStatusesJob::dispatch($integrationId);
```

## Настройки (SupplySettings)

```json
{
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
  "max_supplies_per_day": 3,
  "max_items_per_supply": 100,
  "auto_book_slot": false,
  "notify_no_slots": true,
  "notify_oos_risk": true,
  "notify_stuck_supply": true,
  "notify_api_errors": true,
  "is_active": true
}
```

## Примеры использования

### Получить рекомендации с OOS риском

```bash
GET /api/supplies/recommendations?integration_id=1&oos_risk=1&priority=A
```

### Создать поставку из рекомендаций

```bash
POST /api/supplies
{
  "integration_id": 1,
  "recommendation_ids": [1, 2, 3],
  "supply_method": "direct",
  "comment": "Срочная поставка"
}
```

### Забронировать слот

```bash
# 1. Создать черновик в Ozon
POST /api/supplies/123/create-draft

# 2. Получить доступные слоты
GET /api/supplies/123/timeslots

# 3. Забронировать лучший слот
POST /api/supplies/123/book-slot
{
  "timeslot_id": "slot_abc123"
}
```

### Обновить настройки

```bash
PUT /api/supplies/settings
{
  "integration_id": 1,
  "target_days_a": 28,
  "oos_risk_days": 2,
  "auto_book_slot": true
}
```

## Таблицы БД

| Таблица | Описание |
|---------|----------|
| `supplies` | Поставки |
| `supply_items` | Позиции поставки |
| `supply_events` | События (audit trail) |
| `supply_recommendations` | Рекомендации на поставку |
| `supply_settings` | Настройки по интеграции |
| `supply_analytics` | Агрегированная аналитика |
| `timeslots_cache` | Кэш слотов приёмки |

## Интеграция с Ozon API

Модуль использует методы из `SuppliesApi`:

- `createDirectDraft()` — прямая поставка
- `createCrossdockDraft()` — кросс-док
- `createMultiClusterDraft()` — мультикластер
- `getDraftInfo()` — информация о черновике
- `getDraftTimeslots()` — доступные слоты
- `createSupplyFromDraft()` — создание поставки
- `getSupplyCreateStatus()` — статус создания

## Уведомления

Настраиваемые уведомления:

| Событие | Параметр |
|---------|----------|
| Нет слотов на N дней | `notify_no_slots`, `notify_no_slots_days` |
| OOS риск по топ-SKU | `notify_oos_risk` |
| Поставка зависла > N часов | `notify_stuck_supply`, `notify_stuck_hours` |
| Ошибки API | `notify_api_errors` |
| Проблемы при приёмке | `notify_acceptance_issues` |

## Roadmap

- [ ] Аналитика: OOS rate, fill rate, forecast accuracy
- [ ] Уведомления в Telegram
- [ ] Автобронирование слотов при критическом OOS
- [ ] Генерация сопроводительных документов
- [ ] Интеграция с ЭДО
