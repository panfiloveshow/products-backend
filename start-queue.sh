#!/bin/bash

# Скрипт для запуска queue worker в фоне
cd "$(dirname "$0")"

echo "🚀 Запуск Queue Worker..."

# Запуск queue worker в фоне
nohup php artisan queue:work database \
    --sleep=3 \
    --tries=3 \
    --max-time=3600 \
    --timeout=60 \
    > storage/logs/queue-worker.log 2>&1 &

# Сохранить PID процесса
echo $! > storage/queue-worker.pid

echo "✅ Queue Worker запущен (PID: $!)"
echo "📋 Логи: storage/logs/queue-worker.log"
echo "🛑 Остановить: ./stop-queue.sh"
