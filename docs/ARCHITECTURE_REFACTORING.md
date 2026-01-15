# Рефакторинг архитектуры проекта

## ✅ СТАТУС: ВЫПОЛНЕНО (Этап 1-4)

**Дата:** 23 декабря 2024

---

## Бывшие проблемы (решены)

| Файл | Размер | Проблема |
|------|--------|----------|
| `OzonService.php` | 137 KB | Монолит: API, расчёты, тарифы, синхронизация |
| `UnitEconomicsService.php` | 82 KB | Всё в одном: WB + Ozon + Yandex |
| `WildberriesService.php` | 61 KB | Монолит |
| `YandexMarketService.php` | 40 KB | Монолит |

**Итого:** Нет понимания где какой маркетплейс, сложно поддерживать.

---

## ✅ Реализованная структура

```
app/Domains/
│
├── Marketplace/                      # Базовые контракты
│   └── Contracts/
│       ├── MarketplaceInterface.php
│       ├── ProductsApiInterface.php
│       ├── InventoryApiInterface.php
│       ├── TariffsProviderInterface.php
│       └── CommissionsProviderInterface.php
│
├── Wildberries/                      # ВСЁ про Wildberries
│   ├── Api/
│   │   ├── WildberriesClient.php     # HTTP клиент
│   │   ├── ProductsApi.php           # Товары
│   │   └── InventoryApi.php          # Остатки
│   ├── Tariffs/
│   │   ├── WildberriesTariffs.php    # Тарифы логистики
│   │   └── CommissionCalculator.php  # Комиссии по категориям
│   └── UnitEconomics/
│       └── WildberriesUnitEconomicsCalculator.php
│
├── Ozon/                             # ВСЁ про Ozon
│   ├── Api/
│   │   ├── OzonClient.php
│   │   ├── ProductsApi.php
│   │   └── InventoryApi.php
│   ├── Tariffs/
│   │   ├── OzonTariffs.php           # FBO, FBS, RFBS, EXPRESS
│   │   └── CommissionCalculator.php
│   └── UnitEconomics/
│       └── OzonUnitEconomicsCalculator.php
│
└── UnitEconomics/                    # Общая логика
    ├── Contracts/
    │   └── UnitEconomicsCalculatorInterface.php
    ├── DTO/
    │   ├── CalculationInput.php      # Входные данные
    │   ├── CostBreakdown.php         # Разбивка расходов
    │   └── UnitEconomicsResult.php   # Результат расчёта
    └── UnitEconomicsOrchestrator.php # Выбор калькулятора
```

---

## Архивная информация: Предлагаемая структура (план)

```
app/
├── Domains/                          # Доменные модули
│   │
│   ├── Marketplace/                  # Базовые абстракции маркетплейсов
│   │   ├── Contracts/
│   │   │   ├── MarketplaceInterface.php
│   │   │   ├── TariffsProviderInterface.php
│   │   │   └── CommissionsProviderInterface.php
│   │   └── Factory/
│   │       └── MarketplaceFactory.php
│   │
│   ├── Wildberries/                  # ВСЁ про Wildberries
│   │   ├── Api/
│   │   │   ├── WildberriesClient.php         # HTTP клиент
│   │   │   ├── ProductsApi.php               # /content/v2/get/cards/list
│   │   │   ├── InventoryApi.php              # /api/v3/stocks
│   │   │   ├── PricesApi.php                 # /public/api/v1/info
│   │   │   └── AnalyticsApi.php              # /api/v1/analytics
│   │   ├── Tariffs/
│   │   │   ├── WildberriesTariffs.php        # Тарифы логистики
│   │   │   ├── BoxTariffCalculator.php       # Расчёт коробов
│   │   │   └── PalletTariffCalculator.php    # Расчёт паллет
│   │   ├── UnitEconomics/
│   │   │   ├── WildberriesUnitEconomicsCalculator.php
│   │   │   ├── FboCalculator.php             # Расчёт FBO
│   │   │   ├── FbsCalculator.php             # Расчёт FBS
│   │   │   └── CommissionCalculator.php      # Комиссии по категориям
│   │   ├── Sync/
│   │   │   ├── SyncProductsHandler.php
│   │   │   ├── SyncInventoryHandler.php
│   │   │   └── SyncSalesHandler.php
│   │   ├── DTO/
│   │   │   ├── WildberriesProduct.php
│   │   │   ├── WildberriesStock.php
│   │   │   └── WildberriesUnitEconomicsResult.php
│   │   └── WildberriesService.php            # Фасад (делегирует в подсервисы)
│   │
│   ├── Ozon/                         # ВСЁ про Ozon
│   │   ├── Api/
│   │   │   ├── OzonClient.php
│   │   │   ├── ProductsApi.php
│   │   │   ├── InventoryApi.php
│   │   │   ├── PricesApi.php
│   │   │   └── AnalyticsApi.php
│   │   ├── Tariffs/
│   │   │   ├── OzonTariffs.php
│   │   │   ├── FboTariffCalculator.php
│   │   │   ├── FbsTariffCalculator.php
│   │   │   ├── RfbsTariffCalculator.php      # realFBS Standard
│   │   │   └── ExpressTariffCalculator.php   # realFBS Express
│   │   ├── UnitEconomics/
│   │   │   ├── OzonUnitEconomicsCalculator.php
│   │   │   ├── FboCalculator.php
│   │   │   ├── FbsCalculator.php
│   │   │   ├── RfbsCalculator.php
│   │   │   └── ExpressCalculator.php
│   │   ├── Sync/
│   │   │   ├── SyncProductsHandler.php
│   │   │   ├── SyncInventoryHandler.php
│   │   │   └── SyncSalesHandler.php
│   │   ├── DTO/
│   │   │   ├── OzonProduct.php
│   │   │   ├── OzonStock.php
│   │   │   └── OzonUnitEconomicsResult.php
│   │   └── OzonService.php                   # Фасад
│   │
│   ├── YandexMarket/                 # ВСЁ про Yandex Market
│   │   ├── Api/
│   │   ├── Tariffs/
│   │   ├── UnitEconomics/
│   │   ├── Sync/
│   │   ├── DTO/
│   │   └── YandexMarketService.php
│   │
│   └── UnitEconomics/                # Общая логика юнит-экономики
│       ├── Contracts/
│       │   ├── UnitEconomicsCalculatorInterface.php
│       │   └── TariffCalculatorInterface.php
│       ├── Cache/
│       │   ├── UnitEconomicsCacheService.php
│       │   └── CacheWarmer.php
│       ├── DTO/
│       │   ├── UnitEconomicsResult.php       # Универсальный результат
│       │   ├── CostBreakdown.php             # Разбивка расходов
│       │   └── ProfitMetrics.php             # Метрики прибыли
│       └── UnitEconomicsOrchestrator.php     # Оркестратор (выбирает калькулятор)
│
├── Http/
│   └── Controllers/
│       └── Api/
│           ├── UnitEconomics/
│           │   ├── UnitEconomicsController.php      # Основной
│           │   ├── TariffsController.php            # Справочники тарифов
│           │   └── ComparisonController.php         # Сравнение схем
│           ├── Wildberries/
│           │   └── WildberriesController.php
│           ├── Ozon/
│           │   └── OzonController.php
│           └── YandexMarket/
│               └── YandexMarketController.php
│
├── Jobs/
│   ├── Sync/
│   │   ├── SyncProductsJob.php               # Диспетчер
│   │   ├── SyncInventoryJob.php
│   │   └── SyncSalesJob.php
│   └── UnitEconomics/
│       ├── RecalculateUnitEconomicsJob.php
│       └── WarmUnitEconomicsCacheJob.php
│
└── Models/
    ├── Integration.php
    ├── Product.php
    └── UnitEconomics/
        ├── UnitEconomicsCache.php
        └── UnitEconomicsSettings.php
```

