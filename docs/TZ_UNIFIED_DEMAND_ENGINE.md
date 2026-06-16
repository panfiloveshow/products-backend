# ТЗ: Единый движок спроса (этап 3)

**Статус:** черновик на ревью
**Дата:** 2026-06-11
**Контекст:** Roadmap автопланирования, этап 3 (#3) — согласованность цифр спроса
**Связано:** [TZ_PLAN_FACT_LOOP.md](./TZ_PLAN_FACT_LOOP.md) (измеритель точности — нужен для парити-харнесса)

---

## 1. Проблема

В системе сосуществуют **несколько независимых вычислений дневного спроса** с разными источниками и формулами. Для одного и того же SKU они дают разные числа → на разных экранах/отчётах цифры не сходятся, и нечего калибровать (этап 4) — непонятно, что считать «прогнозом».

| Движок | Файл | Источник | Метод |
|---|---|---|---|
| **Канонический** | `app/Domains/Locality/Recommendation/DemandForecaster` | `postings`+`posting_items` | EWMA по кластерам (α=0.3), окно 28д, cold-start/simple_avg/ewma |
| Авто-план | `app/Services/AutoSupplyPlanService::calculateDailyDemandV2` | Ozon API (`posting_fbo_v3`) + эффективный/EWMA/7д | водопад источников |
| Legacy | `app/Services/Supply/LegacySupplyRecommendationService` | `inventory_warehouses` (avg_7/14/28) | простое среднее × дни (`@deprecated`) |
| DDD | `app/Domains/Supplies/Services/SupplyRecommendationService` | (через SupplyCalculation/Optimization) | отдельная ветка |
| ~~Мёртвый~~ | ~~`CalculateForecastsJob`~~ | ~~`inventory_history`~~ | **УДАЛЁН 2026-06-11** |

Потребители legacy: `SupplyController`, `CalculateSupplyRecommendationsJob`, `CalculateSupplyAnalyticsCommand`.

## 2. Цель и Definition of Done

Один **источник истины по дневному спросу** (`DemandForecaster`), к которому сводятся все потребители; legacy/DDD-дубли задепрекейчены или поглощены; расхождение цифр между путями устранено или явно объяснено и принято.

DoD:
1. Все вычисления «дневного спроса по SKU» идут через один интерфейс/фасад поверх `DemandForecaster`.
2. `LegacySupplyRecommendationService` не вызывается из прод-путей (или удалён).
3. Парити-харнесс показывает: расхождение нового и старого пути по набору SKU в пределах согласованного допуска (см. §5), либо расхождение разобрано.
4. Регрессия: фичи «рекомендации поставок» и «автоплан» работают, числа стабильны/объяснимы.

## 3. Почему это нельзя делать вслепую

- `calculateDailyDemandV2` (авто-план) и `DemandForecaster` берут спрос из **разных источников** (живой Ozon API vs локальные `postings`). Они могут законно расходиться (свежесть, отмены, кластерная агрегация). Простая «замена» сломает уже выверенный авто-план.
- Legacy берёт `inventory_warehouses.avg_*` — это **другая база** (агрегаты остатков), чем `postings`. Переключение изменит выдачу рекомендаций поставок — это видимое пользователю поведение.
- Поэтому нужен **парити-харнесс + допуск + флаг**, а не прямая правка вызовов.

## 4. Стратегия (безопасная миграция)

1. **Интерфейс спроса.** Ввести `DemandProviderInterface::dailyDemand(integrationId, sku, cluster?): float` и адаптер `EwmaDemandProvider` поверх `DemandForecaster`. Не менять поведение — только унифицировать точку входа.
2. **Парити-харнесс (read-only).** Команда `demand:parity --integration=`, которая для набора SKU считает спрос всеми путями (canonical / legacy / v2) и пишет таблицу расхождений (MAE, % SKU за допуском). Опирается на измеритель этапа 2, где возможно.
3. **Переключение за флагом.** Потребители legacy переводятся на провайдер за `UNIFIED_DEMAND=true` (по умолчанию off), со сравнением в логах в shadow-режиме.
4. **Депрекейт.** После подтверждения парити — `SupplyController`/`CalculateSupplyRecommendationsJob` → провайдер; `LegacySupplyRecommendationService` удалить (callers пусты).
5. **Авто-план.** `calculateDailyDemandV2` НЕ удалять сразу — он водопадный и выверен; задача-минимум: сделать `DemandForecaster` одним из его источников и согласовать, а не заменить. Полное слияние — отдельная подзадача с парити.

## 5. Открытый вопрос (нужно решение)

**Допуск расхождения.** Какое отклонение нового (canonical) от текущего спроса считаем приемлемым, чтобы переключать без ручного разбора каждого SKU? Предложение: median APE ≤ 10% и доля SKU с APE > 25% ≤ 5%. Это бизнес-решение — влияет на объём ручной выверки.

Прочее: окно (28 vs параметр плана); как канонизировать кластерную агрегацию для WB (нет кластеров); считать ли отмены/возвраты единообразно (связь с памяткой `ozon_returns_effective_logistics`).

## 6. Этапы и риск

| Этап | Содержание | Риск | Оценка |
|---|---|---|---|
| 3.0 | ✅ Удалить мёртвый `CalculateForecastsJob`; `@deprecated` на legacy | нет | сделано |
| 3.1 | `DemandProviderInterface` + `EwmaDemandProvider` (без смены поведения) | низкий | 1.5 д |
| 3.2 | Парити-харнесс `demand:parity` + отчёт расхождений | низкий (read-only) | 2 д |
| 3.3 | Решение по допуску (§5) + shadow-логирование | — | 0.5 д |
| 3.4 | Перевод потребителей legacy на провайдер за флагом | средний | 2 д |
| 3.5 | Удаление legacy после парити; согласование v2 | высокий | 2–3 д |
| **Итого** | | | **~8 д** |

**Принцип:** ни одного переключателя без парити-харнесса и принятого допуска. Сначала измеряем расхождение, потом меняем.

## Приложение: ключевые файлы
- `app/Domains/Locality/Recommendation/DemandForecaster.php` — канонический прогноз
- `app/Services/AutoSupplyPlanService.php::calculateDailyDemandV2` — водопад авто-плана
- `app/Services/Supply/LegacySupplyRecommendationService.php` — `@deprecated`
- `app/Http/Controllers/Api/SupplyController.php`, `app/Jobs/CalculateSupplyRecommendationsJob.php` — потребители legacy
- `app/Services/AutoSupplyPlanning/PlanFactReconciler.php` — измеритель (этап 2), переиспользовать в парити
