# Per-SKU CPC (клики / CTR / ДРР) в Ozon Performance API — диагноз и решение

Статус: **РЕШЕНО и проверено живым вызовом** (integration 13, период 2026-05-04…2026-06-02).
Результат после фикса: `source=async_report`, 181 товарная CPC-строка, 178 сопоставлено, **53 товара с реальными clicks/CTR/ДРР**.

## Симптом
У товаров рекламные `клики = 0`, `CTR = 0.00%`, `ИСТОЧНИК CPC = FALLBACK`, `СРС связано 0/0`.
Диагностика на дашборде: «CSV ответ не содержит товарных строк … JSON вернул агрегаты кампаний без товарного SKU».

## Root cause (подтверждён)
`fetchProductCampaignStats()` тянул per-SKU статистику из **синхронного** `GET /api/client/statistics/campaign/product`
(+`/json`). На этом аккаунте он отдаёт агрегаты уровня кампании **без товарного SKU** → маппингу нечего
сопоставлять → FALLBACK, нули.

Per-SKU клики/CTR/ДРР в Ozon Performance API отдаются **только асинхронным товарным отчётом**:
1. `POST /api/client/statistics` — тело `{ "campaigns": [...], "from": "...Z", "to": "...Z", "groupBy": "NO" }` → `{ "UUID": ... }`.
2. поллинг `GET /api/client/statistics/{UUID}` до `state == "OK"` → поле `link`.
3. `GET {link}` → **ZIP** (`application/zip`), один CSV на кампанию.

Реальная схема CSV (разделитель `;`, BOM; первая строка — название кампании; в конце `Корректировка`/`Всего`):
```
sku;Название товара;Цена товара, ₽;Показы;Клики;CTR, %;В корзину;Средняя стоимость клика, ₽;Расход, ₽, с НДС;Заказы;Продажи, ₽;Заказы модели;Продажи с заказов модели, ₽;ДРР, %;Заказано на сумму, ₽;Общий ДРР, %;Дата добавления
```
Важно: в CPC-отчёте есть **только `sku` (Ozon SKU), без Артикула** → маппинг на товар идёт по Ozon SKU
(через product-report SKU-map и локальную карту товаров).

### Два операционных ограничения Ozon (выявлены живыми вызовами)
- **≤10 кампаний в одном запросе** `POST /api/client/statistics` — больше даёт `HTTP 400`. → чанкуем по 10.
- **Только товарные кампании.** Нетоварные типы (`REF_VK`, `SEARCH_PROMO`, баннеры) ломают генерацию
  товарного отчёта (400). На аккаунте 46 кампаний: `SKU`×40, `ALL_SKU_PROMO`×1, `SEARCH_PROMO`×1, `REF_VK`×4.
  → фильтр `advObjectType содержит "SKU"`.
- **Rate limit 429** при частой генерации → бэкофф (до 3 попыток, sleep 5·n).

## Что изменено (app/Services/Ozon/OzonPerformanceApiService.php)
- `fetchProductCampaignStats()` — PRIMARY: асинхронный товарный отчёт; синхронный путь оставлен FALLBACK.
- Новые методы: `fetchProductStatsViaAsyncReport()` (чанк по 10), `generateCampaignStatsReport()`
  (POST + бэкофф на 429, connectTimeout 15 / timeout 40), `awaitCampaignStatsReport()` (поллинг),
  `downloadCampaignStatsReportRows()` (скачивание + распаковка ZIP), `extractCsvFromZip()`, `isProductCampaign()`.
- `productAdvertisingImpact()` — список кампаний фильтруется `isProductCampaign()` перед запросом отчёта.
- `parseCampaignProductCsvRows()` — пропуск строки `Корректировка`.
- `normalizeCampaignProductCsvHeader()` — «Продажи с заказов модели» больше не перетирает «Продажи».
- `source = 'async_report'` — новый источник CPC (вместо csv/json/fallback) для дашборда.

Сопутствующее (диагностика, не влияет на прод-путь):
- `debugCampaignProductRaw()` + `app/Console/Commands/DebugOzonCpcCommand.php` (`ozon:debug-cpc`).
- `IntegrationController::performanceAdvertisingImpact()` — опциональный `?debug_raw=1`.

## Производительность / эксплуатация
Async-путь синхронно генерирует и поллит несколько отчётов (по 10 кампаний). При 41 товарной кампании это
~5 отчётов → первый запрос может занять минуты.

Реализован кэш: `cachedProductCampaignStats()` кладёт удачный per-SKU результат в `Cache`
(ключ `ozon_perf_cpc:{integrationId}:{dateFrom}:{dateTo}`, TTL 30 мин). Повторные открытия страницы
отдаются мгновенно; в `coverage.campaign_stats_from_cache` видно, что ответ из кэша. Пустой/ошибочный
ответ не кэшируется (чтобы не залипнуть на 429). Дальнейшее улучшение — прогрев кэша из очереди/джоба
(рядом уже есть `UnitEconomicsCache` / `SyncUnitEconomicsCommand`).

`coverage.campaign_stats_source_error` теперь заполняется только когда товарных строк не получили вовсе —
частичные сбои отдельных чанков при успехе не выводятся в «Диагностику CPC» на дашборде.

## Проверка
```bash
# напрямую (нужна локальная БД для integrationId-маппинга; без БД — integrationId=null):
php artisan tinker  # productAdvertisingImpact(...) → coverage.campaign_stats_source == 'async_report'

# или live-эндпоинт с диагностикой:
GET /api/integrations/{id}/performance-reports/{uuid}/advertising-impact?date_from=2026-05-04&date_to=2026-06-02&debug_raw=1
```
