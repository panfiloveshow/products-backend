# Artisan команды для управления синхронизацией

## Синхронизация

### `sync:auto`
Автоматическая синхронизация всех активных интеграций.

```bash
# Синхронизация товаров для всех маркетплейсов
php artisan sync:auto --type=products

# Синхронизация остатков для всех маркетплейсов
php artisan sync:auto --type=inventory

# Только для конкретного маркетплейса
php artisan sync:auto --marketplace=wildberries --type=products
```

**Расписание:** 
- Товары: каждые 6 часов
- Остатки: каждые 2 часа

### `sync:status`
Показывает статус синхронизаций по маркетплейсам.

```bash
# Статус за последний день
php artisan sync:status

# Статус за последние 7 дней
php artisan sync:status --days=7
```

### `sync:cleanup`
Автоматически завершает зависшие синхронизации.

```bash
# Завершить синхронизации старше 30 минут
php artisan sync:cleanup --minutes=30
```

**Расписание:** каждые 15 минут

---

## Остатки и история

### `inventory:snapshot`
Создаёт ежедневный снимок остатков для графиков динамики.

```bash
# Снимок для всех маркетплейсов
php artisan inventory:snapshot

# Только для конкретного маркетплейса
php artisan inventory:snapshot --marketplace=ozon
```

**Расписание:** ежедневно в 23:55

### `inventory:cleanup`
Удаляет старые записи истории остатков.

```bash
# Хранить данные за последние 90 дней (по умолчанию)
php artisan inventory:cleanup

# Хранить данные за последние 30 дней
php artisan inventory:cleanup --days=30
```

**Расписание:** еженедельно по воскресеньям в 03:00

---

## Юнит-экономика

### `unit-economics:sync`
Синхронизация юнит-экономики из реальных данных API (Ozon, WB, Yandex).

```bash
# Синхронизация для конкретной интеграции
php artisan unit-economics:sync --integration=19

# Синхронизация для маркетплейса
php artisan unit-economics:sync --marketplace=ozon

# Синхронизация всех интеграций
php artisan unit-economics:sync --all
```

**Что делает:**
- Получает актуальные данные из API маркетплейсов
- Рассчитывает комиссии, логистику, возвраты
- Сохраняет результаты в таблицу `unit_economics`

**TTL-кэширование (24 часа):**
- `premium_checked_at` — статус Premium аккаунта
- `localization_checked_at` — индекс локализации (время доставки)
- `redemption_checked_at` — процент выкупа

**Расписание:** ежедневно в 04:30 (после синка товаров)

---

## Проверка данных

### `data:check`
Проверяет целостность данных и выводит проблемы.

```bash
php artisan data:check
```

Проверяет:
- Товары с остатками, но без записей в InventoryWarehouse
- Записи остатков для несуществующих товаров
- Товары с отрицательным остатком

**Расписание:** ежедневно в 05:00

---

## Расписание (Scheduler)

| Команда | Расписание | Описание |
|---------|------------|----------|
| `sync:cleanup` | Каждые 15 мин | Очистка зависших синхронизаций |
| `sync:auto --type=inventory` | Каждые 2 часа | Автосинхронизация остатков |
| `sync:auto --type=products` | Каждые 6 часов | Автосинхронизация товаров |
| `unit-economics:sync` | 04:30 | Синхронизация юнит-экономики из API |
| `CalculateUnitEconomicsJob` | 05:00 | Fallback расчёт юнит-экономики |
| `inventory:snapshot` | 23:55 | Снимок остатков |
| `data:check` | 05:00 | Проверка целостности |
| `inventory:cleanup` | Вс 03:00 | Очистка истории |

---

## Запуск Scheduler

Для работы автоматических задач добавьте в crontab:

```bash
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

Или для разработки:

```bash
php artisan schedule:work
```
