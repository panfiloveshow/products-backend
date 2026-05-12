# Frontend Recovery Checklist

Дата ревизии: 2026-05-12
Проект: `products-backend`
Источник истины: `routes/api.php`, `php artisan route:list --path=api`, контроллеры и FormRequest.

## 0. Главный вывод

Внутри backend-репозитория полноценного фронта сейчас нет:

- `routes/web.php` открывает только стандартную welcome-страницу Laravel.
- `resources/js/app.js` импортирует только `bootstrap.js`.
- `resources/js/bootstrap.js` только кладет `axios` в `window`.
- Нет React/Vue/Inertia страниц, layout, router, компонентов, таблиц, форм.

Фронт нужно проверять/восстанавливать как отдельное API-приложение поверх Laravel API.

Важно: в коде есть `SupplyController`, `PostingController`, `SupplyPackageController`, `SupplyDocumentController`, но активных `/api/supplies...` и `/api/postings...` роутов сейчас нет в `route:list`. Не строить фронт по этим контроллерам, пока backend явно не подключит роуты. Рабочие поставочные зоны сейчас: `/api/auto-supply-plans` и `/api/shipments`.

## 1. Общий API-клиент

Проверить в первую очередь:

- Все защищенные запросы отправляют `Authorization: Bearer <token>`.
- Также отправляют workspace header: `X-Sellico-Workspace: <workspace_id>` или `X-Workspace-Id: <workspace_id>`.
- Для совместимости можно дублировать `X-Sellico-Token: <token>`, потому что middleware его тоже читает.
- `integration_id` передается в query/body везде, где экран работает с магазином.
- `marketplace` нормализуется к `wildberries`, `ozon`, `yandex_market`. Старое `yandex` использовать только там, где backend явно разрешает.
- Ошибки показываются единообразно:
  - `401`: нет/протух токен, отправить на login.
  - `403`: нет permission или чужая интеграция, показать запрет действия.
  - `422`: подсветить поля формы или бизнес-ограничение.
  - `503`: внешний Sellico/CRM недоступен, показать retry.

Фронтовые системные роуты:

- `/login`
- `/workspaces`
- `/dashboard`
- `/settings/integrations`

API:

- `POST /api/auth/login`
- `GET /api/auth/me`
- `GET /api/auth/workspaces`
- `GET /api/auth/workspaces/{workspaceId}/integrations`
- `GET /api/workspaces/{workspace}/limits-external`
- `POST /api/workspaces/{workspace}/limits-external`
- `PUT /api/workspaces/{workspace}/limits-external/sync`

Ожидаемое поведение:

- После login сохранить `access_token`, пользователя, список workspaces.
- Если workspace один, выбрать его автоматически.
- После выбора workspace загрузить интеграции.
- Пока нет workspace/integration, бизнес-разделы не должны делать пустые запросы без контекста.

## 2. Интеграции

Фронтовые роуты:

- `/settings/integrations`
- `/settings/integrations/:integrationId`

API:

- `GET /api/integrations?marketplace=&is_active=`
- `GET /api/integrations/{id}/status`
- `GET /api/integrations/{id}/premium-status`
- `PUT /api/integrations/{id}/manual-redemption-rate`
- `GET /api/integrations/{id}/sync-status`
- `GET|POST /api/integrations/{id}/sync`

Что должно работать:

- Таблица интеграций: название, marketplace, active, premium, validation status, last sync status.
- Фильтры по marketplace и active.
- Кнопка sync запускает `/sync`, затем polling `/sync-status`.
- Для non-premium Ozon доступна ручная настройка `redemption_rate 0..100`.
- Для premium ручной redemption rate скрыт/disabled; backend вернет `403`.
- Показывать `recent_syncs`: `pending`, `running`, `completed`, `failed`.

## 3. Товары

Фронтовые роуты:

- `/products`
- `/products/:id`
- `/products/new`
- `/products/:id/edit`

API:

- `GET /api/products`
- `GET /api/products/{id}`
- `POST /api/products`
- `PUT /api/products/{id}`
- `DELETE /api/products/{id}`
- `POST /api/products/sync/{marketplace}`
- `GET /api/products/sync/status`

Query для списка:

