# Изменения 19.12.2025

## 🎯 Основные изменения

### 1. Габариты товаров

**Проблема:** Колонки ДЛИНА, ШИРИНА, ВЫСОТА, ВЕС показывали 0 на фронтенде.

**Решение:**

1. Добавлены поля в модель `Product`:
   - `depth` — Длина (мм)
   - `width` — Ширина (мм)
   - `height` — Высота (мм)
   - `weight` — Вес (г)
   - `volume_weight` — Объём (л)

2. Создана миграция: `2025_12_19_103700_add_dimensions_to_products_table.php`

3. Обновлён `OzonService::transformProduct()` для извлечения габаритов из API

4. Создана команда для обновления габаритов из характеристик:
   ```bash
   php artisan products:update-dimensions --integration=13
   ```

**Файлы:**
- `app/Models/Product.php` — добавлены поля в `$fillable` и `$casts`
- `app/Services/Marketplace/OzonService.php` — извлечение габаритов
- `app/Jobs/SyncProductsJob.php` — добавлены габариты в `$fieldsToCompare`
- `app/Console/Commands/UpdateProductDimensions.php` — новая команда

---

### 2. Пересчёт тарифов по схеме (fulfillment_type)

**Проблема:** При переключении вкладки FBO/FBS/RFBS/EXPRESS бекенд фильтровал товары вместо пересчёта тарифов.

**Решение:** Параметр `fulfillment_type` теперь пересчитывает тарифы для выбранной схемы, а не фильтрует товары.

**Логика:**
```
GET /api/unit-economics/ozon?fulfillment_type=FBS

1. Бекенд возвращает ВСЕ товары
2. Для каждого товара пересчитываются тарифы по схеме FBS
3. Фронтенд показывает все товары с расчётами для FBS
```

**Файлы:**
- `app/Http/Controllers/Api/UnitEconomicsController.php` — метод `byMarketplace()`
- `app/Services/UnitEconomicsService.php` — метод `recalculateForScheme()`

---

### 3. Определение схемы при синхронизации

**Добавлен метод** `OzonService::determineFulfillmentType()` который определяет схему работы товара из Ozon API:

1. Проверяет `sources[]` — массив активных схем
2. Проверяет `visibility_details.has_stock`
3. Проверяет `fbo_sku` / `fbs_sku`
4. Проверяет `stocks[].type` — тип остатков
5. Проверяет `commissions[]` — комиссии по схемам
6. По умолчанию — FBO

**Файлы:**
- `app/Services/Marketplace/OzonService.php:445-543`

---

### 4. Исправления багов

#### 4.1 Порядок аргументов в startSync()

**Проблема:** `TypeError: Argument #2 ($credentials) must be of type array, string given`

**Решение:** Исправлен порядок аргументов в вызовах `startSync()`:

```php
// Было (неправильно):
$productService->startSync($marketplace, $syncType, $credentials, $integrationId);

// Стало (правильно):
$productService->startSync($marketplace, $credentials, $integrationId, $syncType);
```

**Файлы:**
- `app/Http/Controllers/Api/IntegrationController.php:236`
- `app/Console/Commands/AutoSync.php:69`

#### 4.2 OzonService конструктор

**Проблема:** `Cannot assign null to property $clientId of type string`

**Решение:**
```php
// Было:
$this->clientId = $clientId ?? config('services.ozon.client_id', '');

// Стало:
$this->clientId = $clientId ?? config('services.ozon.client_id') ?? '';
```

#### 4.3 Удалена битая миграция

Удалён пустой файл миграции:
`2025_12_17_144010_change_unit_economics_unique_key_to_sku_integration.php`

---

## 📁 Новые файлы

| Файл | Описание |
|------|----------|
| `database/migrations/2025_12_19_103700_add_dimensions_to_products_table.php` | Миграция для габаритов |
| `app/Console/Commands/UpdateProductDimensions.php` | Команда обновления габаритов |

---

## 🔧 Команды

```bash
# Обновить габариты товаров из характеристик
php artisan products:update-dimensions --integration=13

# Синхронизация товаров
php artisan sync:auto --marketplace=ozon --type=products

# Синхронизация юнит-экономики
php artisan unit-economics:sync --marketplace=ozon
```

---

## ⚠️ Важно для фронтенда

### Переключатель схем (FBO/FBS/RFBS/EXPRESS)

При переключении вкладки:
1. Фронтенд отправляет запрос с `fulfillment_type`
2. Бекенд возвращает **все товары** с пересчитанными тарифами
3. Фронтенд показывает все товары (фильтрация не нужна)

```typescript
// Запрос
const response = await unitEconomicsApi.getByMarketplace('ozon', {
  integration_id: 13,
  fulfillment_type: 'FBS', // Схема для расчёта
});

// Ответ содержит ВСЕ товары с тарифами для FBS
```

### Габариты

Новые поля в ответе API:
- `depth` — Длина (мм)
- `width` — Ширина (мм)
- `height` — Высота (мм)
- `weight` — Вес (г)
- `volume_weight` — Объём (л)

---

## 📊 Статус

- ✅ Миграция выполнена
- ✅ Габариты 101 товара обновлены
- ✅ Бекенд работает корректно
- ⏳ Фронтенд требует обновления (передача `fulfillment_type`, убрать фильтрацию)
