# Покрытие реализации автопланирования Ozon

Дата: 29 июля 2026 года.

## Вердикт

Кодовый backend-контур плана OZ-0…OZ-6 реализован. Расчёт, review, validation, approval, materialization, асинхронное исполнение, tracking и plan-fact связаны в один сценарий.

Регрессия после аудита покрытия 29 июля 2026 года:

- `663 tests`, `3365 assertions`, `0 failed`;
- `3 skipped` — проверки production-данных/ограничений тестовой SQLite-схемы;
- `2 deprecated` — предупреждения PHPUnit, не ошибки выполнения;
- зарегистрирован `41` route автопланирования;
- `composer validate` и `git diff --check` проходят.

Frontend продукта реализован в отдельном репозитории `placesales_front`
(`src/services/autoSupplyPlanApi.ts`, `OzonDataReadinessPanel`,
`OzonAutoPlanningControlCenter`, `AutoSupplyPlanDetailPage`,
`OzonDraftConfirmationDialog`), покрыт 12 тестами и выложен на sellico.ru.
Предыдущая редакция этого документа ошибочно утверждала, что frontend отсутствует:
проверка велась только по `resources` backend-репозитория.

Нельзя честно считать завершёнными действия, для которых отсутствует внешний ресурс:

- нет выделенного Ozon-кабинета для безопасного живого money-path contract test;
- коммерческие beta KPI требуют пользователей и нескольких реальных циклов поставки.

Это не скрытые TODO backend-кода, а release gates.

## Расхождения кода и текста плана

Зафиксированы тестами как фактическое поведение, продуктовое решение не принято:

- срок поставки входит в расчёт только через страховой запас `SS = z × σ × √lead_time`.
  Формулы `gross_need = forecast × (lead_time + target_cover) + safety_stock` из плана
  в коде нет: при нулевой волатильности спроса lead time на количество не влияет;
- OOS-коррекция применяется к агрегированному спросу (`max(real, effective)` при
  `days_in_stock_30 < 28`), а не к каждому окну 7/28/56, как записано в формуле плана;
- OAuth — это приём готового Bearer-токена. Authorization code flow и ротации
  refresh-токена нет, «переподключение» = перезапись credentials.

## Матрица плана

| Этап | Код | Автотесты | Состояние |
|---|---|---|---|
| OZ-0 статусы, freshness, snapshot, idempotency | `DataFreshnessRegistry`, `PlanningFactSnapshotService`, workflow migrations | freshness, workspace, materialization | готово |
| OZ-1 credentials | Bearer/API-key, expiry/health, reconnect update, alerts | auth, health, alert dedup | готово; OAuth onboarding зависит от выданного Ozon token |
| OZ-2 facts | `OzonPlanningFactsBuilder`, exact cluster mapping, current stock, postings, supplies, economics, constraints | calculation/contracts/freshness | готово |
| OZ-3 calculation v2 | 7/28/56, OOS, trial policies, ABC margin, safety stock, reserve, budget, confidence, shadow | calculation + optimizer | готово |
| OZ-4 review backend | фильтры, explain, aggregate, edits, bulk, audit, budget, compare | `OzonAutoSupplyPlanReviewTest` | готово |
| OZ-4 frontend | readiness/review/execution экраны в `placesales_front` | `OzonAutoPlanningWorkflow.test.tsx`, `autoSupplyPlanApi.ozonWorkflow.test.ts` | готово |
| OZ-5 validation/execution | capability, validate, approve, fingerprint, materialize, async draft/timeslot, retry, idempotency | materialization + `OzonSupplyDraftExecutionJobTest` + `OzonAutoSupplyPlanFreshnessGateTest` | готово; живой money-path не запускался |
| OZ-6 tracking/plan-fact | `/v3/supply-order/get`, quantities, WAPE, bias, OOS/excess/manual outcomes | reconciler/accuracy + `SyncSupplyStatusesJobTest` | готово |

Тесты, закрывшие дыры аудита 29 июля:

- `OzonSupplyDraftExecutionJobTest` — лимиты 2/мин и 50/час, повторный черновик,
  `manual_reconciliation_required` при потерянном ответе, отказ Ozon, авто-слот,
  запрет повтора без ручной сверки кабинета;
- `OzonAutoSupplyPlanCalculationPipelineTest` — сквозной прогон
  `CalculateAutoSupplyPlanJob`: кластерные строки, воспроизводимость, бюджет,
  effective params, «в пути» без задвоения;
- `OzonAutoSupplyPlanFreshnessGateTest` — `facts_snapshot_required`,
  блокирующие источники, `facts_changed_after_validation`;
- `SyncSupplyStatusesJobTest` — выборка активных поставок и изоляция по интеграции.

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

> Проверено на проде 29 июля 2026: ни `schedule:run`, ни воркер очередей
> `ozon-fact-refresh`/`ozon-supply-execution` для `products-backend` не настроены.
> Без них автопланирование задеплоено, но не работает: задачи копятся в очереди.
> Это блокирующий пункт release gates, а не опция.

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
