#!/bin/bash
# Простой скрипт деплоя через rsync

set -e

DEPLOY_USER="danya_user"
DEPLOY_HOST="194.87.104.42"
DEPLOY_PASS="8o3QV0iWsZ3IGlP"
REMOTE_PATH="/var/www/products-backend"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=== Деплой на сервер ==="
echo "Сервер: ${DEPLOY_USER}@${DEPLOY_HOST}:${REMOTE_PATH}"
echo ""

# Синхронизируем файлы через rsync
echo "📦 Синхронизация файлов..."
export SSHPASS="$DEPLOY_PASS"
sshpass -e rsync -avz --delete \
  --exclude '.git/' \
  --exclude 'node_modules/' \
  --exclude 'vendor/' \
  --exclude '.env' \
  --exclude 'storage/logs/' \
  --exclude 'storage/framework/cache/data/' \
  --exclude 'storage/framework/sessions/' \
  --exclude 'storage/framework/views/' \
  -e "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10" \
  "$SCRIPT_DIR/" "${DEPLOY_USER}@${DEPLOY_HOST}:${REMOTE_PATH}/"

echo ""
echo "✅ Файлы загружены"
echo ""

# Выполняем команды на сервере
echo "⚙️  Выполнение команд на сервере..."
export SSHPASS="$DEPLOY_PASS"
sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 "${DEPLOY_USER}@${DEPLOY_HOST}" << 'ENDSSH'
cd /var/www/products-backend

echo "  - Установка зависимостей..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "  - Миграции БД..."
php artisan migrate --force

echo "  - Очистка кэшей..."
php artisan config:clear
php artisan route:clear
php artisan view:clear

echo "  - Оптимизация..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "  - Перезапуск очередей..."
# supervisorctl restart all || true

ENDSSH

echo ""
echo "✅ Деплой завершён успешно!"
echo ""
echo "📋 Рекомендации:"
echo "  1. Проверьте логи: tail -f /var/www/products-backend/storage/logs/laravel.log"
echo "  2. Проверьте синхронизацию: php artisan sync:status"
echo "  3. Протестируйте API"
