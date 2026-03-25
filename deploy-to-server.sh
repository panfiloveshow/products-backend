#!/usr/bin/env bash
# Деплой на сервер. Запуск: ./deploy-to-server.sh
# Пароль SSH вводите вручную, когда появится запрос.
# Другой каталог: REMOTE_PATH=/path ./deploy-to-server.sh
#
# Если видите "Connection closed by ... port 22" — на сервере часто запрещён root по паролю.
# Проверка: ssh -vv root@194.87.104.42
# На VPS: PermitRootLogin / вход только по ключу — см. панель хостинга или пользователя ubuntu/debian.

set -euo pipefail

DEPLOY_USER="root"
DEPLOY_HOST="194.87.104.42"
HOST="${DEPLOY_USER}@${DEPLOY_HOST}"
REMOTE_PATH="${REMOTE_PATH:-/var/www/products-backend}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "Локально:  $SCRIPT_DIR"
echo "Сервер:    $HOST:$REMOTE_PATH"
echo ""

rsync -avz --delete \
  --exclude '.git/' \
  --exclude 'node_modules/' \
  --exclude 'vendor/' \
  --exclude '.env' \
  --exclude 'storage/logs/' \
  --exclude 'storage/framework/cache/data/' \
  --exclude 'storage/framework/sessions/' \
  --exclude 'storage/framework/views/' \
  -e "ssh -o StrictHostKeyChecking=accept-new" \
  "$SCRIPT_DIR/" "${HOST}:${REMOTE_PATH}/"

echo ""
echo "Rsync готов. Обновление зависимостей и миграции на сервере..."
ssh -o StrictHostKeyChecking=accept-new "$HOST" "cd ${REMOTE_PATH} && composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache"

echo ""
echo "Готово."