- `integration_id` обязателен.
- `search`, `marketplace`, `category`, `brand`, `price_from`, `price_to`, `in_stock`.
- `page`, `limit` до 200.
- `sort`: `name`, `price`, `stock`, `rating`, `created_at`.
- `sort_order`: `asc`, `desc`.

Что должно работать:

- Таблица товаров с пагинацией, поиском, фильтрами, сортировками.
- Карточка товара с остатками, юнит-экономикой, alert-ами.
- CRUD товара с валидацией.
- Sync по marketplace: `wildberries`, `ozon`, `yandex_market`.
- Если есть `integration_id`, credentials не спрашивать.
- Empty state: нет товаров -> CTA "Синхронизировать товары".
- После sync polling статуса и обновление списка.

## 4. Себестоимость

Фронтовые роуты:

- `/products/cost-prices`

API:

- `GET /api/products/cost-price?integration_id=&page=&per_page=`
- `POST /api/products/cost-price/upload`
- `POST /api/products/cost-price/bulk`
- `GET /api/products/cost-price/template?marketplace=&integration_id=`

Что должно работать:

- Таблица себестоимостей по SKU.
- Bulk edit: `items[{sku,cost_price}]`, до 1000 SKU.
- Upload CSV/XLSX/TXT через multipart `file`.
- Download template.
- Ошибки парсинга файла показывать как validation/error banner.
- После успешного обновления обновлять Unit Economics/товары, если экран рядом открыт.

## 5. Остатки и складская аналитика

Фронтовые роуты:

- `/inventory`
- `/inventory/matrix`
- `/inventory/:sku`
- `/inventory/:sku/history`
- `/inventory/:sku/forecast`

API:

- `GET /api/inventory`
- `GET /api/inventory/{sku}`
- `GET /api/inventory/{sku}/history`
- `GET /api/inventory/{sku}/forecast`
- `GET /api/inventory/alerts`
- `GET /api/inventory/matrix`
- `GET /api/inventory/recommendations`
- `GET /api/inventory/redistribution`
- `GET /api/inventory/stats`
- `POST /api/inventory/sync/{marketplace}`
- `POST /api/inventory/sync-storage-fees`
- `GET /api/inventory/sync/status`

Query для списка:

- `integration_id`, `marketplace`, `search`, `category`.
- `low_stock`, `out_of_stock`.
- `sort`: `sku`, `name`, `internal_stock`, `marketplace_stock`, `sales_28_days`, `days_of_stock`.
- `page`, `limit`.

Что должно работать:

- Список остатков по SKU и складам.
- Matrix view с колонками складов/кластеров.
- Alert/recommendation/redistribution виджеты.
- Sync остатков требует integration access.
- `sync-storage-fees` только для WB integration.
- Empty state: нет остатков -> CTA "Синхронизировать остатки".

## 6. Unit Economics

Фронтовые роуты:

- `/unit-economics/:marketplace`
- `/unit-economics/:marketplace/:sku`
- `/unit-economics/:marketplace/calculator`
- `/unit-economics/settings`

API:

- `GET /api/unit-economics/{marketplace}`
- `GET /api/unit-economics/{marketplace}/{sku}`
- `PUT /api/unit-economics/settings/{sku}`
- `PUT /api/unit-economics/settings/bulk`
- `POST /api/unit-economics/recalculate/{integrationId}`
- `GET /api/unit-economics/cache-stats/{integrationId}`
- `GET /api/unit-economics/freshness/{integrationId}`
- `POST /api/unit-economics/calculate/{marketplace}`
- `GET /api/unit-economics/comparison`
- `GET /api/unit-economics/stats`
- `GET /api/unit-economics/stats/{marketplace}`
- `GET /api/unit-economics/commissions/{marketplace}`
- `GET /api/unit-economics/tariffs/{marketplace}`
- `GET /api/unit-economics/{marketplace}/export/excel`

Query для основного списка:

- `integration_id` обязателен.
- `fulfillment_type` обязателен: `FBO`, `FBS`, `RFBS`, `EXPRESS`, `DBS`, `EDBS`, `DBW`, `MIXED`, `FBY`.
- `search`, `profitable`, `margin_min`, `margin_max`, `price_min`, `price_max`.
- `sort`: `sku`, `product_name`, `price`, `net_profit`, `margin_percent`, `commission_percent`, `stock`, `total_stock`, `current_stock`, `days_of_stock`.
- `limit` до 500, `page`.

