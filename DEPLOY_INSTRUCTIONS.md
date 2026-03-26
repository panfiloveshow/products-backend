# Инструкция по деплою на сервер

## Данные для подключения:
- **Сервер**: 194.87.104.42
- **Пользователь**: danya_user
- **Пароль**: 8o3QV0iWsZ3IGlP
- **Путь к проекту**: /var/www/products-backend

## Изменения отправлены в репозиторий
Все изменения уже запушены в репозиторий GitHub: https://github.com/panfiloveshow/products-backend.git

## Способ 1: Ручное обновление через SSH (рекомендуется)

1. Подключитесь к серверу через терминал:
```bash
ssh danya_user@194.87.104.42
```
Введите пароль: `8o3QV0iWsZ3IGlP`

2. Перейдите в директорию проекта:
```bash
cd /var/www/products-backend
```

3. Настройте безопасную директорию для git:
```bash
git config --global --add safe.directory /var/www/products-backend
```

4. Получите обновления из репозитория:
```bash
git pull origin main
```

5. Установите зависимости PHP:
```bash
composer install --no-dev --optimize-autoloader
```

6. Выполните миграции базы данных:
```bash
php artisan migrate --force
```

7. Очистите и пересоздайте кэши:
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

8. Перезапустите очереди (если используется supervisor):
```bash
sudo supervisorctl restart all
```

9. Перезапустите PHP-FPM (опционально):
```bash
sudo systemctl restart php-fpm
# или
sudo systemctl restart php8.2-fpm
```

## Способ 2: Автоматический скрипт деплоя

Если SSH подключается успешно, можно использовать скрипт:

```bash
cd /Users/panfiloveshow/Documents/Товары\ бекенд/products-backend
./deploy-to-server.sh
```

Скрипт автоматически:
- Подключится к серверу
- Выполнит git pull
- Установит зависимости
- Выполнит миграции
- Обновит кэши

## Основные изменения в этом обновлении:

✅ **Исправлена синхронизация товаров Яндекс Маркет**
- Исправлен метод `YandexMarketMarketplace::getProducts()` для правильной обработки пагинации
- Добавлена трансформация данных из API в формат приложения
- Товары теперь корректно синхронизируются с Yandex Market

✅ **Обновлены API клиенты для всех маркетплейсов**
- Yandex Market API
- Wildberries API  
- Ozon API

✅ **Улучшена обработка интеграций**
- Исправлены проблемы с фильтрацией по integration_id
- Улучшена работа с остатками

## Проверка после деплоя:

1. Проверьте статус приложения:
```bash
php artisan status
# или
php artisan about
```

2. Проверьте логи:
```bash
tail -f storage/logs/laravel.log
```

3. Проверьте синхронизацию Яндекс Маркет:
```bash
php artisan sync:status
```

4. Протестируйте API:
```bash
curl -X GET "https://your-domain.com/api/products?marketplace=yandex_market" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Если что-то пошло не так:

1. Откат к предыдущей версии:
```bash
cd /var/www/products-backend
git log --oneline -5  # найти предыдущий коммит
git reset --hard <previous-commit-hash>
php artisan config:cache
php artisan route:cache
```

2. Проверка логов ошибок:
```bash
tail -100 storage/logs/laravel.log
```

3. Проверка подключения к БД:
```bash
php artisan tinker
>>> DB::connection()->getPdo();
```
