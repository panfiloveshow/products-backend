#!/bin/bash

# Скрипт для остановки queue worker
cd "$(dirname "$0")"

if [ -f storage/queue-worker.pid ]; then
    PID=$(cat storage/queue-worker.pid)
    
    if ps -p $PID > /dev/null; then
        echo "🛑 Остановка Queue Worker (PID: $PID)..."
        kill $PID
        rm storage/queue-worker.pid
        echo "✅ Queue Worker остановлен"
    else
        echo "⚠️ Process с PID $PID не найден"
        rm storage/queue-worker.pid
    fi
else
    echo "⚠️ PID файл не найден. Queue Worker может быть уже остановлен."
fi

# Дополнительная проверка и остановка всех artisan queue процессов
QUEUE_PIDS=$(pgrep -f "artisan queue:work")
if [ ! -z "$QUEUE_PIDS" ]; then
    echo "🔍 Найдены дополнительные queue процессы: $QUEUE_PIDS"
    echo "🛑 Остановка всех queue процессов..."
    pkill -f "artisan queue:work"
    echo "✅ Все queue процессы остановлены"
fi