Что должно работать:

- Таблица с tabs по fulfillment scheme.
- Использовать `scheme_counts`, `actual_scheme`, `default_scheme`.
- Детальная карточка SKU: `data`, `settings`, `all_schemes`.
- Если `404 Unit economics not found`, показать CTA "Запустить синхронизацию/пересчет".
- Настройки SKU: `cost_price`, `drr_percent`, `our_share_percent`, `tax_percent`, `vat_percent`, `redemption_rate_override`, `spp_percent`, `localization_index`.
- Bulk settings до 100 items.
- Recalculate запускает пересчет, после него polling freshness/cache.
- `freshness` поллить каждые 2-3 секунды во время sync/recalculate; показывать этапы `products`, `inventory`, `unit_economics`, `locality`.
- Export Excel скачивает файл.

## 7. Автопланы поставок

Фронтовые роуты:

- `/auto-supply-plans`
- `/auto-supply-plans/new`
- `/auto-supply-plans/:id`
- `/auto-supply-plans/:id/lines`
- `/auto-supply-plans/:id/locality`
- `/auto-supply-plans/:id/exports`

API:

- `GET /api/auto-supply-plans`
- `POST /api/auto-supply-plans`
- `GET /api/auto-supply-plans/{id}`
- `DELETE /api/auto-supply-plans/{id}`
- `POST /api/auto-supply-plans/{id}/calculate`
- `GET /api/auto-supply-plans/{id}/lines`
- `PUT /api/auto-supply-plans/{id}/lines/{lineId}`
- `GET /api/auto-supply-plans/{id}/simulate`
- `GET /api/auto-supply-plans/warehouses`
- `GET /api/auto-supply-plans/data-health`
- `GET /api/auto-supply-plans/{id}/export/ozon`
- `GET /api/auto-supply-plans/{id}/export/ozon-matrix`
- `GET /api/auto-supply-plans/{id}/export/ozon-by-warehouse`
- `GET /api/auto-supply-plans/{id}/export/wb`
- `GET /api/auto-supply-plans/{id}/locality-impact`
- `GET /api/auto-supply-plans/{id}/cluster-split`
- `GET /api/auto-supply-plans/{id}/locality-recommendations`
- `GET /api/auto-supply-plans/{id}/cluster-draft-preview`
- `POST /api/auto-supply-plans/{id}/create-cluster-drafts`
- `POST /api/auto-supply-plans/from-locality-recommendations`
- `POST /api/auto-supply-plans/preview-split-by-cluster`

Create body:

- `integration_id` обязателен.
- `mode`: `anti_oos`, `balanced`, `cash_safe`.
- `horizon_days`: `14`, `30`, `60`, `90`.
- `min_cover_days`, `target_cover_days`, `max_cover_days`, `safety_stock_days`, `turnover_limit_days`, `budget_limit`, `lead_time_days`.
- `warehouse_ids[]`, `cluster_ids[]`.
- Ozon options: `ozon_qty_anchor`, `demand_seasonality_multiplier`, `skip_negative_profit`.
- Locality options: `split_by_cluster`, `minimum_locality_confidence`, `include_locality_recommendations`, `locality_distribution_strategy`.

Что должно работать:

- Для Ozon нельзя создать план без `cluster_ids`: backend вернет `422 ozon_cluster_required`.
- После создания статус `pending`; нужен polling карточки до `ready` или `error`.
- Статусы плана: `pending`, `calculating`, `ready`, `error`.
- Lines агрегируются по SKU, для Ozon по SKU+cluster.
- `PUT line qty_rounded` разрешен только при `ready`, иначе `422`.
- Export доступен только при `ready`.
- Locality вкладка показывает impact, cluster split, рекомендации.
- Cluster draft preview ничего не создает, create вызывает Ozon draft создание и может вернуть частичные errors.

## 8. Shipments

Фронтовые роуты:

- `/shipments`
- `/shipments/new`
- `/shipments/:id`
- `/shipments/:id/items`
- `/shipments/:id/logistics`

