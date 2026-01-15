# Модуль "Поставки" (Supplies)

## Обзор

Модуль управления поставками товаров на маркетплейсы Wildberries и Ozon. Включает:
- Автоматические рекомендации по пополнению запасов
- Расчёт оптимального количества заказа
- Оптимизация логистики (выбор транспорта, склада)
- Синхронизация слотов приёмки с маркетплейсами
- Планирование поставок на период

---

## Структура файлов

```
app/
├── Domains/
│   ├── Marketplace/Contracts/
│   │   └── SuppliesApiInterface.php       # Интерфейс для API поставок
│   ├── Wildberries/Api/
│   │   └── SuppliesApi.php                # WB FBW Supplies API
│   ├── Ozon/Api/
│   │   └── SuppliesApi.php                # Ozon FBO Supplies API
│   └── Supplies/
│       ├── DTO/
│       │   ├── SupplyCalculationInput.php
│       │   └── SupplyCalculationResult.php
│       └── Services/
│           ├── SupplyCalculationService.php    # Расчёт оптимального количества
│           ├── SupplyOptimizationService.php   # Оптимизация логистики
│           └── SupplyRecommendationService.php # Генерация рекомендаций
├── Models/
│   ├── SupplyPlan.php                     # План поставок
│   ├── SupplyRecommendation.php           # Рекомендация по поставке
│   └── WarehouseSlot.php                  # Слот приёмки
├── Http/Controllers/Api/
│   ├── SupplyPlanController.php
│   ├── SupplyRecommendationController.php
│   └── WarehouseSlotController.php
└── Jobs/
    └── GenerateSupplyRecommendationsJob.php
```

---

## API Endpoints

### Supply Plans (Планы поставок)

```
GET    /api/supply-plans                    # Список планов
POST   /api/supply-plans                    # Создать план
GET    /api/supply-plans/{id}               # Детали плана
PUT    /api/supply-plans/{id}               # Обновить план
DELETE /api/supply-plans/{id}               # Удалить план
GET    /api/supply-plans/{id}/calculate     # Рассчитать оптимальный состав
POST   /api/supply-plans/{id}/approve       # Утвердить план
POST   /api/supply-plans/{id}/cancel        # Отменить план
```

### Supply Recommendations (Рекомендации)

```
GET    /api/supply-recommendations          # Список рекомендаций
GET    /api/supply-recommendations/stats    # Статистика
GET    /api/supply-recommendations/by-warehouse  # По складам
POST   /api/supply-recommendations/generate # Сгенерировать рекомендации
GET    /api/supply-recommendations/{id}     # Детали рекомендации
POST   /api/supply-recommendations/{id}/apply   # Применить (создать поставку)
POST   /api/supply-recommendations/{id}/dismiss # Отклонить
```

### Warehouse Slots (Слоты приёмки)

```
GET    /api/warehouse-slots                 # Список слотов
GET    /api/warehouse-slots/warehouses      # Список складов
POST   /api/warehouse-slots/sync            # Синхронизировать с МП
POST   /api/warehouse-slots/{id}/book       # Забронировать слот
POST   /api/warehouse-slots/{id}/release    # Освободить слот
```

---

## Формула расчёта оптимального заказа

```
OptimalQuantity = (AvgDailySales × TargetDays) + SafetyStock - CurrentStock - InTransit

Где:
- AvgDailySales = средние продажи в день (из InventoryWarehouse.average_daily_sales)
- TargetDays = целевой запас в днях (default 30)
- SafetyStock = страховой запас = AvgDailySales × SafetyDays (default 7 дней)
- CurrentStock = текущий остаток на складе
- InTransit = товары в пути
```

### Точка заказа (Reorder Point)

```
ReorderPoint = (AvgDailySales × LeadTimeDays) + SafetyStock

Где:
- LeadTimeDays = время доставки до склада МП (default 5 дней)
```

---

## Приоритеты рекомендаций

| Приоритет | Условие | Действие |
|-----------|---------|----------|
| 🔴 `urgent` | `days_of_stock <= 7` | Срочная поставка |
| 🟡 `high` | `days_of_stock <= 14` | Рекомендуемая поставка |
| 🟢 `medium` | `days_of_stock <= 21` | Плановая поставка |
| ⚪ `low` | `days_of_stock > 21` | Можно отложить |

---

## Типы транспорта

| Тип | Объём (м³) | Грузоподъёмность (кг) | Базовая стоимость |
|-----|------------|----------------------|-------------------|
| Газель | 9 | 1500 | 3000 ₽ |
| Газель-Long | 16 | 1500 | 4000 ₽ |
| Бычок | 22 | 3000 | 5500 ₽ |
| Фура 5т | 36 | 5000 | 8000 ₽ |
| Фура 10т | 54 | 10000 | 12000 ₽ |
| Фура 20т | 82 | 20000 | 18000 ₽ |

