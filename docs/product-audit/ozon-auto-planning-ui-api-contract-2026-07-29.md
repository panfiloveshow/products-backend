# UI/API контракт автопланирования Ozon

Дата: 29 июля 2026 года.

Назначение: готовый контракт для frontend-репозитория. Пользователю не требуется читать `result_json` или `explain_json`.

## 1. Экран готовности

Запросы:

```text
GET  /api/auto-supply-plans/data-health?integration_id={id}
POST /api/auto-supply-plans/refresh-data
GET  /api/auto-supply-plans/capabilities?integration_id={id}
```

Refresh body:

```json
{"integration_id": 17}
```

Показывать:

- общий `status`, `can_calculate`, `can_approve`;
- карточки `credentials`, `products`, `inventory`, `demand`, `economics`, `constraints`, `supplies`;
- для карточки: `status`, `fetched_at`, `age_hours`, `sla_hours`, `coverage_percent`, `items`, `last_error`, `message`;
- `blocking_errors` отдельно от `warnings`;
- `sync_progress.percentage` и семь stages.

Кнопка расчёта недоступна при `can_calculate=false`.

## 2. Создание плана

```text
POST /api/auto-supply-plans
```

Минимальный body:

```json
{
  "integration_id": 17,
  "planning_mode": "balanced",
  "analysis_period_days": 56,
  "horizon_days": 28,
  "min_cover_days": 7,
  "target_cover_days": 21,
  "max_cover_days": 42,
  "lead_time_days": 7,
  "budget_limit": 500000,
  "cluster_ids": [154, 149],
  "draft_supply_method": "direct",
  "include_in_transit": true,
  "skip_negative_profit": true
}
```

Допустимые режимы:

```text
anti_oos | balanced | cash_safe | protect_oos |
improve_locality | max_profit | post_promo_careful
```

Дополнительные controls: `seasonality_multiplier`, `trend_multiplier`, `promo_mode`, `safety_stock_days`, `turnover_limit_days`, `use_auto_constraints`, crossdock drop-off point.

## 3. Review

```text
GET /api/auto-supply-plans/{id}
GET /api/auto-supply-plans/{id}/lines
GET /api/auto-supply-plans/{id}/compare?changed_only=1
GET /api/auto-supply-plans/{id}/adjustments
```

Строка UI — агрегат `SKU × cluster_id`. Основные поля:

```text
sku, offer_id, product_name, cluster_id, cluster_name,
current_stock, in_transit, demand_daily, oos_date,
cover_days_before, cover_days_after,
qty_rounded, candidate_quantity,
supply_cost_estimate, expected_profit, roi_percent,
confidence_level, review_status, not_recommended_reason,
quantity_explanation, manually_modified
```

Фильтры:

```text
urgent_oos=1
blocked=1
low_confidence=1
new_product=1
missing_cost=1
negative_profit=1
budget_cut=1
manually_modified=1
cluster_id={id}
risk_level={level}
search={text}
```

`review_status`:

- `recommended` — можно оставить;
- `review` — требуется решение пользователя;
- `blocked` — в исполнение не попадёт без ручного изменения.

Редактирование:

```text
PATCH /api/auto-supply-plans/{plan}/lines/{line}
```

```json
{
  "qty_rounded": 24,
  "is_excluded": false,
  "cluster_id": 154,
  "comment": "Проверено менеджером",
  "reason": "Учитываем локальную акцию"
}
```

Bulk:

```text
POST /api/auto-supply-plans/{id}/lines/bulk
```

Actions:

```text
exclude | include | set_quantity | multiply_quantity |
minimum_quantity | maximum_quantity
```

После любой ручной правки UI обязан показать новый бюджет и статус `REVIEW_REQUIRED`; прежние validate/approve аннулируются.

## 4. Validation и approval

```text
POST /api/auto-supply-plans/{id}/validate
POST /api/auto-supply-plans/{id}/approve
```

На validation показывать `errors`, `warnings`, `groups`, `facts`. Кнопка approval доступна только при `allowed=true`.

Блокирующие примеры:

```text
facts_snapshot_required
destination_mapping_incomplete
cluster_unavailable
facts_credentials_expired
facts_inventory_stale
ozon_sku_unresolved
quality_gate_blocked
drop_off_point_required
```

## 5. Исполнение

Без внешнего вызова:

```text
POST /api/auto-supply-plans/{id}/materialize-supplies
GET  /api/auto-supply-plans/{id}/execution
```

Создание Ozon drafts:

```text
POST /api/auto-supply-plans/{id}/execute
```

```json
{
  "idempotency_key": "plan-{id}-revision-{fingerprint}",
  "confirmation_text": "СОЗДАТЬ ПОСТАВКИ OZON",
  "auto_book_timeslot": false
}
```

Рекомендуемый UI:

1. Сначала materialize и показать группы/количества.
2. Показать финальное предупреждение о внешнем действии.
3. Требовать точную фразу.
4. Сгенерировать один неизменный idempotency key на попытку.
5. После `202` polling `GET /execution`.
6. Для `manual_reconciliation_required` запретить автоматический retry.

Retry после проверки кабинета:

```text
POST /api/auto-supply-plans/{plan}/executions/{execution}/retry
```

```json
{"confirmed_no_external_draft": true}
```

## 6. Tracking и план-факт

```text
GET /api/auto-supply-plans/{id}/execution
GET /api/auto-supply-plans/{id}/plan-fact
```

Tracking показывает supply status, draft/order IDs, slot, execution step и error.

Plan-fact показывает:

- WAPE и weighted bias;
- forecast/actual по строкам;
- planned/accepted/rejected и acceptance rate;
- OOS days и excess cover;
- результат ручных изменений;
- причины расхождения.

## 7. Обработка ошибок

- `409` — версия изменилась, уже материализована или не выполнены canonical prerequisites;
- `422` — данные/кластер/quality/validation/confirmation не позволяют продолжить;
- `202` — операция поставлена в очередь;
- `200` с `idempotent=true` — повтор вернул существующую операцию, это нормальный success.

Frontend не должен автоматически повторять POST money-path после timeout. Нужно обновить `execution`; если remote state неоднозначен — показать ручную сверку кабинета.
