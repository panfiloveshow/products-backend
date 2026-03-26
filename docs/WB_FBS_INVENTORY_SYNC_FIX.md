# Отчет: Проблема синхронизации остатков Wildberries FBS

## Дата: 2026-03-25

## Описание проблемы

При синхронизации товаров Wildberries интеграций не выгружаются актуальные остатки, особенно для FBS складов (склады продавца).

## Найденные проблемы

### Проблема 1: Отсутствует метод `getFbsStocks()`

**Файл:** `app/Domains/Wildberries/WildberriesMarketplace.php`

**Суть проблемы:**
В `SyncInventoryJob.php` (строка 85-91) есть проверка:
```php
if ($this->syncLog->marketplace === 'wildberries' && method_exists($marketplaceService, 'getFbsStocks')) {
    $fbsStocks = $marketplaceService->getFbsStocks();
    // ...
}
```

Метод `getFbsStocks()` **НЕ СУЩЕСТВОВАЛ** в классе `WildberriesMarketplace`, поэтому проверка `method_exists()` возвращала `false`, и FBS остатки никогда не синхронизировались.

**Решение:**
Добавлен метод `getFbsStocks()` в `WildberriesMarketplace`:

```php
public function getFbsStocks(): array
{
    return $this->inventory->getStocksFromFbsWarehousesDirect($this->getIntegration());
}
```

### Проблема 2: Нет публичного доступа к FBS остаткам

**Файл:** `app/Domains/Wildberries/Api/InventoryApi.php`

**Суть проблемы:**
Метод `getStocksFromFbsWarehouses()` был приватным (`private`), поэтому невозможно было получить FBS остатки напрямую.

**Решение:**
Добавлен публичный метод-обёртка:

```php
public function getStocksFromFbsWarehousesDirect(?Integration $integration = null): array
{
    return $this->getStocksFromFbsWarehouses($integration, []);
}
```

### Проблема 3: Зависимость от chrtIds

**Файл:** `app/Domains/Wildberries/Api/InventoryApi.php`

**Суть проблемы:**
Метод `getStocksFromFbsWarehouses()` требует `chrtIds` (ID размеров товаров). Если:
- Интеграция не передана
- Товары не загружены в БД
- `getAllChrtIds()` возвращает пустой массив

Тогда FBS остатки не загружаются.

**Логика работы:**
1. `getStocks()` сначала пытается получить FBO остатки через Analytics API
2. Если ничего не найдено → пытается legacy Statistics API  
3. Затем вызывает `getStocksFromFbsWarehouses()` для FBS
4. **НО** если нет `chrtIds` → FBS пропускается

## Архитектура синхронизации остатков WB

### WildberriesMarketplace::getInventory()

```
WildberriesMarketplace::getInventory()
  └─> InventoryApi::getStocks($integration)
       ├─> getWbWarehousesStocksReport() [FBO склады WB]
       │    └─> Analytics API: /api/analytics/v1/stocks-report/wb-warehouses
       │
       ├─> getStocksReport() [Legacy fallback]
       │    └─> Statistics API: /api/v1/supplier/stocks
       │
       └─> getStocksFromFbsWarehouses() [FBS склады продавца]
            └─> Marketplace API: /api/v3/warehouses + /api/v3/stocks/{warehouseId}
```

### SyncInventoryJob для Wildberries

```php
// 1. Получаем все остатки (FBO + FBS)
$inventory = $marketplaceService->getInventory();

// 2. Дополнительно пытаемся получить FBS (если метод существует)
if (method_exists($marketplaceService, 'getFbsStocks')) {
    $fbsStocks = $marketplaceService->getFbsStocks();
    $inventory = array_merge($inventory, $fbsStocks);
}
```

## Внесённые изменения

### Файл: `app/Domains/Wildberries/WildberriesMarketplace.php`

