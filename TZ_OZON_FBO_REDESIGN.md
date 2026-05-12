# ТЗ: Перепроектирование автопланирования Ozon FBO под модель 2026

**Дата составления:** 07.05.2026
**Автор:** AI-аудит существующего кода
**Цель:** Алгоритм должен реально использовать локальность кластеров при расчёте qty, чтобы Locality Impact показывал ненулевой uplift, а основная таблица плана для Ozon была организована по (SKU × кластер), а не (SKU × склад).

---

## 0. Контекст: что не так сейчас

С 06.04.2026 Ozon отменил Индекс Локализации и СВД. Вместо этого — **наценка 0–12% на каждый нелокальный заказ**. Продавец в FBO физически **не управляет складами Ozon**, он управляет только тем, **в какой кластер отгрузить новую поставку**. Между складами Ozon продавец перемещать товар не может.

В текущей реализации (репо `products-backend`) автоматическое планирование:

1. **Итерируется по `inventory_warehouse_stocks` rows** — то есть по парам (SKU, склад Ozon).
2. **Для каждой пары считает свой `daily_demand`** из warehouse-уровневых полей `sales_7_days`, `sales_30_days`, `real_avg_daily_sales` (см. `CalculateAutoSupplyPlanJob::calculate()` строки 490–530).
3. **Считает `needed_qty` отдельно для каждого склада**: `target_cover × daily_demand_warehouse - current_stock - in_transit`.
4. **Cluster split применяется ПОСЛЕ расчёта** (строки 884–982), просто перераспределяя уже посчитанный `qty_rounded` по весам из `LocalityRecommendation` или Ozon recommended_supply (`LocalityEnrichmentService::resolveSplitWeights`).
5. **Locality Impact uplift** (`LocalityEnrichmentService::buildPlanSummary`) считается из поля `expected_local_share_after_pp` каждой строки плана, которое заполняется суммой `expected_local_share_uplift_pp` рекомендаций. Если рекомендаций нет или они не попали в split — uplift = 0.

### Симптомы в проде

- «Влияние плана на локальность»: `current=51.9%`, `after=51.9%`, `uplift=0пп`, `coverage=0%` при наличии 2+ активных Locality-рекомендаций и переплате 7-43k ₽ по кластерам.
- «Матрица перераспределения»: предлагает переместить SKU `5862/black1` из СПб→МСК 3шт + МСК→СПб 2шт одновременно. Это **физически невозможно** для Ozon FBO и было результатом наивного двойного цикла `for deficit × for surplus` (уже исправлено патчем 06.05.2026, см. ниже «Уже сделано»).
- В UI «Кластеры» — отдельная вкладка, которая дублирует основную таблицу. Юзер не видит, что cluster split — это и есть главный артефакт Ozon-плана.

### Архитектурный корень

**Алгоритм мыслит складами**, а Ozon FBO 2026 **мыслит кластерами**. Уровень склада в плане Ozon — артефакт устаревшей логики (когда продавец действительно отгружал на конкретный warehouse). Сейчас кластер — единица решения.

---

## 1. Что уже сделано (фронт + минимальные backend-патчи 06.05.2026)

### Frontend
- `CreateAutoSupplyPlanRequest` (тип) расширен полями `include_locality_recommendations`, `locality_distribution_strategy`. См. `frontend/src/services/autoSupplyPlanApi.ts:420-421`.
- `AutoSupplyPlansPage::handleCreate` для Ozon **по умолчанию** шлёт:
  ```
  split_by_cluster: true
  include_locality_recommendations: true
  locality_distribution_strategy: 'recommendations'
  minimum_locality_confidence: 'medium'
  ```
- В `LocalityImpactSection.tsx` добавлен баннер: «План не учёл N рекомендаций — пересоздайте».