API:

- `GET /api/shipments`
- `POST /api/shipments`
- `GET /api/shipments/{id}`
- `PUT /api/shipments/{id}`
- `DELETE /api/shipments/{id}`
- `POST /api/shipments/{id}/items`
- `PUT /api/shipments/{id}/items/{itemId}`
- `DELETE /api/shipments/{id}/items/{itemId}`
- `POST /api/shipments/{id}/submit`
- `POST /api/shipments/{id}/approve`
- `POST /api/shipments/{id}/reject`
- `POST /api/shipments/{id}/send`
- `POST /api/shipments/{id}/deliver`
- `GET /api/shipments/slots`
- `POST /api/shipments/{id}/book-slot`
- `GET /api/shipments/{id}/export/pdf`
- `GET /api/shipments/{id}/export/csv`
- `GET /api/shipments/recommendations`
- `POST /api/shipments/from-recommendation/{recommendationId}`
- `GET /api/shipments/stats`

Что должно работать:

- CRUD поставок и управление item-ами.
- Создание: `name`, `marketplace`, `shipment_type`, `supplier_id`; items optional.
- `marketplace`: `wildberries`, `ozon`, `yandex`.
- `shipment_type`: `fbo`, `fbs`, `dbs`.
- Workflow:
  - `draft -> submit -> pending_logistics`.
  - `pending_logistics -> approve -> approved`.
  - `pending_logistics -> reject -> rejected`.
  - `approved -> send -> sent`.
  - `sent/in_transit -> deliver -> delivered`.
- Неправильные действия по статусам должны быть hidden/disabled; backend вернет `422`.
- Export PDF/CSV возвращает URL/JSON, не stream.

## 9. Suppliers

Фронтовые роуты:

- `/suppliers`
- `/suppliers/:id`
- `/suppliers/new`
- `/suppliers/:id/edit`

API:

- `GET /api/suppliers`
- `POST /api/suppliers`
- `GET /api/suppliers/{id}`
- `PUT /api/suppliers/{id}`
- `DELETE /api/suppliers/{id}`

Что должно работать:

- Таблица поставщиков.
- CRUD формы: `name`, `address`, `phone`, `email`, `contact_person`, `metadata`.
- Удаление поставщика с привязанными поставками может дать `422`; показывать понятное сообщение.

## 10. Seller Stocks

Фронтовые роуты:

- `/seller-stocks`
- `/seller-stocks/catalog`
- `/seller-stocks/summary`

API:

- `GET /api/seller-stocks`
- `GET /api/seller-stocks/summary`
- `GET /api/seller-stocks/catalog`
- `POST /api/seller-stocks`
- `POST /api/seller-stocks/bulk`
- `DELETE /api/seller-stocks/{id}`

Что должно работать:

- Везде нужен `integration_id`.
- Upsert: `sku`, `quantity`, `reserved`, `cost_price`, `location`, `note`.
- Bulk import/edit.
- Summary cards: общий stock, reserved, available, cost.

## 11. WB Barcode Costs

Фронтовые роуты:

- `/wb/barcode-costs`

API:

- `GET /api/wb-barcode-costs`
- `POST /api/wb-barcode-costs/bulk`
- `DELETE /api/wb-barcode-costs`

Что должно работать:

- Таблица себестоимости по WB barcode.
- Bulk: `integration_id`, `items[{barcode,nm_id,cost_price,chrt_id,size_name}]`.
- Delete может принимать фильтры/body; проверить текущую реализацию перед финальной UI-логикой.

## 12. Ozon Reports

Фронтовые роуты:

- `/ozon-reports`
- `/ozon-reports/:id`
- `/ozon-reports/warehouse-sales`

API:

- `GET /api/ozon-reports`
- `POST /api/ozon-reports/upload`
- `GET /api/ozon-reports/summary`
- `GET /api/ozon-reports/warehouse-sales`
- `DELETE /api/ozon-reports/{id}`

Что должно работать:

- List последних отчетов по `integration_id`.
- Upload multipart: `integration_id`, `file` CSV/TXT/XLSX/XLS до 50MB.
- Summary по `report_id`.
- Warehouse sales: `integration_id`, optional `report_id`, `sku`.
- Delete откатывает report-derived поля в inventory; нужно confirmation modal.

