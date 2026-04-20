# Загрузка файлов через веб-интерфейс

Поскольку SSH не работает, используем альтернативный метод загрузки через веб.

## Шаг 1: Загрузите скрипт upload-via-web.php

### Вариант A: Через любой доступный FTP/SFTP клиент

1. Откройте FileZilla или другой FTP клиент
2. Подключитесь к серверу:
   - Хост: `194.87.104.42`
   - Логин: `root` или `danya_user`
   - Пароль: `o9MW*GCS1zDoSG` или `8o3QV0iWsZ3IGlP`
   - Порт: `21` (FTP) или `22` (SFTP)

3. Загрузите файл `upload-via-web.php` в директорию:
   ```
   /var/www/products-backend/public/
   ```
   ИЛИ
   ```
   /var/www/products-backend/
   ```

### Вариант B: Через панель управления хостингом

Если у вас есть панель управления (ISPmanager, cPanel, etc.):
1. Зайдите в файловый менеджер
2. Перейдите в `/var/www/products-backend/public/`
3. Создайте файл `upload-via-web.php`
4. Скопируйте в него содержимое из локального файла

## Шаг 2: Откройте скрипт в браузере

```
http://194.87.104.42/upload-via-web.php
```

ИЛИ

```
http://products.sellico.ru/upload-via-web.php
```

## Шаг 3: Загрузите файлы

1. Введите секретный токен: `upload_token_2026_secure`
2. Выберите файл для загрузки: `CheckSellicoPermission.php`
3. Нажмите "Загрузить"

## Шаг 4: Переместите файл в нужную директорию

После загрузки файла через веб-интерфейс, подключитесь к серверу через терминал (если это возможно) или через панель управления и выполните:

```bash
# Если файл загружен в public директорию
cd /var/www/products-backend/public
mv CheckSellicoPermission.php ../app/Http/Middleware/

# Или скопируйте через файловый менеджер панели управления
```

## Шаг 5: Очистите кэш

После загрузки файла выполните команды на сервере (через SSH или панель):

```bash
cd /var/www/products-backend
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
```

## Шаг 6: УДАЛИТЕ скрипт upload-via-web.php!

Это важно для безопасности! После завершения загрузки удалите файл:

```bash
rm /var/www/products-backend/public/upload-via-web.php
```

---

## Альтернативный вариант: Прямое редактирование через панель

Если у вас есть доступ к панели управления сервером:

1. Зайдите в файловый менеджер панели
2. Перейдите в `/var/www/products-backend/app/Http/Middleware/`
3. Откройте файл `CheckSellicoPermission.php` для редактирования
4. Скопируйте и вставьте новое содержимое из локального файла
5. Сохраните изменения

---

## Проверка после загрузки

1. Откройте терминал и выполните:
```bash
curl -X GET "https://products.sellico.ru/api/products?marketplace=ozon&integration_id=12&workspace=23"
```

2. Проверьте логи:
```bash
tail -100 /var/www/products-backend/storage/logs/laravel.log | grep -i permission
```

---

## Контакты для помощи

Если возникли проблемы, свяжитесь с администратором сервера и попросите:
1. Включить SSH доступ
2. ИЛИ загрузить файл `CheckSellicoPermission.php` вручную
3. ИЛИ дать доступ к панели управления сервером
