# Аудит логики Ozon-автопланирования — 2026-07-31

Полная трассировка пайплайна тремя независимыми ревизорами + ручная верификация ключевых находок.
Статусы: ✅ подтверждено по коду лично · 🔶 найдено ревизором, кросс-чек не делался · ❔ проверить на данных.

**ФИКСЫ 2026-07-31 (та же дата):** починены пункты 1–8 списка багов: экономика от qty; гард сплита для кластерных строк + деление денег детей; двухпроходные квоты лимитов + pack в capLine/needQty; slot_pending/at_warehouse в «в пути»; анти-дубль полей спроса при агрегации; EWMA sales14-фолбэк; budget_unpriced_lines в summary (строки без cost по-прежнему входят мимо бюджета — осознанно, теперь видимы); failed() у драфт-джоба. Тесты: +3 (утечка квоты, pack-кратность, EWMA), 670 зелёных. НЕ тронуто из ❔: turnover cap без safety, порядок лимиты→оптимизатор, FBS-фильтр, max(valid,available), тройной консервативный срез, двойной тренд, синтетические кандидаты для новых кластеров.

## Схема пайплайна (как есть)

1. **Кандидаты**: `inventory_warehouses` (FBO из `/v2/analytics/stock_on_warehouses` + FBS) → маппинг склад→кластер по нормализованному имени (`ozon_warehouse_clusters`, кэш 24ч) → `aggregateOzonWarehousesByCluster()` → одна строка SKU×кластер (`CalculateAutoSupplyPlanJob.php:157-170, 1962-2022`).
2. **Остатки**: SUM по кластеру, затем перезапись `max(valid_stock_count, available_stock_count)` из `/v1/analytics/stocks` (`:568-570`). **В пути**: `transit_stock_count` + до-отгрузочные заявки (анти-задвоение по флагу, `:571-589`).
3. **Спрос**: postings by_cluster → `shapeOzonPostingDemand` → `calculateDailyDemandV2` (real > effective(OOS) > EWMA > api_avg > 7д) → ×сезонность ×тренд ×калибровка → урезания (low_confidence_trial, промо, new_warehouse).
4. **Кол-во**: `target(ABC)×спрос + safety − остаток − в пути` → cap max_cover (+safety) → cap turnover (без safety) → pack ceil → protective guard → seller-stock cap.
5. **Пост-обработка**: cluster split (`LocalityEnrichmentService::applyClusterSplit`) → лимиты МП (`MarketplaceConstraintService`) → `skip_negative_profit` → бюджет-рюкзак (`PlanLineOptimizer`) → bulk insert строк.
6. **Workflow**: validate (фингерпринты плана/снапшота/фактов, сброс approve) → approve → materialize → execute (идемпотентность + unique-индекс на гонку, фраза `hash_equals`, fail-closed manual_reconciliation, rate-limit 2/мин 50/час) — крепкий.

## Подтверждённые баги (по убыванию тяжести)

1. ✅ **Экономика строки на смешанных базах** (`Job:1047-1052`): `expected_revenue = спрос×target_cover×цена` (НЕ зависит от qty), `supply_cost/logistics = ×qty`, хранение `×target_cover` (и `storage_cost_per_day` суммируется по складам кластера `:2004`). Прибыль/ROI строк несопоставимы; при большом остатке прибыль завышена. Все последующие капы (`PlanLineOptimizer:352-356`, `MarketplaceConstraintService:590-594,618-622`) масштабируют деньги линейно ×(newQty/oldQty), хотя revenue от qty не зависит → после любого капа экономика фиктивна. На ней стоят skip_negative_profit, бюджет и сортировки.
2. ✅ **Cluster split дублирует SKU×кластер и наследует чужие данные** (`LocalityEnrichmentService:227-250`, вызов `Job:~1438`): каждая кластерная строка-родитель делится по ОДНОМУ И ТОМУ ЖЕ набору рекомендованных кластеров SKU — при N родителях кластер попадает в план N раз; дедупа (sku, cluster_id) дальше нет. `$child = $line` наследует current_stock/in_transit/экономику родительского кластера без пересчёта (сумма стоимостей детей = N× родителя → отравляет economics_summary и бюджет). Pack для сплита — `default_pack_multiple` из настроек, а округление строки — pack из карточки: расхождение ломает кратность.
3. ✅ **Лимиты МП текут и рвут pack** (`MarketplaceConstraintService`): `capLine` (`:612+`) пишет allowedQty без pack-кратности (лимит 7 при pack 5 → неотгружаемые 7 шт); `applyMarketplaceNeedQty` (`:578-588`) поднимает qty ДО need_qty поверх всех капов и тоже без пака; квота `remainingByKey` списывается до проверки блокировки вторым ключом (`:114-122`) → лимит расходуется на выброшенные строки; лимиты применяются ДО отсечения убыточных и бюджета → квоту съедают строки, которые потом выкинут.
4. ✅ **«В пути» теряет статусы** (`Job:374,377,2206`): списки pending/shipped не включают `slot_pending` и `at_warehouse` (модель Supply считает их активными). `at_warehouse` — товар привезён, не принят: его нет ни в остатках, ни в transit_stock_count, ни в заявках → повторный заказ.
5. ✅ **Пробная партия в новый кластер недостижима**: для кластера без строки в `inventory_warehouses` кандидата нет вообще (синк удаляет всё, чего нет в API — `SyncInventoryJob:334-360`); когда строка есть, `new_warehouse` требует нулевых продаж → demandV2 даёт 0 (кроме лазейки `average_daily_sales`) → qty=0 → строка отсекается. Обходной путь только файл потребностей (`appendMarketplaceNeedCandidates`).
6. ✅ **EWMA-дыра**: `sales7=0, sales30=0, sales14>0` → ветка EWMA занята (`Service:585`), формула (`:54-71`) игнорирует sales14 → спрос 0 без пометки, фолбэки недостижимы → строка молча выпадает.
7. 🔶 **Бюджет обходится строками без себестоимости** (`PlanLineOptimizer:488-492`): cost<=0/null → «бесплатные», включаются мимо budget_limit; они же проходят skip_negative_profit (profit null → 0.0, `:56`).
8. ✅ **ExecuteOzonSupplyDraftJob без failed()**: после 20 tries поставка виснет в waiting_draft, execution — в running навсегда.

