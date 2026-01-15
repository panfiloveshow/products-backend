# Исправление: Цены товаров в акциях Ozon

## Проблема

Товары, участвующие в акциях на Ozon, отображались с ценой продавца (`price`), а не с акционной ценой (`marketing_seller_price`). Например, товар A39 магазина "Свежее поле" показывал цену 545₽, хотя фактическая цена с учётом акции была другой.

## Причина

Ozon API возвращает несколько типов цен:
- `price` — базовая цена продавца (без акций)
- `marketing_seller_price` — цена с учётом маркетинговых акций
- `old_price` — старая/зачёркнутая цена

Ранее код использовал только `price`, не учитывая `marketing_seller_price`.

**Важно:** Ozon отключил поле `marketing_price` с 12.11.2025, но `marketing_seller_price` всё ещё доступно.

## Решение

### 1. Обновлён метод `enrichWithCommissions()` в `OzonService.php`

Теперь метод:
- Извлекает `marketing_seller_price` из `/v4/product/info/prices`
- Определяет, участвует ли товар в акции (`is_in_promotion`)
- Рассчитывает скидку в процентахpromotion_discount`)
- Обновляет `$product['price']` на актуальную цену с учётом акций
- Сохраняет информацию об активных акциях

```php
// Определяем актуальную цену для расчётов
$actualPrice = $sellerPrice;
$isInPromotion = false;

if ($marketingSellerPrice > 0 && $marketingSellerPrice < $sellerPrice) {
    $actualPrice = $marketingSellerPrice;
    $isInPromotion = true;
    $promotionDiscount = round((1 - $marketingSellerPrice / $sellerPrice) * 100, 1);
}
```

### 2. Добавлен вызов `enrichWithCommissions()` в `getProducts()`

```php
// Получаем комиссии и акционные цены (marketing_seller_price)
// ВАЖНО: Этот метод обновляет price на актуальную цену с учётом акций
$products = $this->enrichWithCommissions($products);
```

### 3. Обновлён `SyncUnitEconomicsCommand.php`

Добавлена передача информации об акциях в `buildCalculationData()` и `extractDetailedData()`.

### 4. Создана миграция для новых полей

```
2025_12_18_110000_add_promotion_fields_to_unit_economics.php
```

Новые поля в таблице `unit_economics`:
- `is_in_promotion` (boolean) — товар в акции
- `promotion_discount` (decimal) — скидка в %
- `seller_price` (decimal) — цена без акции
- `marketing_seller_price` (decimal) — цена с акцией

### 5. Обновлена модель `UnitEconomics.php`

Добавлены новые поля в `$fillable` и `$casts`.

## Как работает

1. При синхронизации товаров (`SyncProductsJob`) вызывается `OzonService::getProducts()`
2. `getProducts()` вызывает `enrichWithCommissions()` который:
   - Запрашивает `/v4/product/info/prices`
   - Извлекает `marketing_seller_price` и `marketing_actions`
   - Если `marketing_seller_price < price` — товар в акции
   - Обновляет `$product['price']` на `marketing_seller_price`
3. Товар сохраняется в БД с актуальной ценой
4. При расчёте юнит-экономики используется актуальная цена

## Данные об акциях в API

Пример ответа `/v4/product/info/prices`:

```json
{
  "price": {
    "price": 545,
    "marketing_seller_price": 490,
    "old_price": 600,
    "min_price": 400
  },
  "marketing_actions": {
    "actions": [
      {
        "title": "Скидка 10%",
        "date_from": "2025-12-01T00:00:00Z",
        "date_to": "2025-12-31T23:59:59Z"
      }
    ],
    "ozon_actions_exist": true
  }
}
```

## Тестирование

После применения изменений:

1. Запустите миграцию:
```bash
php artisan migrate
```

2. Пересинхронизируйте товары:
```bash
php artisan sync:products --marketplace=ozon --integration_id=XXX
```

3. Пересчитайте юнит-экономику:
```bash
php artisan sync:unit-economics --marketplace=ozon --integration_id=XXX
```

4. Проверьте товар A39 — цена должна отображаться с учётом акции.

## Файлы изменений

- `app/Services/Marketplace/OzonService.php` — метод `enrichWithCommissions()`
- `app/Console/Commands/SyncUnitEconomicsCommand.php` — `buildCalculationData()`, `extractDetailedData()`
- `app/Models/UnitEconomics.php` — `$fillable`, `$casts`
- `database/migrations/2025_12_18_110000_add_promotion_fields_to_unit_economics.php`
