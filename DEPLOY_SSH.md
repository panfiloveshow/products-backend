# Инструкция по ручному деплою через SSH

## Проблема
Файлы на сервере принадлежат другому пользователю (root), поэтому автоматический rsync не работает.

## Решение 1: Подключиться через SSH и выполнить команды вручную

### Шаг 1: Подключение к серверу

Откройте терминал и выполните:

```bash
ssh danya_user@194.87.104.42
```

**Пароль:** `8o3QV0iWsZ3IGlP`

### Шаг 2: Переход в директорию проекта

```bash
cd /var/www/products-backend
```

### Шаг 3: Попытка git pull

```bash
git config --global --add safe.directory /var/www/products-backend
git pull origin main
```

**Если git pull не работает** (ошибка прав доступа), используйте Решение 2 ниже.

### Шаг 4: Установка зависимостей

```bash
composer install --no-dev --optimize-autoloader
```

### Шаг 5: Миграции

```bash
php artisan migrate --force
```

### Шаг 6: Очистка и кэширование

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Шаг 7: Перезапуск очередей (если нужно)

```bash
sudo supervisorctl restart all
```

Или:

```bash
sudo systemctl restart php-fpm
```

---

## Решение 2: Если нет прав на git pull

Поскольку файлы принадлежат root, попросите администратора сервера выполнить:

```bash
# На сервере от root
cd /var/www/products-backend
chown -R danya_user:danya_user .
git pull origin main
```

ИЛИ загрузите файлы через SFTP/FTP клиент:

1. Откройте FileZilla или другой FTP клиент
2. Подключитесь к серверу:
   - Host: `194.87.104.42`
   - Username: `danya_user`
   - Password: `8o3QV0iWsZ3IGlP`
   - Port: `22` (SFTP)
3. Загрузите изменённые файлы:
   ```
   app/Domains/Wildberries/WildberriesMarketplace.php
   app/Domains/Wildberries/Api/InventoryApi.php
   app/Domains/YandexMarket/YandexMarketMarketplace.php
   app/Domains/YandexMarket/Api/ProductsApi.php
   ```

---

## Решение 3: Через sudo (если есть права)

Попробуйте выполнить от имени root:

```bash
ssh danya_user@194.87.104.42
cd /var/www/products-backend
sudo git pull origin main
sudo chown -R danya_user:danya_user .
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

---

## Проверка после деплоя

### 1. Проверка версии

```bash
cd /var/www/products-backend
git log --oneline -1
```

Ожидаемый коммит: `f60294d` или новее

### 2. Проверка синхронизации Wildberries

```bash
php artisan sync:inventory wildberries
```

В логах должно быть:
```
WB FBS stocks merged {"fbs_count": X}
```

### 3. Проверка синхронизации Yandex

```bash
php artisan sync:products yandex_market
```

В логах должно быть:
```
YM enriching products with prices {"count": X}
YM enriching products with stocks {"count": X}
```

### 4. Проверка в БД

```bash
php artisan tinker
```

```php
// Wildberries FBS
DB::table('inventory_warehouses')
  ->where('marketplace', 'wildberries')
  ->where('fulfillment_type', 'FBS')
  ->count();

// Yandex с ценами
DB::table('products')
  ->where('marketplace', 'yandex_market')
  ->where('price', '>', 0)
  ->count();
```

### 5. Проверка логов

```bash
tail -100 /var/www/products-backend/storage/logs/laravel.log
```

---

## Изменённые файлы для деплоя

### Wildberries FBS Fix:
- `app/Domains/Wildberries/WildberriesMarketplace.php`
- `app/Domains/Wildberries/Api/InventoryApi.php`

### Yandex Prices/Stocks Fix:
- `app/Domains/YandexMarket/YandexMarketMarketplace.php`
- `app/Domains/YandexMarket/Api/ProductsApi.php`

---

## Если ничего не помогает

Свяжитесь с администратором сервера и попросите:

1. Дать права на запись в `/var/www/products-backend`
2. ИЛИ выполнить деплой от root:

```bash
ssh root@194.87.104.42
# Пароль: o9MW*GCS1zDoSG

cd /var/www/products-backend
git pull origin main
chown -R www-data:www-data .  # или danya_user:danya_user
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```
