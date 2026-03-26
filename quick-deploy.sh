#!/bin/bash
# Быстрый деплой через HTTPS
# Использование: ./quick-deploy.sh

set -e

echo "=== Деплой обновлений на сервер ==="
echo ""

# Данные для подключения
SERVER_IP="194.87.104.42"
SERVER_USER="root"
SERVER_PASS="o9MW*GCS1zDoSG"
REMOTE_PATH="/var/www/products-backend"

echo "1. Изменения уже в репозитории GitHub ✓"
echo ""
echo "2. Подключитесь к серверу вручную:"
echo "   ssh ${SERVER_USER}@${SERVER_IP}"
echo "   Пароль: ${SERVER_PASS}"
echo ""
echo "3. На сервере выполните команды:"
echo ""
echo "   cd ${REMOTE_PATH}"
echo "   git config --global --add safe.directory ${REMOTE_PATH}"
echo "   git pull origin main"
echo "   composer install --no-dev --optimize-autoloader"
echo "   php artisan migrate --force"
echo "   php artisan config:cache"
echo "   php artisan route:cache"
echo "   php artisan view:cache"
echo ""
echo "4. При необходимости перезапустите очереди:"
echo "   sudo supervisorctl restart all"
echo ""

# Копируем команды в буфер обмена (если возможно)
if command -v pbcopy &> /dev/null; then
    cat << 'CMDS' | pbcopy
cd /var/www/products-backend
git config --global --add safe.directory /var/www/products-backend
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo supervisorctl restart all
CMDS
    echo "✅ Команды скопированы в буфер обмена!"
fi

echo ""
echo "=== Готово ==="
