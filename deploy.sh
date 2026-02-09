#!/bin/bash
# Деплой автопланирования поставок на сервер
# Использование: bash deploy.sh

set -e

echo "🚀 Деплой автопланирования поставок..."

# Подтягиваем изменения
git add -A
git commit -m "feat: автопланирование поставок (EWMA, XLSX export)" || true
git push origin main || git push origin master

echo "📦 Подключаемся к серверу и деплоим..."

ssh root@194.87.104.42 << 'ENDSSH'
cd /var/www/products-backend || cd /root/products-backend || { echo "❌ Не найдена папка проекта"; exit 1; }

echo "📥 Подтягиваем код..."
git pull

echo "📦 Устанавливаем зависимости..."
composer install --no-dev --optimize-autoloader

echo "🗄️ Запускаем миграции..."
php artisan migrate --force

echo "🧹 Очищаем кэш..."
php artisan route:clear
php artisan config:clear
php artisan cache:clear

echo "✅ Деплой завершён!"
ENDSSH

echo "🎉 Готово!"
