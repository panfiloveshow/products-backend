#!/bin/bash
# Деплой через rsync + sudo

set -e

DEPLOY_USER="danya_user"
DEPLOY_HOST="194.87.104.42"
DEPLOY_PASS="8o3QV0iWsZ3IGlP"
REMOTE_PATH="/var/www/products-backend"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=== Деплой через rsync + sudo ==="
echo ""

export SSHPASS="$DEPLOY_PASS"

# Копируем файлы во временную директорию
TEMP_DIR="/tmp/deploy-$(date +%Y%m%d-%H%M%S)"
echo "📦 Копирование файлов во временную директорию $TEMP_DIR..."

sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 "${DEPLOY_USER}@${DEPLOY_HOST}" << ENDSSH
mkdir -p $TEMP_DIR
ENDSSH

sshpass -e rsync -avz \
  --exclude 'node_modules/' \
  --exclude 'vendor/' \
  --exclude '.env' \
  --exclude 'storage/logs/' \
  -e "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10" \
  "$SCRIPT_DIR/" "${DEPLOY_USER}@${DEPLOY_HOST}:${TEMP_DIR}/"

echo ""
echo "✅ Файлы загружены во временную директорию"
echo ""

# Копируем файлы с sudo
echo "⚙️  Копирование файлов в проект с sudo..."
sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 "${DEPLOY_USER}@${DEPLOY_HOST}" << ENDSSH
echo "$DEPLOY_PASS" | sudo -S cp -r ${TEMP_DIR}/* ${REMOTE_PATH}/
echo "$DEPLOY_PASS" | sudo -S chown -R www-data:www-data ${REMOTE_PATH}/app ${REMOTE_PATH}/bootstrap ${REMOTE_PATH}/routes
rm -rf $TEMP_DIR
ENDSSH

echo ""
echo "✅ Файлы скопированы"
echo ""

# Выполняем команды
echo "⚙️  Выполнение команд на сервере..."
sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 "${DEPLOY_USER}@${DEPLOY_HOST}" << ENDSSH
cd ${REMOTE_PATH}
echo "$DEPLOY_PASS" | sudo -S composer install --no-dev --optimize-autoloader --no-interaction
echo "$DEPLOY_PASS" | sudo -S php artisan migrate --force
echo "$DEPLOY_PASS" | sudo -S php artisan config:clear
echo "$DEPLOY_PASS" | sudo -S php artisan cache:clear
echo "$DEPLOY_PASS" | sudo -S php artisan config:cache
echo "$DEPLOY_PASS" | sudo -S php artisan route:cache
ENDSSH

echo ""
echo "✅ Деплой завершён успешно!"
echo ""
echo "📋 Проверка:"
echo "  php artisan sync:products yandex_market"
echo "  php artisan sync:inventory wildberries"