### Backend (УЖЕ ИЗМЕНЕНО в этом репо, **НЕ задеплоено в прод на момент 07.05.2026**)
- `app/Http/Requests/AutoSupplyPlan/StoreAutoSupplyPlanRequest.php` — добавлены rules для 8 advanced/locality полей.
- `app/Http/Controllers/Api/AutoSupplyPlanController.php` (метод `store`, строки 120–139) — все 8 полей теперь сохраняются в `$plan->params`. Заменён `array_filter` на колбэк `fn($v) => $v !== null`, чтобы `false` не выкидывался.
- `app/Jobs/CalculateAutoSupplyPlanJob.php` (строки 635–667 и 995–1052) — переписаны критерии surplus/deficit (теперь взаимоисключающие, по реальному `currentStock + inTransit`) и матчинг через жадный алгоритм с уменьшением остатков. Симметричные пары A→B + B→A больше не создаются.

**Это нужно задеплоить и проверить, прежде чем приступать к этому ТЗ.** Эффект: для Ozon-планов `split_by_cluster=true` + `strategy='recommendations'` начнут реально активироваться. Для тех SKU, по которым уже есть `LocalityRecommendation`, появится cluster split. uplift всё ещё может оставаться маленьким — потому что **needed_qty считается warehouse-уровнем, а не cluster-уровнем** (см. далее).

---

## 2. Что нужно сделать (этот ТЗ)

Две большие задачи:

- **B. Демандо-кластерный расчёт `needed_qty` для Ozon** (ядро).
- **C. Реорганизация таблицы плана для Ozon** (UI + структура).

---

## 3. B. Демандо-кластерный расчёт `needed_qty` для Ozon

### B.1. Источник данных per-(SKU, кластер)

Уже существует: `App\Domains\Locality\Recommendation\DemandForecaster::forIntegration(int $integrationId, int $windowDays = 28)`.

Возвращает `array<sku, array<cluster_name, ['daily_demand' => float, 'sales_28d' => int, 'sales_7d' => int, 'volatility' => float, 'days_with_sales' => int, 'source' => string]>>` — это EWMA-прогноз per (SKU, cluster), построенный из таблицы `postings` по `financial_data->>'cluster_to'`.

**Использовать его как primary source деманда для Ozon-планов.**

### B.2. Изменение алгоритма `CalculateAutoSupplyPlanJob::calculate()`

Сейчас (упрощённо):

```
foreach ($warehouseRows as $wh) {
    $dailyDemand = computeFromWarehouseRow($wh);
    $needed = $targetCover * $dailyDemand - $wh->stock - $wh->in_transit;
    $qty = round($needed);
    saveLine([sku=$wh->sku, warehouse=$wh->warehouse_id, qty=$qty]);
}
```

Должно стать (для Ozon, при `params.split_by_cluster=true` или по дефолту):

```
$selectedClusterIds = $plan->params['cluster_ids'] ?? all_active_clusters();
$demandPerSkuCluster = app(DemandForecaster::class)->forIntegration($integrationId, 28);
$stockPerSkuCluster = aggregateInventoryByCluster($integrationId);   // см. B.3
$inTransitPerSkuCluster = aggregateInTransitByCluster($integrationId); // см. B.3

foreach ($skus as $sku) {
    foreach ($selectedClusterIds as $clusterId) {
        $clusterName = $clusterIdToName[$clusterId];
        $dailyDemand = $demandPerSkuCluster[$sku][$clusterName]['daily_demand'] ?? 0;
        if ($dailyDemand <= 0) {
            // Если в кластере не было продаж — пропускаем строку (не отгружаем туда).
            // Опционально: для seed_skus из Locality-рекомендаций — стартовая партия 30% от среднего.
            continue;
        }

        $stock = $stockPerSkuCluster[$sku][$clusterId] ?? 0;
        $inTransit = $inTransitPerSkuCluster[$sku][$clusterId] ?? 0;
        $abc = computeAbc($sku); // глобальный, не cluster-level
        $targetCover = $service->getTargetDaysByAbc($abc, $settings, $planDefault);
        $safety = $service->calculateDynamicSafetyStock($dailyDemand, $volatility, $leadTime, $minSafety);

        $needed = $targetCover * $dailyDemand + $safety - $stock - $inTransit;
        $needed = applyMaxCoverCap($needed, ...);
        $needed = applyTurnoverLimit($needed, ...);
        $qty = roundToPackMultiple(max(0, $needed), $packMultiple);

        if ($qty <= 0) continue;

        // Применить Locality-бустер: если для (sku, cluster) есть active recommendation,
        // увеличить приоритет/qty (но не превышать max_cover * dailyDemand).
        if (isset($recommendations[$sku][$clusterId])) {
            $rec = $recommendations[$sku][$clusterId];
            $qty = max($qty, $rec->recommended_qty_units);
        }

        saveLine([
            sku => $sku,
            cluster_id => $clusterId,
            cluster_name => $clusterName,
            warehouse_id => null,           // для Ozon-плана warehouse не фиксируется
            destination_type => 'cluster',
            qty_rounded => $qty,
            daily_demand => $dailyDemand,
            current_stock => $stock,
            in_transit => $inTransit,
            ...explain_json,
        ]);
    }
}
```

