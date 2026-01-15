# Оптимизация Unit Economics API

## 1. Индексы в БД ✅

Миграция создана: `2025_12_23_073128_add_performance_indexes_to_unit_economics.php`

```sql
-- Составной индекс для выборки по интеграции и маркетплейсу
CREATE INDEX idx_ue_integration_marketplace ON unit_economics(integration_id, marketplace);

-- Индекс для фильтрации по схеме работы
CREATE INDEX idx_ue_integration_fulfillment ON unit_economics(integration_id, fulfillment_type);

-- Индекс для поиска по SKU
CREATE INDEX idx_ue_integration_sku ON unit_economics(integration_id, sku);

-- Индекс для сортировки по марже
CREATE INDEX idx_ue_margin ON unit_economics(integration_id, marketplace, margin_percent);

-- Индекс для актуальных схем
CREATE INDEX idx_ue_actual_scheme ON unit_economics(integration_id, marketplace, is_actual_scheme);
```

Применить: `php artisan migrate`

## 2. Кэширование тарифов WB

Тарифы WB захардкожены в `UnitEconomicsService`:
- `calculateWbLogistics()` — логистика по объёму
- `calculateWbKtr()` — КТР по индексу локализации

**Рекомендация:** При интеграции с API WB для получения актуальных тарифов:

```php
// В WildberriesService
public function getTariffs(int $integrationId): array
{
    return Cache::remember(
        "wb_tariffs_{$integrationId}",
        now()->addHours(6), // Кэш на 6 часов
        fn() => $this->fetchTariffsFromApi($integrationId)
    );
}

// Инвалидация при изменении
public function invalidateTariffsCache(int $integrationId): void
{
    Cache::forget("wb_tariffs_{$integrationId}");
}
```

## 3. Параметр fields для оптимизации ответа

**TODO:** Добавить в `IndexUnitEconomicsRequest`:

```php
'fields' => 'nullable|string', // Список полей через запятую
```

Пример запроса:
```
GET /api/unit-economics?marketplace=wildberries&integration_id=34&fields=sku,name,price,margin,profit
```

## 4. Cursor-based пагинация (для больших каталогов)

**TODO:** Для каталогов > 10000 товаров рекомендуется cursor-пагинация:

```php
// Вместо offset-based
GET /api/unit-economics?page=100&limit=50

// Использовать cursor
GET /api/unit-economics?cursor=eyJpZCI6MTIzNH0&limit=50
```

## 5. Фоновый пересчёт

Уже реализовано через `SyncUnitEconomicsJob`:

```php
// При изменении тарифов WB
dispatch(new SyncUnitEconomicsJob($integrationId));
```

## 6. Текущие оптимизации

### Реализовано:
- ✅ Индексы в БД (миграция создана)
- ✅ Фоновый пересчёт через Job
- ✅ Валидация fulfillment_type для WB (FBW, FBS, DBS, EDBS, DBW)
- ✅ Правильная дефолтная схема (FBW для WB, FBO для Ozon)

### TODO:
- [ ] Параметр `fields` для выборочных полей
- [ ] Cursor-пагинация для больших каталогов
- [ ] Интеграция с API WB для актуальных тарифов
- [ ] Redis-кэш для частых запросов

## 7. Мониторинг производительности

```php
// Добавить в контроллер для отладки
$startTime = microtime(true);
// ... запрос
$duration = microtime(true) - $startTime;
Log::info("Unit economics query took {$duration}s", [
    'integration_id' => $integrationId,
    'items_count' => count($items),
]);
```