Оптимальный транспорт выбирается автоматически с загрузкой >= 70%.

---

## Интеграция с маркетплейсами

### Wildberries (FBW Supplies API)

**Поддерживаемые функции:**
- ✅ Получение списка поставок
- ✅ Детали поставки
- ✅ Товары в поставке
- ✅ Список складов
- ✅ Слоты приёмки
- ✅ Коэффициенты приёмки (КС)
- ✅ Тарифы транзита
- ❌ Создание поставки (только через ЛК WB)
- ❌ Бронирование слота (только через ЛК WB)

### Ozon (FBO Supplies API)

**Поддерживаемые функции:**
- ✅ Получение списка поставок
- ✅ Детали поставки
- ✅ Товары в поставке
- ✅ Список складов
- ✅ Слоты приёмки
- ✅ Создание черновика поставки
- ✅ Добавление товаров
- ✅ Бронирование слота
- ✅ Создание грузомест
- ✅ Назначение водителя

---

## Примеры использования

### Генерация рекомендаций

```php
use App\Domains\Supplies\Services\SupplyRecommendationService;

$service = app(SupplyRecommendationService::class);
$recommendations = $service->generateForIntegration($integration);

foreach ($recommendations as $rec) {
    echo "{$rec->title} - {$rec->priority}\n";
    echo "Товаров: {$rec->total_items}, Сумма: {$rec->total_cost} ₽\n";
}
```

### Расчёт оптимального количества

```php
use App\Domains\Supplies\Services\SupplyCalculationService;
use App\Domains\Supplies\DTO\SupplyCalculationInput;

$service = app(SupplyCalculationService::class);

$input = new SupplyCalculationInput(
    sku: 'SKU-001',
    currentStock: 50,
    avgDailySales: 5.0,
    targetDaysOfStock: 30,
    safetyStockDays: 7,
    leadTimeDays: 5,
    inTransit: 0,
    costPrice: 1000,
);

$result = $service->calculate($input);

echo "Оптимальный заказ: {$result->optimalQuantity} шт.\n";
echo "Приоритет: {$result->priority}\n";
echo "Стоимость: {$result->totalCost} ₽\n";
```

### Подбор транспорта

```php
use App\Domains\Supplies\Services\SupplyOptimizationService;

$service = app(SupplyOptimizationService::class);

$trucks = $service->selectOptimalTruck(
    totalVolume: 15.5,  // м³
    totalWeight: 1200   // кг
);

$optimal = collect($trucks)->firstWhere('is_optimal', true);
echo "Рекомендуемый транспорт: {$optimal['name']}\n";
echo "Загрузка: {$optimal['utilization']}%\n";
```

---

## Миграции

```bash
php artisan migrate
```

Новые таблицы:
- `supply_plans` — планы поставок
- `supply_recommendations` — рекомендации
- `warehouse_slots` — слоты приёмки

Изменения в существующих таблицах:
- `inventory_warehouses` — добавлены поля для расчёта поставок
- `shipments` — добавлена связь с планом поставок

---

## Автоматическая генерация рекомендаций

Job `GenerateSupplyRecommendationsJob` можно запускать:

1. **Вручную:**
```php
GenerateSupplyRecommendationsJob::dispatch($integrationId);
```

2. **По расписанию (ежедневно):**
```php
// app/Console/Kernel.php
$schedule->job(new GenerateSupplyRecommendationsJob())
    ->dailyAt('06:00')
    ->name('generate-supply-recommendations');
```

---

## Статусы поставок

### Внутренние статусы (Shipment)

| Статус | Описание |
|--------|----------|
| `draft` | Черновик |
| `pending_logistics` | На согласовании логистов |
| `approved` | Одобрена |
| `sent` | Отправлена |
| `in_transit` | В пути |
| `delivered` | Доставлена |
| `rejected` | Отклонена |

### Статусы WB

| Код | Статус |
|-----|--------|
| 1 | Не запланирована |
| 2 | Запланирована |
| 3 | Разрешена разгрузка |
| 4 | Идёт приёмка |
| 5 | Принята |
| 6 | Разгружена у ворот |

### Статусы Ozon

| Статус | Описание |
|--------|----------|
| `DRAFT` | Черновик |
| `AWAITING_CONFIRMATION` | Ожидает подтверждения |
| `CONFIRMED` | Подтверждена |
| `IN_TRANSIT` | В пути |
| `AT_WAREHOUSE` | На складе |
| `ACCEPTING` | Идёт приёмка |
| `ACCEPTED` | Принята |
| `PARTIALLY_ACCEPTED` | Частично принята |
| `CANCELLED` | Отменена |