### B.3. Агрегация stock/in_transit per (SKU, cluster)

Сейчас `inventory_warehouse_stocks` хранит per (SKU, warehouse). Нужно агрегировать в кластер:

```
SELECT
    iws.sku,
    cluster_mapping.cluster_id,
    SUM(iws.quantity) AS stock,
    SUM(iws.in_transit) AS in_transit
FROM inventory_warehouse_stocks iws
JOIN warehouse_to_cluster_mapping cm ON cm.warehouse_id = iws.warehouse_id
WHERE iws.integration_id = ?
GROUP BY iws.sku, cluster_mapping.cluster_id
```

Используйте `OzonWarehouseCluster::normalizeWarehouseName()` или существующий `clusterMapping` из `CalculateAutoSupplyPlanJob` (строки 580–595).

### B.4. Локалити-бустер

При `params.include_locality_recommendations === true` — для пар (SKU, cluster) с активной `LocalityRecommendation` (state=new, confidence ≥ minimum):

1. Если `qty_calculated >= rec.recommended_qty_units` — оставить `qty_calculated`.
2. Если `qty_calculated < rec.recommended_qty_units` — поднять до `min(rec.recommended_qty_units, max_cover * daily_demand)`.
3. В строку плана сохранить `linked_locality_recommendation_ids = [rec.id]` и `expected_savings_rub`, `expected_local_share_after_pp` из rec.

Это даст **реальный coverage > 0** в `buildPlanSummary`.

### B.5. Что делать со старым «итерация по warehouse»

Для **Ozon** — заменить полностью на cluster-based loop (B.2).
Для **WB / других** — оставить старую логику (warehouse-based), потому что у WB модель другая.

Развилка через `if ($marketplace === 'ozon')` в начале `calculate()`.

### B.6. Удалить применение `applyClusterSplit` для Ozon

После B.2 строки уже разбиты по кластерам. Двойного split'а быть не должно.

В `CalculateAutoSupplyPlanJob` блок 884–982 (`Locality enrichment + cluster split`) для Ozon упростить до:

```
if ($marketplace === 'ozon') {
    // Lines уже cluster-level. Только enrich метриками.
    foreach ($lines as &$line) {
        $metric = $metrics[$line['sku']] ?? null;
        $recs = $recsPerSku[$line['sku']] ?? collect();
        // Берём ту рекомендацию, у которой target_cluster_id совпадает с $line['cluster_id']
        $matchingRec = $recs->firstWhere('target_cluster_id', $line['cluster_id']);
        $recsForLine = $matchingRec ? collect([$matchingRec]) : collect();
        $line = $enricher->enrichLine($line, $metric, $recsForLine, null);
    }
}
```

### B.7. Acceptance criteria для B