**Добавлено:**
```php
/**
 * Получить остатки только с FBS складов продавца
 * Используется в SyncInventoryJob для явной синхронизации FBS
 */
public function getFbsStocks(): array
{
    return $this->inventory->getStocksFromFbsWarehousesDirect($this->getIntegration());
}
```

### Файл: `app/Domains/Wildberries/Api/InventoryApi.php`

**Добавлено:**
```php
/**
 * Публичный метод для получения FBS остатков (используется в WildberriesMarketplace::getFbsStocks())
 * Возвращает только FBS склады продавца
 */
public function getStocksFromFbsWarehousesDirect(?Integration $integration = null): array
{
    return $this->getStocksFromFbsWarehouses($integration, []);
}
```

## Диагностика

### Проверка синхронизации

```bash
# Проверить последнюю синхронизацию
php artisan sync:status

# Посмотреть логи синхронизации
tail -f storage/logs/laravel.log | grep -i "WB.*inventory\|wildberries.*stocks"

# Проверить наличие FBS складов в БД
SELECT 
    marketplace, 
    fulfillment_type, 
    COUNT(*) as count, 
    SUM(quantity) as total_qty 
FROM inventory_warehouses 
WHERE marketplace = 'wildberries'
GROUP BY marketplace, fulfillment_type;
```

### Ожидаемые логи после исправления

```
[2026-03-25 10:00:00] INFO: WB InventoryApi: Got FBO stocks from WB warehouses report {"count": 150}
[2026-03-25 10:00:05] INFO: WB InventoryApi: Collected chrtIds {"count": 300}
[2026-03-25 10:00:10] INFO: WB InventoryApi: Combined FBO+FBS stocks {"total_skus": 200, "fbs_skus": 50}
[2026-03-25 10:00:10] INFO: WB FBS stocks merged {"fbs_count": 50}
```

## Рекомендации

### 1. Убедитесь, что интеграция настроена корректно

```php
// Проверьте в БД
SELECT id, name, marketplace, is_active, credentials 
FROM integrations 
WHERE marketplace = 'wildberries';
```

### 2. Проверьте наличие товаров в БД

FBS синхронизация требует наличия товаров для получения `chrtIds`:

```sql
SELECT COUNT(*) 
FROM products 
WHERE marketplace = 'wildberries' 
  AND integration_id = YOUR_INTEGRATION_ID;
```

### 3. Запустите полную синхронизацию

```bash
# Сначала синхронизируйте товары
php artisan sync:products wildberries

# Затем остатки
php artisan sync:inventory wildberries
```

### 4. Мониторинг

Добавьте мониторинг логов:
- `WB InventoryApi: No chrtIds found from products` — нет товаров в БД
- `WB InventoryApi: No warehouses found` — нет FBS складов
- `WB FBS stocks merged` — FBS успешно синхронизирован

## Тестирование

### Unit тесты

```bash
php artisan test --filter=InventoryServiceTest
php artisan test --filter=SyncProductsJobTest
```

### Ручное тестирование

1. Создайте тестовую интеграцию WB с FBS складами
2. Запустите синхронизацию товаров
3. Запустите синхронизацию остатков
4. Проверьте в БД наличие записей с `fulfillment_type = 'FBS'`

```sql
SELECT * 
FROM inventory_warehouses 
WHERE marketplace = 'wildberries' 
  AND fulfillment_type = 'FBS'
LIMIT 10;
```

## Заключение

**Причина проблемы:** Отсутствие метода `getFbsStocks()` в `WildberriesMarketplace` приводило к тому, что FBS остатки не синхронизировались явно, даже если они присутствовали в общем ответе `getInventory()`.

**Решение:** Добавлен публичный метод `getFbsStocks()` и вспомогательный метод `getStocksFromFbsWarehousesDirect()` для явной синхронизации FBS остатков.

**Ожидаемый результат:** После деплоя исправлений FBS остатки будут корректно синхронизироваться для всех Wildberries интеграций.
