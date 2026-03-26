# Отчет: Исправление отсутствующих цен и остатков Yandex Market

## Дата: 2026-03-25

## Описание проблемы

При синхронизации товаров Яндекс Маркет интеграции:
- ❌ **Цены отсутствовали** (`price = null`)
- ❌ **Остатки были нулевыми** (`stock = 0`)
- ❌ В интерфейсе отображались товары без цен и с нулевыми остатками

## Найденные проблемы

### Проблема 1: API не возвращает цены в offer-mappings

**Файл:** `app/Domains/YandexMarket/Api/ProductsApi.php`

**Суть проблемы:**
Yandex Market API endpoint `POST /v2/businesses/{businessId}/offer-mappings` **НЕ возвращает информацию о ценах**. 

Цены необходимо получать отдельно через endpoint:
```
GET /v2/campaigns/{campaignId}/offer-prices
```

**Документация Yandex:**
- https://yandex.ru/dev/market/partner-api/doc/ru/reference/assortment/offer-prices

### Проблема 2: Отсутствие обогащения данных

**Файл:** `app/Domains/YandexMarket/YandexMarketMarketplace.php`

**Суть проблемы:**
Метод `getProducts()` получал только базовую информацию о товарах из каталога, но не обогащал её:
- Ценами из `/offer-prices`
- Остатками из `/offers/stocks`

### Проблема 3: Неправильное поле цены

**Файл:** `app/Domains/YandexMarket/YandexMarketMarketplace.php`

**Суть проблемы:**
Yandex Market использует поле `basicPrice` для актуальной цены, а не `price`:

```php
// ❌ НЕПРАВИЛЬНО (старый код)
if (isset($offer['price']['value'])) {
    $price = (float) $offer['price']['value'];
}

// ✅ ПРАВИЛЬНО (Yandex текущий API)
if (isset($offer['basicPrice']['value'])) {
    $price = (float) $offer['basicPrice']['value'];
    $oldPrice = isset($offer['basicPrice']['discountBase']) 
        ? (float) $offer['basicPrice']['discountBase'] 
        : null;
}
```

## Архитектура решения

### Поток данных

```
YandexMarketMarketplace::getProducts()
  │
  ├─> ProductsApi::getProducts() [POST /v2/businesses/{businessId}/offer-mappings]
  │    └─> Возвращает: offerId, shopSku, name, barcodes, ... (БЕЗ ЦЕН)
  │
  ├─> getProductPricesWithPagination() [GET /v2/campaigns/{campaignId}/offer-prices]
  │    └─> Возвращает: offerId, basicPrice.value, basicPrice.discountBase
  │    └─> Обработка пагинации (все страницы)
  │
  ├─> getInventory() [POST /v2/campaigns/{campaignId}/offers/stocks]
  │    └─> Возвращает: offerId, quantity (остатки по складам)
  │    └─> Суммирование остатков по SKU
  │
  └─> Обогащение товаров:
       - price из basicPrice.value
       - old_price из basicPrice.discountBase
       - stock из суммы quantity по складам
```

## Внесённые изменения

### Файл: `app/Domains/YandexMarket/YandexMarketMarketplace.php`

**Изменения:**

1. **Метод `getProducts()`** теперь обогащает товары ценами и остатками:

```php
public function getProducts(): array
{
    // 1. Получаем базовые данные товаров
    $products = [];
    // ... пагинация через ProductsApi::getProducts()
    
    // 2. Получаем цены с пагинацией
    $prices = $this->getProductPricesWithPagination();
    if (! empty($prices)) {
        foreach ($products as &$product) {
            $sku = $product['sku'] ?? null;
            if ($sku && isset($prices[$sku])) {
                $product['price'] = $prices[$sku]['price'];
                $product['old_price'] = $prices[$sku]['old_price'];
            }
        }
        unset($product);
    }
    
    // 3. Получаем остатки и суммируем по SKU
    $inventory = $this->getInventory();
    if (! empty($inventory)) {
        $stocksBySku = [];
        foreach ($inventory as $item) {
            $sku = $item['sku'] ?? null;
            if ($sku) {
                $stocksBySku[$sku] += (int) ($item['quantity'] ?? 0);
            }
        }
        foreach ($products as &$product) {
            $sku = $product['sku'] ?? null;
            if ($sku && isset($stocksBySku[$sku])) {
                $product['stock'] = $stocksBySku[$sku];
            }
        }
        unset($product);
    }
    
    return $products;
}
```

2. **Добавлен метод `getProductPricesWithPagination()`**:

```php
private function getProductPricesWithPagination(): array
{
    $allPrices = [];
    $pageToken = null;
    
    do {
        $result = $this->products->getPricesWithPagination($pageToken);
        $items = $result['items'] ?? [];
        $pageToken = $result['paging']['nextPageToken'] ?? null;
        
        foreach ($items as $item) {
            $offerId = $item['offerId'] ?? $item['shopSku'] ?? null;
            
            // basicPrice — актуальное поле
            $price = isset($item['basicPrice']['value']) 
                ? (float) $item['basicPrice']['value'] 
                : null;
            $oldPrice = isset($item['basicPrice']['discountBase']) 
                ? (float) $item['basicPrice']['discountBase'] 
                : null;
            
            if ($price !== null) {
                $allPrices[$offerId] = [
                    'price' => $price,
                    'old_price' => $oldPrice,
                ];
            }
        }
    } while ($pageToken);
    
    return $allPrices;
}
```