## 13. Locality Engine

Фронтовые роуты:

- `/locality`
- `/locality/skus`
- `/locality/skus/:sku`
- `/locality/clusters`
- `/locality/recommendations`
- `/locality/recommendations/:id`
- `/locality/reconciliation`

API:

- `GET /api/v1/locality/overview`
- `GET /api/v1/locality/skus`
- `GET /api/v1/locality/clusters`
- `GET /api/v1/locality/sku/{sku}/explain`
- `POST /api/v1/locality/sku/{sku}/counterfactual`
- `GET /api/v1/locality/explain`
- `POST /api/v1/locality/counterfactual`
- `GET /api/v1/locality/recommendations`
- `GET /api/v1/locality/recommendations/{id}`
- `POST /api/v1/locality/recommendations/{id}/dismiss`
- `POST /api/v1/locality/recommendations/{id}/draft/preview`
- `POST /api/v1/locality/recommendations/{id}/draft/create`
- `GET /api/v1/locality/reconciliation`
- `POST /api/v1/locality/recompute`

Что должно работать:

- Только Ozon integration; для других marketplace backend вернет `422`.
- Overview: период `7` или `28`, optional `as_of`.
- SKUs: `sort`: `overpayment`, `lost_margin`, `local_share_asc`, `orders`, `revenue`; `limit` до 1000, `offset`.
- Clusters: `sort`: `overpayment`, `orders`, `local_share_asc`, `revenue`.
- Explain поддерживает path SKU и query SKU; для SKU со slash лучше использовать query route `/explain?sku=`.
- Counterfactual: `target_cluster_id`, `hypothetical_qty`.
- Recommendations:
  - states: `new`, `dismissed`, `applied`, `superseded_by_supply`, `stale`, `expired`.
  - filters: `cluster_id`, `min_savings`, `confidence`, `sort`.
  - dismiss/apply только для `new`, иначе `422`.
- Recompute dispatch jobs: `scope=aggregation|recommendations|reconciliation|ingestion|all`, `period_days=7|28`.

## 14. WB Webhooks

Фронтовые роуты:

- `/wb/webhook`

API:

- `GET /api/wb-webhook/status`
- `POST /api/wb-webhook/register`
- `POST /api/wb-webhook/deactivate`
- `POST /api/wb-webhook/receive/{integrationId}` public backend endpoint, не UI-call.

Что должно работать:

- Status по `integration_id`.
- Register: `integration_id`, `webhook_url`; backend проверяет SSRF и доступ.
- Deactivate: `integration_id`. Важно: route сейчас без `sellico.permission`; фронт все равно должен слать токен/workspace.
- Receive не дергать из UI, это endpoint для WB.

## 15. Навигация и приоритет восстановления

P0:

- Auth, workspace, integration selector.
- Общий API client с token/workspace/integration context.
- Products list + sync.
- Inventory list/matrix + sync.
- Unit Economics list/detail/settings/freshness.
- Auto Supply Plans list/create/detail/lines/export.

P1:

- Cost prices upload/bulk/template.
- Integrations status/sync/premium/redemption.
- Locality overview/SKUs/clusters/recommendations.
- Ozon reports upload/summary/warehouse-sales.
- Shipments CRUD/workflow.

P2:

- Suppliers CRUD.
- Seller stocks.
- WB barcode costs.
- WB webhook settings.
- Workspace external limits admin-like screen.

## 16. Что проверить вручную после правок фронта

- Login -> выбрать workspace -> выбрать integration -> перейти в каждый бизнес-раздел без 401/403.
- Для каждого списка: loading, empty, error, pagination, filters, sort.
- Для каждого mutation: validation errors 422, success toast, refetch.
- Для sync/recalculate/auto-plan calculate: кнопка запуска, disabled state, polling, final success/error.
- Для export/download: корректная обработка stream/file и JSON URL.
- Для Ozon-only экранов: не показывать Locality для WB/Yandex; если открыли прямым URL, показать friendly 422.
- Для старых `/api/supplies` и `/api/postings`: не использовать до появления routes в `php artisan route:list`.