---

## Принципы новой архитектуры

### 1. Domain-Driven Design
Каждый маркетплейс — отдельный домен со своей логикой.

### 2. Single Responsibility
- `Api/` — только HTTP запросы к API маркетплейса
- `Tariffs/` — только расчёт тарифов
- `UnitEconomics/` — только расчёт экономики
- `Sync/` — только синхронизация данных

### 3. Dependency Inversion
Контракты в `Contracts/`, реализации по маркетплейсам.

### 4. Facade Pattern
`WildberriesService`, `OzonService` — фасады, делегирующие в подсервисы.

---

## План рефакторинга (этапы)

### Этап 1: Структура папок (1 день)
- [ ] Создать структуру `app/Domains/`
- [ ] Создать базовые контракты
- [ ] Не ломать текущий код

### Этап 2: Wildberries (2-3 дня)
- [ ] Вынести API методы в `Wildberries/Api/`
- [ ] Вынести тарифы в `Wildberries/Tariffs/`
- [ ] Вынести расчёт юнит-экономики в `Wildberries/UnitEconomics/`
- [ ] Создать DTO
- [ ] `WildberriesService.php` → фасад

### Этап 3: Ozon (3-4 дня)
- [ ] Аналогично Wildberries
- [ ] Учесть 4 схемы: FBO, FBS, RFBS, EXPRESS

### Этап 4: Yandex Market (1-2 дня)
- [ ] Аналогично

### Этап 5: Общий UnitEconomics (1 день)
- [ ] Оркестратор
- [ ] Универсальные DTO
- [ ] Кэш сервис

### Этап 6: Контроллеры и роуты (1 день)
- [ ] Разбить контроллеры
- [ ] Обновить роуты

---

## Пример использования после рефакторинга

```php
// До:
$service = new UnitEconomicsService();
$result = $service->calculateForOzon($product, 'FBO'); // 82KB файл, всё в куче

// После:
$calculator = app(OzonUnitEconomicsCalculator::class);
$result = $calculator->calculate($product, FulfillmentType::FBO);

// Или через оркестратор:
$orchestrator = app(UnitEconomicsOrchestrator::class);
$result = $orchestrator->calculate('ozon', $product, 'FBO');
```

---

## Преимущества

| До | После |
|----|-------|
| 1 файл 137KB (Ozon) | 15-20 файлов по 2-10KB |
| Непонятно где что | Чёткая структура по папкам |
| Сложно тестировать | Легко тестировать каждый класс |
| Сложно добавить маркетплейс | Копируем структуру папки |
| Риск сломать всё | Изолированные изменения |

---

## Готовы начать?

Рекомендую начать с **Этапа 1** — создать структуру папок и базовые контракты, не ломая текущий код. Затем постепенно выносить логику.