3. **Метод `transformProduct()`** использует `basicPrice`:

```php
private function transformProduct(array $entry): array
{
    $offer = $entry['offer'] ?? [];
    
    // Получаем цену (basicPrice — актуальное поле, price — fallback)
    $price = null;
    $oldPrice = null;
    if (isset($offer['basicPrice']['value'])) {
        $price = (float) $offer['basicPrice']['value'];
        $oldPrice = isset($offer['basicPrice']['discountBase']) 
            ? (float) $offer['basicPrice']['discountBase'] 
            : null;
    } elseif (isset($offer['price']['value'])) {
        $price = (float) $offer['price']['value'];
    }
    
    // ...
}
```

### Файл: `app/Domains/YandexMarket/Api/ProductsApi.php`

**Добавлен метод `getPricesWithPagination()`**:

```php
public function getPricesWithPagination(?string $pageToken = null): array
{
    $params = ['limit' => 200];
    if ($pageToken) {
        $params['page_token'] = $pageToken;
    }
    
    $response = $this->client->get(
        '/v2/campaigns/{campaignId}/offer-prices',
        $params
    );
    
    if (! $response) {
        return ['items' => [], 'paging' => null];
    }
    
    return [
        'items' => $response['result']['offers'] ?? [],
        'paging' => $response['result']['paging'] ?? null,
    ];
}
```

## Тестирование

### 1. Запуск синхронизации

```bash
php artisan sync:products yandex_market
```

### 2. Проверка логов

Ожидаемые записи в логах:

```
[2026-03-25 12:00:00] INFO: Yandex Market products fetched {"count": 150}
[2026-03-25 12:00:05] INFO: YM enriching products with prices {"count": 150}
[2026-03-25 12:00:10] INFO: YM enriching products with stocks {"count": 150}
[2026-03-25 12:00:10] INFO: Products sync completed {"synced": 150, "created": 50, "updated": 100}
```

### 3. Проверка в БД

```sql
-- Проверить наличие цен и остатков
SELECT 
    sku, 
    name, 
    price, 
    old_price, 
    stock, 
    marketplace 
FROM products 
WHERE marketplace = 'yandex_market'
ORDER BY created_at DESC
LIMIT 20;

-- Статистика
SELECT 
    COUNT(*) as total_products,
    COUNT(CASE WHEN price > 0 THEN 1 END) as with_price,
    COUNT(CASE WHEN stock > 0 THEN 1 END) as with_stock,
    AVG(price) as avg_price,
    SUM(stock) as total_stock
FROM products
WHERE marketplace = 'yandex_market';
```

### 4. Проверка фронтенда

1. Откройте раздел "Товары" → Яндекс Маркет
2. Проверьте, что:
   - ✅ Отображаются цены (не null)
   - ✅ Отображаются остатки (> 0 для товаров в наличии)
   - ✅ Работает фильтрация по наличию

## Сравнение до/после

### ❌ ДО исправления:

```json
{
  "sku": "12345",
  "name": "Товар Яндекс",
  "price": null,
  "old_price": null,
  "stock": 0,
  "marketplace": "yandex_market"
}
```

### ✅ ПОСЛЕ исправления:

```json
{
  "sku": "12345",
  "name": "Товар Яндекс",
  "price": 1500.00,
  "old_price": 2000.00,
  "stock": 45,
  "marketplace": "yandex_market"
}
```

## Заключение

**Причины проблемы:**
1. Yandex Market API не возвращает цены в `offer-mappings` — нужен отдельный запрос к `offer-prices`
2. Новый класс `YandexMarketMarketplace` не обогащал товары ценами и остатками
3. Использовалось устаревшее поле `price` вместо `basicPrice`

**Решение:**
- Добавлено обогащение товаров ценами через `getProductPricesWithPagination()`
- Добавлено обогащение товаров остатками через `getInventory()`
- Обновлена логика работы с ценами (используется `basicPrice`)

**Ожидаемый результат:**
После деплоя товары Яндекс Маркет будут иметь актуальные цены и остатки.

## Примечания

### API Endpoints Yandex Market

| Endpoint | Метод | Описание |
|----------|-------|----------|
| `/v2/businesses/{businessId}/offer-mappings` | POST | Каталог товаров (БЕЗ цен) |
| `/v2/campaigns/{campaignId}/offer-prices` | GET | Цены товаров |
| `/v2/campaigns/{campaignId}/offers/stocks` | POST | Остатки по складам |

### Rate Limits

Yandex Market API ограничения:
- 100 товаров за запрос (offer-mappings)
- 200 цен за запрос (offer-prices)
- Пагинация через `page_token`

### Поля Yandex Market API

**basicPrice** (актуально):
- `value` — текущая цена
- `discountBase` — цена до скидки (для old_price)

**price** (устарело, fallback):
- `value` — цена
