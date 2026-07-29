# Покрытие реализации автопланирования Ozon

Дата: 29 июля 2026 года.

## Вердикт

Кодовый backend-контур плана OZ-0…OZ-6 реализован. Расчёт, review, validation, approval, materialization, асинхронное исполнение, tracking и plan-fact связаны в один сценарий.

Финальная регрессия 29 июля 2026 года:

- `619 passed`, `3252 assertions`, `0 failed`;
- `3 skipped` — проверки production-данных/ограничений тестовой SQLite-схемы;
- `13 deprecated` — предупреждения PHP 8.5/PHPUnit 12, не ошибки выполнения;
- зарегистрирован `41` route автопланирования;
- `schedule:list`, `composer validate` и `git diff --check` проходят.

Нельзя честно считать завершёнными действия, для которых в этом workspace отсутствует необходимый внешний ресурс:

- frontend продукта отсутствует: в `resources` есть только стандартный Laravel welcome scaffold;
- нет выделенного Ozon-кабинета для безопасного живого money-path contract test;
- коммерческие beta KPI требуют пользователей и нескольких реальных циклов поставки.

Это не скрытые TODO backend-кода, а release gates.

## Матрица плана

| Этап | Код | Автотесты | Состояние |
|---|---|---|---|
| OZ-0 статусы, freshness, snapshot, idempotency | `DataFreshnessRegistry`, `PlanningFactSnapshotService`, workflow migrations | freshness, workspace, materialization | готово |
| OZ-1 credentials | Bearer/API-key, expiry/health, reconnect update, alerts | auth, health, alert dedup | готово; OAuth onboarding зависит от выданного Ozon token |
| OZ-2 facts | `OzonPlanningFactsBuilder`, exact cluster mapping, current stock, postings, supplies, economics, constraints | calculation/contracts/freshness | готово |
| OZ-3 calculation v2 | 7/28/56, OOS, trial policies, ABC margin, safety stock, reserve, budget, confidence, shadow | calculation + optimizer | готово |
| OZ-4 review backend | фильтры, explain, aggregate, edits, bulk, audit, budget, compare | `OzonAutoSupplyPlanReviewTest` | готово |
| OZ-4 frontend | API-контракт подготовлен | — | внешний frontend-репозиторий не предоставлен |
| OZ-5 validation/execution | capability, validate, approve, fingerprint, materialize, async draft/timeslot, retry, idempotency | materialization/API contracts/status | готово; живой money-path не запускался |
| OZ-6 tracking/plan-fact | `/v3/supply-order/get`, quantities, WAPE, bias, OOS/excess/manual outcomes | reconciler/status/accuracy | готово |

## Инварианты безопасности

- План без завершённого snapshot не валидируется.
- Stale blocking facts и истёкшие credentials блокируют approval.
- Отсечённые бюджетом, отрицательной прибылью или отсутствием seller stock строки сохраняются для review, но имеют `qty=0` и `is_excluded=true`.
- Ручная правка требует причины, оставляет audit record, пересчитывает бюджет и отменяет validation/approval fingerprint.
- Неизвестный или недоступный кластер блокируется.
- Материализация не вызывает Ozon и идемпотентно создаёт одну `Supply` на кластер.
- Execute требует точную фразу `СОЗДАТЬ ПОСТАВКИ OZON` и клиентский idempotency key.
- Повторный money-path после неоднозначного ответа автоматически не выполняется.
- Таймслот обязан быть свежим и принадлежать этой интеграции, draft и складу.
- FBO posting list использует `/v3/posting/fbo/list`.
- После появления order ID tracking использует `/v3/supply-order/get`.

## Эксплуатация

Обязательные процессы:

```text
php artisan migrate --force
php artisan schedule:run
php artisan queue:work --queue=ozon-fact-refresh,ozon-supply-execution,default
```

Включённые по умолчанию расписания:

- refresh Ozon facts: `17 2,10,18 * * *`;
- tracking активных Ozon supplies: каждые 15 минут;
- plan-fact: ежедневно в 06:00;
- credential alerts: ежедневно в 08:00.

Настройки находятся в `config/autoplanning.php`.

## Release gates

Перед production:

1. Выполнить миграции на staging-копии данных.
2. Повторить полный тестовый набор и smoke всех 41 routes в staging-окружении.
3. Проверить воркеры и scheduler.
4. На отдельной Ozon-интеграции выполнить read-only sync.
5. После отдельного подтверждения создать один direct draft, получить slot, создать одну supply order и прочитать её через `/v3/supply-order/get`.
6. Повторить crossdock на разрешённой точке.
7. Проверить network-timeout/manual-reconciliation runbook.
8. Подключить frontend по UI/API контракту.

Живой money-path нельзя запускать как часть обычного автотеста: он создаёт реальную заявку в кабинете Ozon.
