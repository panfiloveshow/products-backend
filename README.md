# Products Inventory Backend

Backend API для управления товарами, остатками, поставками и юнит-экономикой маркетплейсов.

## Технологии

- **Laravel 12** - PHP Framework
- **SQLite/PostgreSQL** - База данных
- **Redis** - Кэширование и очереди (опционально)

## Модули

### 1. Товары (Products)
- CRUD операции с товарами
- Синхронизация с маркетплейсами (WB, Ozon, Яндекс.Маркет)
- Фильтрация и поиск

### 2. Остатки (Inventory)
- Остатки по складам маркетплейсов
- История изменений
- ML-прогнозы спроса
- Алерты и рекомендации

### 3. Поставки (Shipments)
- Создание и управление поставками
- Workflow согласования
- Бронирование слотов
- ML-рекомендации по составу

### 4. Юнит-экономика (Unit Economics)
- Расчет маржинальности по маркетплейсам
- Калькулятор комиссий и затрат
- Сравнение между маркетплейсами

## Установка

```bash
# Клонировать репозиторий
cd products-backend

# Установить зависимости
composer install

# Скопировать .env
cp .env.example .env

# Сгенерировать ключ
php artisan key:generate

# Запустить миграции
php artisan migrate

# Запустить сервер
php artisan serve
```

## Настройка маркетплейсов

Добавьте API ключи в `.env`:

```env
WILDBERRIES_API_KEY=your_wb_api_key
OZON_CLIENT_ID=your_ozon_client_id
OZON_API_KEY=your_ozon_api_key
YANDEX_MARKET_TOKEN=your_ym_token
YANDEX_MARKET_CAMPAIGN_ID=your_campaign_id
```

## API Endpoints

### Products
| Method | Endpoint | Описание |
|--------|----------|----------|
| GET | `/api/products` | Список товаров |
| GET | `/api/products/{id}` | Детали товара |
| POST | `/api/products` | Создать товар |
| PUT | `/api/products/{id}` | Обновить товар |
| DELETE | `/api/products/{id}` | Удалить товар |
| POST | `/api/products/sync/{marketplace}` | Синхронизация |

### Inventory
| Method | Endpoint | Описание |
|--------|----------|----------|
| GET | `/api/inventory` | Список остатков |
| GET | `/api/inventory/{sku}` | Остатки по SKU |
| GET | `/api/inventory/{sku}/history` | История |
| GET | `/api/inventory/{sku}/forecast` | Прогноз |
| GET | `/api/inventory/alerts` | Алерты |
| GET | `/api/inventory/recommendations` | AI-рекомендации |

### Shipments
| Method | Endpoint | Описание |
|--------|----------|----------|
| GET | `/api/shipments` | Список поставок |
| POST | `/api/shipments` | Создать поставку |
| POST | `/api/shipments/{id}/items` | Добавить товар |
| POST | `/api/shipments/{id}/submit` | На согласование |
| POST | `/api/shipments/{id}/approve` | Согласовать |
| POST | `/api/shipments/{id}/send` | Отправить |

### Unit Economics
| Method | Endpoint | Описание |
|--------|----------|----------|
| GET | `/api/unit-economics` | Список |
| GET | `/api/unit-economics/{marketplace}` | По маркетплейсу |
| POST | `/api/unit-economics/calculate/{marketplace}` | Калькулятор |
| GET | `/api/unit-economics/comparison` | Сравнение МП |

## Фоновые задачи

| Job | Расписание | Описание |
|-----|------------|----------|
| `sync_products_all` | каждые 6 часов | Синхронизация товаров |
| `sync_inventory_all` | каждые 2 часа | Синхронизация остатков |
| `calculate_forecasts` | 03:00 | Расчет прогнозов |
| `generate_alerts` | каждые 2 часа | Генерация алертов |
| `generate_shipment_recommendations` | 06:00 | ML-рекомендации |
| `calculate_unit_economics` | 04:00 | Пересчет юнит-экономики |

Запуск планировщика:
```bash
php artisan schedule:work
```

Запуск очередей:
```bash
php artisan queue:work
```

## Структура проекта

```
app/
├── Http/
│   ├── Controllers/Api/
│   │   ├── ProductController.php
│   │   ├── InventoryController.php
│   │   ├── ShipmentController.php
│   │   ├── UnitEconomicsController.php
│   │   └── SupplierController.php
│   └── Requests/
│       ├── Product/
│       ├── Inventory/
│       ├── Shipment/
│       └── UnitEconomics/
├── Models/
│   ├── Product.php
│   ├── InventoryWarehouse.php
│   ├── InventoryHistory.php
│   ├── InventoryAlert.php
│   ├── Shipment.php
│   ├── ShipmentItem.php
│   ├── ShipmentRecommendation.php
│   ├── Supplier.php
│   ├── UnitEconomics.php
│   └── SyncLog.php
├── Services/
│   ├── ProductService.php
│   ├── InventoryService.php
│   ├── ShipmentService.php
│   ├── UnitEconomicsService.php
│   └── Marketplace/
│       ├── MarketplaceInterface.php
│       ├── MarketplaceFactory.php
│       ├── WildberriesService.php
│       ├── OzonService.php
│       └── YandexMarketService.php
└── Jobs/
    ├── SyncProductsJob.php
    ├── SyncInventoryJob.php
    ├── CalculateForecastsJob.php
    ├── GenerateAlertsJob.php
    ├── GenerateShipmentRecommendationsJob.php
    └── CalculateUnitEconomicsJob.php
```

## License

MIT