## ❔ Проверить на данных / обсудить

- Кластерная агрегация суммирует `average_daily_sales`/`real_avg_daily_sales`/`sales_*` (`Job:1995-2003`), которые синк порой пишет ОДНИМ значением на все склады (анти-дубль эвристика есть в `InventoryController:882-918`, в расчёте — нет) → спрос кластера ×N складов. `real_avg` при этом приоритетнее кластерного posting (`Job:626`).
- `shapeOzonAggregateDemand` никогда не возвращает 'good' (`Service:915`) → ЛЮБАЯ строка без кластерного posting-спроса получает low_confidence_trial: target ≤14 дн + safety ≤3×спрос + protective guard = тройной консервативный срез. Вероятная причина массовых «требуют проверки спроса» и маленьких qty.
- Тренд дважды: adjustDemandByTrend внутри V2 + внешний trend_multiplier; на падении — двойной haircut поверх пост-промо кэпа.
- `max(valid, available)` как остаток; `requested_stock_count` не используется.
- FBS-строки не фильтруются из кандидатов Ozon (WB/Yandex фильтруют).
- Строки без маппинга кластера: current_stock и «в пути» подменяются ГЛОБАЛЬНОЙ суммой по SKU (`Job:493-495, 586-588`) → при нескольких немаппленных строках потребность занижается кратно; в группе >1 строки лишние теряются молча (`:1979-1981`).
- `getOzonInTransitFromSuppliesByCluster` группирует по `supply_items.sku` — если там числовой SKU, а в остатках offer_id, весь «в пути» из заявок обнуляется; `catch (\Throwable) → []` глушит без лога.
- turnover cap (`Service:138-160`) без safety применяется ПОСЛЕ max_cover cap с safety → тихо срезает страховой запас при turnover_limit ≤ max_cover.
- Все капы применяются к float до pack-ceil → qty может превысить cap на pack−1.
- coefficient-строки файла лимитов только в explain, на выбор/экономику не влияют.
- days_in_stock_30 NOT NULL default 30 → ветка «дни неизвестны» и флаг oos_adjust_when_unknown_days мертвы; `max()` по кластеру занижает OOS.
- redemption_rate без нижнего клампа и применяется не только к Ozon.
- Метки demand_source затираются/комбинируются без маппинга в UI.

## Порядок починки (предложение)

1. Единая база экономики строки (всё от qty) + честный пересчёт после капов.
2. Дедуп (sku, cluster_id) после сплита + пересчёт остатков/экономики детей + единый pack.
3. Pack-восстановление и порядок применения лимитов (после отбора), фикс утечки квоты.
4. `at_warehouse`/`slot_pending` в «в пути».
5. Кластерная агрегация: анти-дубль полей спроса (MAX-эвристика как в InventoryController).
6. EWMA sales14, failed() у драфт-джоба, free-ride бюджета.
7. Продукт: ослабить тройной консервативный срез (aggregate shape 'good'), пробные партии в новые кластеры (синтетические кандидаты) — это же разблокирует Фазу-1 тумблер.
