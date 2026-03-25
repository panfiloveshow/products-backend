# Покрытие API маркетплейсов и бэклог улучшений

Обзор актуален по состоянию кода в `products-backend`: два слоя интеграции и разные джобы используют разные фабрики.

## Архитектура (важно)

| Поток | Фабрика | Реализации |
|--------|---------|------------|
| Синхрон **товаров** (`SyncProductsJob`) | `App\Services\Marketplace\MarketplaceFactory` | `OzonService`, `WildberriesService`, `YandexMarketService` |
| Синхрон **остатков** (`SyncInventoryJob`) | `App\Domains\Marketplace\MarketplaceFactory` | `OzonMarketplace`, `WildberriesMarketplace`, `YandexMarketMarketplace` |
| Автоплан / поставки | Доменные фасады + `AutoSupplyPlanService` (Ozon HTTP, WB `SuppliesApi` для in-transit) | см. ниже |

Риск: логика в **легаси-сервисах** и **Domains** может разъезжаться. Целевое направление — единый вход (домен) для всех джобов.

---

## Ozon

### Доменный фасад `OzonMarketplace` (основной для остатков и юнит-экономики)

Реализовано (неполный список по коду):

- Товары: `ProductsApi`
- Остатки: FBO по складам, FBS по складам (`getInventoryPerWarehouse`, `getInventoryFbsPerWarehouse`, `getDetailedInventory`)
- Продажи: по SKU, по SKU×склад, заказы/возвраты, аналитика (выкуп, эквайринг, локализация)
- Хранение и тарифы: `StorageApi`, отчёты
- Поставки: `SuppliesApi`, FBO supply orders / cargoes / postings, FBS postings/returns

Использование в `SyncInventoryJob`: FBO + FBS остатки, `getSalesBySkuAndWarehouse(30)` для `sales_*` / `avg_daily_sales`.

### Автоплан (`CalculateAutoSupplyPlanJob` + `AutoSupplyPlanService`)

- Кэшируемая **Delivery Analytics** и **Stock Analytics** Ozon (FBO)
- Якорь количества под рекомендации Ozon
- Данные из БД: `inventory_warehouses`, отчёт заказов Ozon (`OzonOrderReport`)

### Легаси `App\Services\Marketplace\OzonService`

Дублирует часть сценариев для синка **товаров**; эндпоинты в комментариях (`/v3/product/list`, аналитика, FBS stocks и т.д.).

---

## Wildberries

### Доменный фасад `WildberriesMarketplace`

- Карточки, цены: `ProductsApi`
- Остатки: `InventoryApi` (статистика складов + FBS склады продавца через chrtIds)
- Продажи: `SalesApi` (в т.ч. регионы/локализация)
- Отчёт реализации, хранение, комиссии: `StorageApi`, `RealizationReportApi`
- FBW поставки (чтение): `SuppliesApi` — список/детали/товары, склады, коэффициенты приёмки
- FBS заказы: `FbsSuppliesApi`

Использование в `SyncInventoryJob`: `getInventory()`; **`getSalesByWarehouse(30)`** — загрузка продаж по складам из Statistics API (`SalesApi::getSalesByWarehouse`, тот же формат, что и в легаси `WildberriesService`, чтобы заполнялись `sales_*` / `avg_daily_sales` в `inventory_warehouses`).

### Легаси `WildberriesService`

Используется синхроном **товаров**; содержит `getSalesByWarehouse`, `getFbsStocks` и др. Для остатков доменный `InventoryApi` уже подмешивает FBS.

### Автоплан

- Опционально: **в пути** по открытым поставкам через WB `SuppliesApi` (`include_wb_supplies_api_in_transit`)

---

## Яндекс Маркет

### Доменный фасад `YandexMarketMarketplace`

- `ProductsApi`, `InventoryApi` (остатки, склады), `SalesApi`
- Схемы в коде: FBY, FBS, DBS, EXPRESS

Использование в `SyncInventoryJob`: только `getInventory()` + при необходимости общая ветка без отдельных «продаж по складам» как у Ozon/WB.

### Легаси `YandexMarketService`

Синхрон товаров: offer mappings, остатки отдельно в `getInventory`.

**Пробел:** нет паритета с Ozon/WB по продажам per-warehouse в матрице остатков (если API ЯМ это позволяет — вынести в отдельную задачу).

---

## Приоритизированный бэклог

### P0 — согласованность и доверие к данным

1. **Свести синк товаров на доменную фабрику** (или явно задокументировать и заморозить различия легаси vs Domains).
2. **Проверить Ozon FBS:** в `SyncInventoryJob` для продаж используется только `getSalesBySkuAndWarehouse` — уточнить, покрывает ли FBS-склады; при необходимости добавить `getSalesBySkuAndWarehouseFbs` в джоб.
3. **Индекс / UI «паспорт данных»:** время последнего синка остатков/продаж, включённые режимы (FBO/FBS), флаги автоплана (WB supplies API, Ozon analytics).

### P1 — функциональность «как в ЛК»

4. **Яндекс Маркет:** продажи по складам/кампаниям в матрицу (если есть стабильный API-источник).
5. **WB:** отображать в UI ограничения FBW API (создание поставок только в ЛК) — уже описано в `SuppliesApi` PHPDoc, вынести в подсказки продукта.
6. **Ozon:** связка автоплан → черновик поставки уже есть в `SupplyService`; расширить сценарии (слоты, кластеры) по обратной связи клиентов.

### P2 — углубление API

7. Возвраты/движения как отдельный сигнал для прогноза (где API стабильны).
8. Лимиты и ретраи: централизованный учёт 429/квот в логах + статус интеграции для саппорта.

---

## Связанные документы

- `docs/auto-supply-planning/data-mapping.md`
- `docs/OZON_SUPPLIES_MODULE.md`, `docs/SUPPLIES_MODULE.md`
- `docs/ARCHITECTURE_REFACTORING.md`
