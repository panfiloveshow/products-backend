# Runbook: деплой авто-синка ограничений МП (этап 1)

**Что деплоим:** ветка `feat/mp-constraints-autosync` — новая таблица `marketplace_constraint_snapshots`, колонка `source_kind` в `auto_supply_constraint_files`, сервис/команда синка, подключение в создание плана.
**Риск миграций:** низкий — обе аддитивные (новая таблица + nullable-колонка с дефолтом, guard через `hasColumn`). Существующие данные не трогаются.
**Расписание:** по умолчанию ВЫКЛЮЧЕНО (`MP_CONSTRAINTS_SCHEDULE` не задан) — после деплоя ничего не запустится само.

---

## 0. Перед началом (проверка на безопасной среде)

Миграции уже проверены up/down на одноразовой sqlite и в полном наборе на sqlite (feature-тесты). Если есть staging с PostgreSQL — прогнать там первым. Если нет — переходить к проду с обязательным бэкапом (шаг 2).

```bash
# локально, на одноразовой БД (никогда не на проде):
php artisan test tests/Unit/MarketplaceConstraintSyncServiceTest.php tests/Feature/AutoSupplyPlanCreateTest.php
```

---

## 1. Доставка кода на сервер

Через штатный процесс (см. `DEPLOY_SSH.md` / `deploy-to-server.sh`). Порядок: смержить ветку → задеплоить, ИЛИ задеплоить ветку напрямую для проверки.

```bash
# на сервере 194.87.104.42, в каталоге проекта:
git fetch origin
git checkout feat/mp-constraints-autosync   # или main после merge
git pull
composer install --no-dev --optimize-autoloader   # зависимостей не добавлялось, но на всякий
```

> Важно: код должен оказаться на сервере ДО миграции — иначе таблица появится без модели/сервиса в приложении.

---

## 2. Бэкап затронутых объектов (ОБЯЗАТЕЛЬНО)

Перед `migrate` снять дамп схемы + затронутой таблицы. Подставить реальные креды из окружения сервера (НЕ из переписки — пароль, присланный в чат, рекомендуется сменить).

```bash
# дамп только структуры затронутой таблицы (для быстрого отката source_kind)
pg_dump -h <DB_HOST> -p <DB_PORT> -U <DB_USER> -d products_backend \
  --schema-only -t auto_supply_constraint_files \
  > /tmp/backup_constraint_files_schema_$(date +%F).sql

# на всякий — данные таблицы ограничений (она невелика)
pg_dump -h <DB_HOST> -p <DB_PORT> -U <DB_USER> -d products_backend \
  -t auto_supply_constraint_files \
  > /tmp/backup_constraint_files_$(date +%F).sql
```

(`marketplace_constraint_snapshots` — новая, бэкапить нечего: при откате просто дропнется.)

---

## 3. Применение миграций

```bash
php artisan migrate --step --force
```

Ожидаемый вывод — две строки:
```
2026_06_11_120000_create_marketplace_constraint_snapshots_table ... DONE
2026_06_11_120100_add_source_kind_to_auto_supply_constraint_files_table ... DONE
```

Проверка:
```bash
php artisan tinker --execute="echo Schema::hasTable('marketplace_constraint_snapshots') ? 'table OK' : 'NO TABLE'; echo PHP_EOL; echo Schema::hasColumn('auto_supply_constraint_files','source_kind') ? 'column OK' : 'NO COLUMN';"
```

---

## 4. Дымовой прогон синка (одна интеграция)

Без расписания, вручную, на одной WB-интеграции — убедиться, что API отвечает и снапшот пишется:

```bash
php artisan mp:sync-constraints --integration=<WB_INTEGRATION_ID>
```

Ожидаемо: строка вида `[wildberries] integration=<id> status=ok warehouses=N available=X blocked=Y`.
Проверить снапшот:
```bash
php artisan tinker --execute="\$s = App\Models\MarketplaceConstraintSnapshot::where('integration_id', <WB_INTEGRATION_ID>)->first(); echo \$s?->sync_status; echo PHP_EOL; echo count(\$s?->warehouse_constraints_json ?? []);"
```

Если `status=error` — посмотреть `sync_error`/`summary_json.reason` (частые причины: нет api_key после resolveCredentials, WB API недоступен).

---

## 5. Включение расписания (только после успешного дымового прогона)

Проверить, что на сервере вообще работает Laravel scheduler:
```bash
crontab -l | grep "schedule:run" || echo "schedule:run НЕ настроен — нужен системный cron"
```

- Если `schedule:run` есть → добавить в `.env` сервера:
  ```
  MP_CONSTRAINTS_SCHEDULE=true
  ```
  затем `php artisan config:clear` (если конфиг кешируется). Проверить: `php artisan schedule:list | grep mp:sync-constraints`.
- Если `schedule:run` НЕ настроен (как для ue:sanity-check, см. `routes/console.php`) → завести системный cron:
  ```
  # /etc/cron.d/mp-constraints-sync
  0 * * * * www-data cd /var/www/products-backend && php artisan mp:sync-constraints --marketplace=wildberries >> storage/logs/mp-constraints-sync.log 2>&1
  ```

---

## 6. Проверка эффекта на план

Создать план для WB-интеграции БЕЗ загрузки файла ограничений и без `warehouse_constraints` в запросе → в `result_json`/summary источник ограничений должен быть `api_sync`, закрытые склады заблокированы. Либо проверить через UI после правок этапа 5 (UI-блок источника — отдельный тикет).

---

## Откат

```bash
# код
git checkout main && git pull   # или предыдущий тег

# миграции (обе down() проверены)
php artisan migrate:rollback --step=2 --force
# при проблеме с source_kind — восстановить схему из бэкапа шага 2
```

Откат безопасен: `marketplace_constraint_snapshots` дропается целиком, `source_kind` удаляется (данные плана от него не зависят — он только для метки источника).

---

## Чек-лист

- [ ] Код на сервере (git pull) — ДО миграции
- [ ] Бэкап `auto_supply_constraint_files` снят
- [ ] `migrate --force` — две миграции DONE
- [ ] Schema-проверка: таблица + колонка на месте
- [ ] Дымовой `mp:sync-constraints --integration=<id>` → status=ok
- [ ] Решено по scheduler (Laravel cron vs системный)
- [ ] `MP_CONSTRAINTS_SCHEDULE=true` (или системный cron) включён осознанно
- [ ] План без файла использует `api_sync`
