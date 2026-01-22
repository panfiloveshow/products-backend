# Модуль «Поставки Ozon FBO»

## Обзор

Модуль автоматизирует управление поставками на склады Ozon FBO:
- Автоматический расчёт рекомендаций на поставку
- Создание и управление поставками
- Интеграция с Ozon API (черновики, слоты, статусы)
- Мониторинг жизненного цикла поставки
- Аналитика и уведомления

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

### Рекомендации

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET | `/api/supplies/recommendations` | Список рекомендаций |
| POST | `/api/supplies/recommendations/calculate` | Рассчитать рекомендации |
| POST | `/api/supplies/recommendations/{id}/accept` | Принять рекомендацию |
| POST | `/api/supplies/recommendations/{id}/reject` | Отклонить рекомендацию |
| POST | `/api/supplies/recommendations/{id}/postpone` | Отложить рекомендацию |

### Поставки

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET | `/api/supplies` | Список поставок |
| POST | `/api/supplies` | Создать поставку из рекомендаций |
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