1. Для Ozon-плана с `split_by_cluster=true` все строки плана имеют `cluster_id !== null`, `warehouse_id === null`, `destination_type === 'cluster'`.
2. Для каждого (SKU, выбранный кластер) с `daily_demand_cluster > 0` создаётся отдельная строка.
3. `LocalityImpactSection.expected_uplift_pp > 0` если в плане участвуют SKU, у которых текущая локальность < 100%.
4. `coverage_percent` совпадает с долей `LocalityRecommendation` (active), у которых есть строка с тем же (sku, target_cluster_id) в плане.
5. «Матрица перераспределения» **пуста** для Ozon-планов (см. C.1).

---

## 4. C. Реорганизация структуры плана для Ozon

### C.1. Убрать матрицу перераспределения для Ozon (и WB FBO)

В `CalculateAutoSupplyPlanJob::calculate()` блок 995–1052 (текущая v5 жадная матрица) обернуть условием:

```
if ($marketplace === 'ozon' || $marketplace === 'wildberries') {
    $resultJson = ['redistribution' => []];
} else {
    // существующая v5 логика
}
```

Обоснование: продавец **не может** перемещать инвентарь между marketplace-складами. Эта секция — пустой совет.

В UI: `frontend/src/pages/products/components/.../RedistributionMatrix.tsx` (если такой есть) скрыть для Ozon/WB. Или просто `result_json.redistribution = []` уже даст пустую секцию.

### C.2. Основная таблица плана: для Ozon — (SKU × кластер)

Сейчас `frontend/src/pages/products/pages/AutoSupplyPlanDetailPage.tsx` рендерит `lines` (`AutoSupplyPlanLine[]`) с колонками для warehouse. После B.2 каждая строка plan_line будет cluster-level (`cluster_id` заполнен, `warehouse_id` пуст).

Изменения во фронте:
- В `buildLineColumns()` (`AutoSupplyPlanDetailPage.tsx:867-915`) для Ozon заменить колонку «Склад» на «Кластер» (отображать `cluster_name`).
- Скрыть колонку `warehouse_breakdown` для Ozon (она имеет смысл только для WB, где warehouse — реальная единица).
- Убрать вкладку «Кластеры» (`ClusterSplitTable`) для Ozon — она дублирует основную таблицу. **Альтернатива**: оставить как «Сводка по кластерам» с агрегатом (cluster, total_qty, expected_savings) — без детализации по SKU.

### C.3. Acceptance criteria для C

1. Для Ozon-плана таблица показывает строки в формате `[SKU, Cluster Name, qty, daily_demand_cluster, current_stock_cluster, ...]`.
2. Вкладка «Кластеры» либо удалена, либо превращена в read-only сводку.
3. Кнопка «Создать черновики FBO в Ozon» использует cluster_id напрямую из строк плана (без re-aggregation), потому что строки уже cluster-level.
4. Матрица перераспределения для Ozon-плана пустая (или скрыта).

---

## 5. Список файлов к изменению

### Backend (`products-backend`)

| Файл | Что изменить |
|------|---------------|
| `app/Jobs/CalculateAutoSupplyPlanJob.php` | Метод `calculate()`: для Ozon — заменить warehouse-loop на cluster-loop (B.2). Убрать redistribution для Ozon/WB (C.1). Упростить enrichment (B.6). |
| `app/Domains/Locality/Recommendation/DemandForecaster.php` | Добавить `forIntegrationByClusterId()` если для маппинга нужен `cluster_id`, не только `cluster_name`. |
| `app/Domains/Locality/Integration/LocalityEnrichmentService.php` | `buildPlanSummary` — уплифт считать через факт. покрытие рекомендаций строками плана с правильным `cluster_id` (а не через сумму `expected_local_share_after_pp`). |
| `app/Models/AutoSupplyPlanLine.php` | Документировать, что для Ozon `warehouse_id` всегда null, `cluster_id` всегда заполнен. Возможно — миграция: индекс на `(plan_id, cluster_id)`. |
| `app/Services/AutoSupplyPlanService.php` | Если в нём есть warehouse-specific хелперы — продублировать cluster-version. |

### Frontend (`frontend/src/pages/products`)

