#!/bin/bash
# Быстрое исправление бесконечной загрузки Yandex

DEPLOY_USER="danya_user"
DEPLOY_HOST="194.87.104.42"
DEPLOY_PASS="8o3QV0iWsZ3IGlP"
REMOTE_PATH="/var/www/products-backend"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=== Исправление бесконечной загрузки Yandex ==="
echo ""

export SSHPASS="$DEPLOY_PASS"

# Копируем файл
echo "📦 Копирование исправленного файла..."
sshpass -e rsync -avz \
  -e "ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10" \
  "$SCRIPT_DIR/app/Domains/YandexMarket/YandexMarketMarketplace.php" \
  "${DEPLOY_USER}@${DEPLOY_HOST}:${REMOTE_PATH}/app/Domains/YandexMarket/"

echo ""
echo "✅ Файл загружен"
echo ""

# Очищаем кэш
echo "⚙️  Очистка кэша..."
sshpass -e ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 "${DEPLOY_USER}@${DEPLOY_HOST}" << 'ENDSSH'
cd /var/www/products-backend
php artisan config:clear
php artisan cache:clear
ENDSSH

echo ""
echo "✅ Готово!"
echo ""
echo "📋 Проверка:"
echo "  Запустите синхронизацию Yandex - должна завершиться за < 30 секунд"
