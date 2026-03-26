#!/bin/bash
# Ручной деплой ключевых файлов

set -e

DEPLOY_USER="danya_user"
DEPLOY_HOST="194.87.104.42"
DEPLOY_PASS="8o3QV0iWsZ3IGlP"
REMOTE_PATH="/var/www/products-backend"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=== Деплой исправлений Yandex и Wildberries ==="
echo ""

export SSHPASS="$DEPLOY_PASS"

# Копируем только изменённые файлы
echo "📦 Копирование файлов..."

# Wildberries
sshpass -e rsync -avz \
  -e "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10" \
  "$SCRIPT_DIR/app/Domains/Wildberries/WildberriesMarketplace.php" \
  "${DEPLOY_USER}@${DEPLOY_HOST}:${REMOTE_PATH}/app/Domains/Wildberries/"

sshpass -e rsync -avz \
  -e "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10" \
  "$SCRIPT_DIR/app/Domains/Wildberries/Api/InventoryApi.php" \
  "${DEPLOY_USER}@${DEPLOY_HOST}:${REMOTE_PATH}/app/Domains/Wildberries/Api/"

# Yandex
sshpass -e rsync -avz \
  -e "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10" \
  "$SCRIPT_DIR/app/Domains/YandexMarket/YandexMarketMarketplace.php" \
  "${DEPLOY_USER}@${DEPLOY_HOST}:${REMOTE_PATH}/app/Domains/YandexMarket/"

sshpass -e rsync -avz \
  -e "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10" \
  "$SCRIPT_DIR/app/Domains/YandexMarket/Api/ProductsApi.php" \
  "${DEPLOY_USER}@${DEPLOY_HOST}:${REMOTE_PATH}/app/Domains/YandexMarket/Api/"

echo ""
echo "✅ Файлы загружены"
echo ""

# Выполняем команды на сервере
echo "⚙️  Выполнение команд на сервере..."
sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 "${DEPLOY_USER}@${DEPLOY_HOST}" << 'ENDSSH'
cd /var/www/products-backend

echo "  - Очистка кэшей..."
php artisan config:clear
php artisan cache:clear

echo "  - Оптимизация..."
php artisan config:cache
php artisan route:cache

ENDSSH

echo ""
echo "✅ Деплой завершён!"
echo ""
echo "📋 Следующие шаги:"
echo "  1. Запустите синхронизацию Wildberries:"
echo "     php artisan sync:inventory wildberries"
echo ""
echo "  2. Запустите синхронизацию Yandex:"
echo "     php artisan sync:products yandex_market"
echo ""
echo "  3. Проверьте логи:"
echo "     tail -f storage/logs/laravel.log"