| Файл | Что изменить |
|------|---------------|
| `pages/AutoSupplyPlanDetailPage.tsx` | `buildLineColumns()` — для Ozon показать колонку «Кластер» вместо «Склад». Скрыть `warehouse_breakdown`. |
| `components/locality/ClusterSplitTable.tsx` | Опционально удалить вкладку или превратить в агрегат-readonly. |
| `pages/AutoSupplyPlansPage.tsx` | Уже OK — Locality-флаги шлются по умолчанию для Ozon. |
| Компонент с матрицей перераспределения (`RedistributionMatrix.tsx` или аналог) | Скрыть для `plan.marketplace === 'ozon'` или `'wildberries'`. |

---

## 6. Этапы и зависимости

1. **Этап 0** (готов): задеплоить уже сделанные правки в backend (StoreRequest, Controller, Job v5 redistribution). Проверить в проде, что cluster_split начал работать для новых планов через `params.split_by_cluster=true`.
2. **Этап 1** (B): cluster-based расчёт `needed_qty` в `CalculateAutoSupplyPlanJob` для Ozon. Это самая большая работа (~300–500 строк изменений + тесты).
3. **Этап 2** (C.1): убрать redistribution для Ozon/WB. ~10 строк.
4. **Этап 3** (C.2): переписать колонки таблицы во фронте. ~50–100 строк.
5. **Этап 4**: e2e-тест на реальном Ozon-аккаунте. Проверить что uplift и coverage > 0.

---

## 7. Тесты

### Unit
- `tests/Unit/CalculateAutoSupplyPlanJobTest.php` — добавить кейс «Ozon с 3 кластерами и разным demand → 3 строки плана с qty пропорционально demand».
- `tests/Unit/LocalityEnrichmentServiceTest.php` — кейс «план покрывает 2 из 3 рекомендаций → coverage_percent = 66.67%».

### Integration
- Создать тестовый план на стейджинге с 1 SKU у которого demand: МСК=70%, СПб=20%, Краснодар=10%. Ожидание: qty распределится 7:2:1 (если все в `cluster_ids`).

### Регрессия
- WB-планы должны продолжать работать как раньше (warehouse-based).
- Старые Ozon-планы (созданные до этого ТЗ) не должны ломаться при просмотре — fallback на текущую логику отображения.

---

## 8. Риски

1. **`postings.financial_data->>'cluster_to'`** не всегда заполнен — у части интеграций может не быть данных. Fallback: использовать Ozon delivery analytics `recommended_supply` per cluster (см. `LocalityEnrichmentService::weightsFromOzonAnalytics`).
2. **EWMA на малых выборках** даёт нестабильный demand. `DemandForecaster::projectDailyDemand` уже это учитывает (cold_start, simple_avg). Проверить пороги `cold_start_min_sales_28d` для cluster-уровня — возможно надо снизить.
3. **Cluster mapping актуальность**. С 06.11.2025 Ozon разделил 4 кластера на 8 (Сибирь→Омск+Новосибирск и т.д.). Убедиться, что `OzonClusterMapSyncer` берёт свежий справочник перед генерацией плана.
4. **Совместимость со старыми планами**: после миграции `AutoSupplyPlanLine`, старые строки с `warehouse_id` без `cluster_id` должны продолжить рендериться. Не делать NOT NULL constraint.

---

## 9. Out of scope

Эти задачи **не покрывает** ТЗ, но они на радаре:

- Реализация `createFromLocalityRecommendations` endpoint (объявлен в `routes/api.php:269`, но метод отсутствует в контроллере).
- Seed-логика для `include_locality_recommendations`: добавить в план SKU из активных рекомендаций даже если их нет в текущем deficit.
- Bulk re-calculation старых планов.
- ABC-приоритет per cluster (сейчас глобальный по SKU).

---

## 10. Контакт / вопросы

Если после прочтения остались вопросы по структуре `postings.financial_data`, маппингу кластеров, или поведению `LocalityRecommendation.confidence` — связаться с автором этого ТЗ через CRM/Slack.
