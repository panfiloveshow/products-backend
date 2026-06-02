<?php

namespace App\Services\AutoSupplyPlanning;

use App\Models\AutoSupplyPlan;

class PlanningReadinessChecklistService
{
    /**
     * @return array<string, mixed>
     */
    public function build(AutoSupplyPlan $plan): array
    {
        $params = is_array($plan->params ?? null) ? $plan->params : [];
        $sources = $plan->planning_sources;
        $freshness = $plan->facts_freshness;
        $capabilities = $plan->marketplace_capabilities;
        $territorial = $plan->territorial_summary;
        $constraints = $plan->constraints_summary;
        $deficit = $plan->deficit_summary;
        $surplus = $plan->surplus_summary;
        $deficitSurplus = $plan->deficit_surplus_summary;
        $economics = $plan->economics_summary;
        $selection = $plan->selection_summary;
        $marketplace = (string) $plan->marketplace;

        $sections = [
            $this->section('parameters', 'Параметры расчёта', [
                $this->item(
                    'analysis_period',
                    'Период анализа продаж',
                    $this->hasValue($params['analysis_period_days'] ?? $plan->horizon_days) ? 'ready' : 'missing',
                    $this->hasValue($params['analysis_period_days'] ?? $plan->horizon_days)
                        ? 'Период анализа передан в расчёт.'
                        : 'Период анализа не найден в параметрах плана.',
                    $this->hasValue($params['analysis_period_days'] ?? $plan->horizon_days)
                        ? (int) ($params['analysis_period_days'] ?? $plan->horizon_days) . ' дней'
                        : null,
                ),
                $this->item(
                    'calculation_system',
                    'Система расчёта',
                    $this->hasValue($params['planning_mode'] ?? $plan->mode) ? 'ready' : 'missing',
                    'Режим влияет на приоритеты: баланс, защита от отсутствия товара, локальность, прибыль или осторожность после акции.',
                    $this->modeLabel((string) ($params['planning_mode'] ?? $plan->mode ?? '')),
                ),
                $this->item(
                    'include_in_transit',
                    'Учитывать товары в пути',
                    array_key_exists('include_in_transit', $params) && $params['include_in_transit'] === false ? 'partial' : 'ready',
                    array_key_exists('include_in_transit', $params) && $params['include_in_transit'] === false
                        ? 'Пользователь отключил учёт товаров в пути.'
                        : 'Товары в пути включены в расчёт, если источник данных доступен.',
                    array_key_exists('include_in_transit', $params) && $params['include_in_transit'] === false ? 'нет' : 'да',
                ),
                $this->item(
                    'destinations',
                    $marketplace === 'ozon' ? 'Нужные кластеры' : 'Нужные склады',
                    $this->selectedDestinationsCount($params) > 0 ? 'ready' : 'partial',
                    $this->selectedDestinationsCount($params) > 0
                        ? 'Пользователь выбрал конкретные направления, они являются жёстким ограничением расчёта.'
                        : 'Конкретные направления не выбраны: план считается по всем доступным направлениям.',
                    $this->selectedDestinationsLabel($params, $marketplace),
                ),
                $this->item(
                    'seasonality_and_trend',
                    'Коэффициенты сезонности и тренда',
                    $this->hasValue($params['demand_seasonality_multiplier'] ?? null) || $this->hasValue($params['trend_multiplier'] ?? null) ? 'ready' : 'partial',
                    $this->hasValue($params['demand_seasonality_multiplier'] ?? null) || $this->hasValue($params['trend_multiplier'] ?? null)
                        ? 'Коэффициенты переданы и применяются к спросу.'
                        : 'Коэффициенты не заданы: используется нейтральное значение 1.0.',
                    'сезонность ' . (string) ($params['demand_seasonality_multiplier'] ?? $params['seasonality_multiplier'] ?? 1) . ', тренд ' . (string) ($params['trend_multiplier'] ?? 1),
                ),
            ]),
            $this->section('api_sources', 'Источники данных API', [
                $this->sourceItem('orders', 'Заказы', $sources['demand'] ?? null, 'Спрос построен из заказов или отчёта продаж.', 'Нет подтверждённого источника спроса.'),
                $this->sourceItem('stocks', 'Остатки по складам/кластерам', $sources['stock'] ?? null, 'Остатки подключены к расчёту.', 'Нет подтверждённого источника остатков.'),
                $this->sourceItem('in_transit', 'Товары в пути', $sources['in_transit'] ?? null, 'Товары в пути подключены к расчёту.', ($params['include_in_transit'] ?? true) === false ? 'Учёт товаров в пути отключён пользователем.' : 'Источник товаров в пути не найден или пуст.', ($params['include_in_transit'] ?? true) === false ? 'partial' : null),
                $this->marketplaceNeedsItem($sources, $constraints),
                $this->item(
                    'prices_and_coefficients',
                    'Цены и коэффициенты',
                    $this->hasEconomics($economics, $freshness) ? 'ready' : 'partial',
                    $this->hasEconomics($economics, $freshness)
                        ? 'Юнит-экономика, стоимость и прибыль участвуют в выборе строк.'
                        : 'Экономика частичная: рекомендации будут осторожнее.',
                    $this->hasValue($economics['total_expected_profit'] ?? null) ? 'прибыль: ' . $economics['total_expected_profit'] : null,
                ),
                $this->constraintCoefficientsItem($sources, $constraints),
                $this->deliverySpeedItem($sources, $territorial),
                $this->constraintsItem($sources, $constraints),
            ]),
            $this->section('calculations', 'Что считает сервис', [
                $this->item('deficit', 'Где есть дефицит', $deficit !== [] ? 'ready' : 'missing', 'Дефицит считается отдельно от количества к поставке.', $this->summaryQtyLabel($deficit)),
                $this->item('surplus', 'Где есть профицит', $surplus !== [] ? 'ready' : 'missing', 'Профицит не маскируется под новую потребность.', $this->summaryQtyLabel($surplus)),
                $this->item('shipment_qty', 'Сколько нужно отгрузить', $plan->total_qty > 0 || $selection !== [] ? 'ready' : 'missing', 'Финальное количество выбирает модуль выбора после ограничений, экономики и бюджета.', $plan->total_qty !== null ? (int) $plan->total_qty . ' шт.' : null),
                $this->item('best_destinations', $marketplace === 'ozon' ? 'В какие кластеры лучше везти' : 'На какие склады лучше везти', $territorial !== [] ? 'ready' : 'missing', 'Направления ранжируются по скорости, спросу, ABC, риску, ограничениям и экономике.', $this->territorialStatus($territorial)),
                $this->item('demand_closure', 'Какие направления быстрее закрывают спрос', $this->hasDemandClosureRanking($territorial) ? 'ready' : 'partial', $this->hasDemandClosureRanking($territorial) ? 'Есть ранжирование направлений по скорости закрытия спроса.' : 'Территориальные источники частичные, балл менее уверенный.', $this->demandClosureLabel($territorial)),
                $this->item('lost_revenue', 'Упущенная выручка', $this->hasValue($deficit['lost_revenue_daily'] ?? null) ? 'ready' : 'partial', 'Потери считаются там, где есть дефицит и экономика.', $this->hasValue($deficit['lost_revenue_daily'] ?? null) ? (string) $deficit['lost_revenue_daily'] . ' в день' : null),
                $this->sourceItem('turnover', 'Оборачиваемость', $sources['turnover'] ?? null, 'Оборачиваемость подключена как источник.', 'Оборачиваемость частичная: используются fallback-метрики.', 'partial'),
                $this->item('seasonality_trend_effect', 'Влияние сезонности и тренда', 'ready', 'Коэффициенты сезонности/тренда попадают в формулу спроса; если не заданы, применяются нейтрально.', 'учтено'),
                $this->item('redistribution', 'Дефицит, профицит и перераспределение', $deficitSurplus !== [] ? 'ready' : 'missing', $deficitSurplus['method'] ?? 'Отдельный анализ дефицита и профицита.', $deficitSurplus['redistribution']['policy'] ?? null),
            ]),
            $this->section('marketplace_policy', 'Логика площадки', [
                $this->item('territorial_distribution', 'Территориальное распределение', $this->boolPath($capabilities, ['territorial_distribution', 'supported']) ? 'ready' : 'partial', $this->boolPath($capabilities, ['territorial_distribution', 'supported']) ? 'Территориальное ранжирование включено для площадки.' : 'Для этой площадки территориальная модель ограничена.', $capabilities['territorial_distribution']['score_kind'] ?? null),
                $this->item('ktr', 'КТР', $this->hasValue($territorial['ktr']['value'] ?? null) ? 'ready' : 'partial', $territorial['ktr']['explanation'] ?? 'КТР считается, когда есть территориальное распределение строк.', $territorial['ktr']['label'] ?? null),
                $this->ktrBaselineItem($territorial, $params),
                $this->item('a_items_fast_destinations', 'Быстрые направления для A-товаров', $this->hasValue($territorial['ktr']['abc_a_fast_share_percent'] ?? null) ? 'ready' : 'partial', $territorial['ktr']['a_items_policy_status'] ?? 'Для A-товаров используется повышенный вес скорости, когда есть ABC и территориальные данные.', $this->hasValue($territorial['ktr']['abc_a_fast_share_percent'] ?? null) ? (string) $territorial['ktr']['abc_a_fast_share_percent'] . '%' : null),
                $this->item('draft_creation', 'Черновик поставки / кросс-докинг', ! empty($capabilities['supports_draft_creation']) ? 'ready' : 'partial', ! empty($capabilities['supports_draft_creation']) ? (string) ($capabilities['planning_flow'] ?? 'Создание черновика доступно после предпросмотра и подтверждения.') : 'Площадка работает как рекомендации и экспорт без автобронирования.', $capabilities['autobooking_policy'] ?? null),
                $this->crossdockDropOffItem($marketplace, $params, $capabilities),
                $this->item('autobooking', 'Автобронирование', 'ready', $capabilities['autobooking_policy'] ?? 'Автобронирование не выполняется.', ! empty($capabilities['supports_autobooking']) ? 'включено' : 'не выполняется'),
            ]),
        ];

        $requirements = $this->requirementsMatrix($sections);

        return array_merge($this->overall($sections), [
            'version' => 'planning-readiness-1',
            'marketplace' => $marketplace,
            'sections' => $sections,
            'requirements_matrix' => $requirements['matrix'],
            'requirements_summary' => $requirements['summary'],
            'critical_gaps_ru' => $requirements['critical_gaps_ru'],
            'next_actions_ru' => $requirements['next_actions_ru'],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function section(string $key, string $title, array $items): array
    {
        $counts = ['ready' => 0, 'partial' => 0, 'missing' => 0];
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? 'missing');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return [
            'key' => $key,
            'title_ru' => $title,
            'status' => $this->statusFromCounts($counts),
            'status_ru' => $this->statusRu($this->statusFromCounts($counts)),
            'ready_count' => $counts['ready'],
            'partial_count' => $counts['partial'],
            'missing_count' => $counts['missing'],
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $key, string $label, string $status, string $details, mixed $value = null): array
    {
        return [
            'key' => $key,
            'label_ru' => $label,
            'status' => $status,
            'status_ru' => $this->statusRu($status),
            'details_ru' => $details,
            'value' => $value,
        ];
    }

    private function sourceItem(string $key, string $label, mixed $source, string $readyText, string $fallbackText, ?string $fallbackStatus = null): array
    {
        $hasSource = $this->hasValue($source);

        return $this->item(
            $key,
            $label,
            $hasSource ? 'ready' : ($fallbackStatus ?? 'missing'),
            $hasSource ? $readyText : $fallbackText,
            $hasSource ? $this->sourceLabel((string) $source) : null,
        );
    }

    /**
     * @param array<string, mixed> $sources
     * @param array<string, mixed> $territorial
     */
    private function deliverySpeedItem(array $sources, array $territorial): array
    {
        $source = $sources['delivery_health'] ?? null;
        $metrics = is_array($territorial['source_coverage']['metrics'] ?? null)
            ? $territorial['source_coverage']['metrics']
            : [];
        $hasSpeedInRanking = (float) ($metrics['delivery_speed_source']['coverage_percent']
                ?? $metrics['speed_source']['coverage_percent']
                ?? 0) > 0;

        return $this->item(
            'delivery_speed',
            'Скорость доставки',
            $this->hasValue($source) || $hasSpeedInRanking ? 'ready' : 'partial',
            $this->hasValue($source) || $hasSpeedInRanking
                ? 'Скорость доставки участвует в территориальном ранжировании.'
                : 'Скорость доставки неполная: территориальный балл будет менее уверенным.',
            $this->hasValue($source) ? $this->sourceLabel((string) $source) : ($hasSpeedInRanking ? 'есть в ранжировании направлений' : null),
        );
    }

    /**
     * @param array<string, mixed> $sources
     * @param array<string, mixed> $constraints
     */
    private function constraintsItem(array $sources, array $constraints): array
    {
        $source = $sources['constraints'] ?? null;
        $planningSource = is_array($constraints['planning_source'] ?? null) ? $constraints['planning_source'] : [];
        $sourceTypeCounts = is_array($constraints['source_type_counts'] ?? null) ? $constraints['source_type_counts'] : [];
        $hasConstraints = $this->hasValue($source)
            || (int) ($constraints['files_count'] ?? 0) > 0
            || (int) ($constraints['matched_rules'] ?? 0) > 0
            || (int) ($constraints['constraints_count'] ?? 0) > 0
            || (int) ($constraints['matched_constraints_count'] ?? 0) > 0
            || $this->hasValue($constraints['source_file'] ?? null)
            || ! empty($planningSource['used_as_constraints'])
            || ! empty($planningSource['used_as_marketplace_needs'])
            || ! empty($sourceTypeCounts);
        $requiresReview = ! empty($planningSource['requires_review'])
            || (int) ($constraints['unmatched_constraints_count'] ?? 0) > 0
            || (int) ($constraints['unmatched_marketplace_need_qty'] ?? 0) > 0;

        return $this->item(
            'constraints',
            'Ограничения складов/кластеров',
            $hasConstraints ? ($requiresReview ? 'partial' : 'ready') : 'partial',
            match (true) {
                ! $hasConstraints => 'Файл ограничений не подключён: расчёт использует доступные внутренние ограничения.',
                $requiresReview => 'Ограничения подключены, но часть правил или потребностей требует проверки.',
                default => 'Ограничения подключены из файла, параметров или правил площадки.',
            },
            $this->constraintsLabel($source, $constraints),
        );
    }

    /**
     * @param array<string, mixed> $sources
     * @param array<string, mixed> $constraints
     */
    private function marketplaceNeedsItem(array $sources, array $constraints): array
    {
        $source = $sources['marketplace_needs'] ?? null;
        $planningSource = is_array($constraints['planning_source'] ?? null) ? $constraints['planning_source'] : [];
        $sourceTypeCounts = is_array($constraints['source_type_counts'] ?? null) ? $constraints['source_type_counts'] : [];
        $hasNeeds = $this->hasValue($source)
            || (int) ($constraints['file_marketplace_needs_count'] ?? 0) > 0
            || (int) ($constraints['marketplace_needs_count'] ?? 0) > 0
            || (int) ($constraints['marketplace_need_lines'] ?? 0) > 0
            || (int) ($constraints['matched_marketplace_need_lines'] ?? 0) > 0
            || (int) ($constraints['total_file_marketplace_need_qty'] ?? 0) > 0
            || (int) ($constraints['total_marketplace_need_qty'] ?? 0) > 0
            || ! empty($planningSource['used_as_marketplace_needs'])
            || (int) ($sourceTypeCounts['marketplace_need'] ?? 0) > 0
            || (int) ($sourceTypeCounts['constraint_and_need'] ?? 0) > 0;
        $requiresReview = ! empty($planningSource['requires_review'])
            || ! empty($planningSource['has_unmatched_marketplace_needs'])
            || (int) ($constraints['unmatched_marketplace_need_qty'] ?? 0) > 0
            || (int) ($constraints['unmatched_marketplace_need_count'] ?? 0) > 0
            || (int) ($constraints['marketplace_need_remaining_delta_qty'] ?? 0) > 0;

        return $this->item(
            'marketplace_needs',
            'Потребности складов/кластеров',
            $hasNeeds ? ($requiresReview ? 'partial' : 'ready') : 'partial',
            match (true) {
                ! $hasNeeds => 'Готовых потребностей маркетплейса нет: потребность считает наш engine.',
                $requiresReview => 'Потребности маркетплейса загружены, но часть SKU или направлений требует проверки.',
                default => 'Потребности маркетплейса загружены из файла или импорта и участвуют в расчёте.',
            },
            $this->marketplaceNeedsLabel($source, $constraints),
        );
    }

    /**
     * @param array<string, mixed> $sources
     * @param array<string, mixed> $constraints
     */
    private function constraintCoefficientsItem(array $sources, array $constraints): array
    {
        $source = $sources['constraint_coefficients'] ?? null;
        $planningSource = is_array($constraints['planning_source'] ?? null) ? $constraints['planning_source'] : [];
        $sourceTypeCounts = is_array($constraints['source_type_counts'] ?? null) ? $constraints['source_type_counts'] : [];
        $coefficientLines = (int) ($constraints['coefficient_lines'] ?? $constraints['coefficient_lines_count'] ?? 0);
        $hasCoefficients = $this->hasValue($source)
            || $coefficientLines > 0
            || ! empty($planningSource['used_as_coefficients'])
            || (int) ($sourceTypeCounts['coefficient'] ?? 0) > 0;

        return $this->item(
            'warehouse_coefficients',
            'Коэффициенты складов/кластеров',
            $hasCoefficients ? 'ready' : 'partial',
            $hasCoefficients
                ? 'Коэффициенты направлений подключены и влияют на выбор склада или кластера.'
                : 'Отдельные коэффициенты направлений не найдены: ranking использует нейтральный коэффициент.',
            $hasCoefficients
                ? trim(implode(', ', array_filter([
                    $this->hasValue($source) ? $this->sourceLabel((string) $source) : null,
                    $coefficientLines > 0 ? $coefficientLines . ' строк с коэффициентами' : null,
                    $this->hasValue($constraints['source_file'] ?? null) ? (string) $constraints['source_file'] : null,
                ])))
                : null,
        );
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return array<string, mixed>
     */
    private function overall(array $sections): array
    {
        $ready = 0;
        $partial = 0;
        $missing = 0;
        foreach ($sections as $section) {
            $ready += (int) ($section['ready_count'] ?? 0);
            $partial += (int) ($section['partial_count'] ?? 0);
            $missing += (int) ($section['missing_count'] ?? 0);
        }

        $total = max(1, $ready + $partial + $missing);
        $percent = round(($ready + $partial * 0.5) / $total * 100, 2);
        $status = match (true) {
            $percent >= 85 && $missing === 0 => 'ready',
            $percent >= 55 => 'partial',
            default => 'missing',
        };

        return [
            'overall_status' => $status,
            'overall_status_ru' => $this->statusRu($status),
            'readiness_percent' => $percent,
            'ready_count' => $ready,
            'partial_count' => $partial,
            'missing_count' => $missing,
            'summary_ru' => $this->summaryText($status, $ready, $partial, $missing),
        ];
    }

    /**
     * @param array<string, int> $counts
     */
    private function statusFromCounts(array $counts): string
    {
        if (($counts['missing'] ?? 0) > 0) {
            return ($counts['ready'] ?? 0) > 0 || ($counts['partial'] ?? 0) > 0 ? 'partial' : 'missing';
        }

        return ($counts['partial'] ?? 0) > 0 ? 'partial' : 'ready';
    }

    private function statusRu(string $status): string
    {
        return match ($status) {
            'ready' => 'включено',
            'partial' => 'частично',
            default => 'не хватает данных',
        };
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return array{matrix:list<array<string,mixed>>, summary:array<string,mixed>, critical_gaps_ru:list<string>, next_actions_ru:list<string>}
     */
    private function requirementsMatrix(array $sections): array
    {
        $matrix = [];
        $criticalGaps = [];
        $nextActions = [];
        $ready = 0;
        $partial = 0;
        $missing = 0;

        foreach ($sections as $section) {
            $items = [];
            foreach ((array) ($section['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $status = (string) ($item['status'] ?? 'missing');
                $ready += $status === 'ready' ? 1 : 0;
                $partial += $status === 'partial' ? 1 : 0;
                $missing += $status === 'missing' ? 1 : 0;

                $matrixItem = [
                    'key' => (string) ($item['key'] ?? ''),
                    'requirement_ru' => (string) ($item['label_ru'] ?? ''),
                    'status' => $status,
                    'status_ru' => $this->statusRu($status),
                    'evidence_ru' => $this->requirementEvidenceText($item),
                    'next_action_ru' => $this->requirementNextActionText((string) ($section['key'] ?? ''), $item),
                    'is_blocking' => $this->isBlockingRequirement((string) ($section['key'] ?? ''), $status),
                ];

                if ($matrixItem['is_blocking']) {
                    $criticalGaps[] = $matrixItem['requirement_ru'] . ': ' . $matrixItem['next_action_ru'];
                }

                if ($status !== 'ready') {
                    $nextActions[] = $matrixItem['next_action_ru'];
                }

                $items[] = $matrixItem;
            }

            $total = max(1, count($items));
            $sectionReady = count(array_filter($items, static fn (array $item): bool => $item['status'] === 'ready'));
            $sectionPartial = count(array_filter($items, static fn (array $item): bool => $item['status'] === 'partial'));

            $matrix[] = [
                'key' => (string) ($section['key'] ?? ''),
                'title_ru' => (string) ($section['title_ru'] ?? ''),
                'status' => (string) ($section['status'] ?? 'missing'),
                'status_ru' => (string) ($section['status_ru'] ?? $this->statusRu((string) ($section['status'] ?? 'missing'))),
                'coverage_percent' => round(($sectionReady + $sectionPartial * 0.5) / $total * 100, 2),
                'items' => $items,
            ];
        }

        $total = max(1, $ready + $partial + $missing);
        $coveragePercent = round(($ready + $partial * 0.5) / $total * 100, 2);

        return [
            'matrix' => $matrix,
            'summary' => [
                'title_ru' => 'Покрытие требований умного автопланирования',
                'coverage_percent' => $coveragePercent,
                'ready_count' => $ready,
                'partial_count' => $partial,
                'missing_count' => $missing,
                'decision_ru' => $this->requirementsDecisionText($coveragePercent, $missing, $partial),
            ],
            'critical_gaps_ru' => array_values(array_unique(array_slice($criticalGaps, 0, 8))),
            'next_actions_ru' => array_values(array_unique(array_slice($nextActions, 0, 8))),
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function requirementEvidenceText(array $item): string
    {
        $value = $item['value'] ?? null;
        $details = (string) ($item['details_ru'] ?? '');

        if ($this->hasValue($value)) {
            return trim((string) $value . ' · ' . $details);
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function requirementNextActionText(string $sectionKey, array $item): string
    {
        $status = (string) ($item['status'] ?? 'missing');
        $label = (string) ($item['label_ru'] ?? 'пункт');
        $key = (string) ($item['key'] ?? '');

        if ($status === 'ready') {
            return 'Действий не требуется: пункт включён.';
        }

        if ($status === 'missing') {
            return match ($sectionKey) {
                'api_sources' => "Подключить или синхронизировать источник данных: {$label}.",
                'calculations' => "Досчитать блок: {$label}.",
                'parameters' => "Передать параметр расчёта: {$label}.",
                default => "Настроить блок: {$label}.",
            };
        }

        return match ($key) {
            'include_in_transit' => 'Включить учёт товаров в пути, если нужно планировать с фактическими заявками поставки.',
            'destinations' => 'Выбрать конкретные склады/кластеры, если план должен быть ограничен направлением.',
            'seasonality_and_trend' => 'Задать сезонность или тренд, если был всплеск продаж, акция или изменение спроса.',
            'constraints' => 'Загрузить или проверить файл ограничений складов/кластеров.',
            'marketplace_needs' => 'Проверить несовпавшие потребности маркетплейса и привязку SKU/направлений.',
            'delivery_speed' => 'Подключить источник скорости доставки или обновить территориальные данные.',
            'demand_closure' => 'Улучшить территориальные источники, чтобы ранжирование направлений стало точнее.',
            'lost_revenue' => 'Подключить экономику и дефицит, чтобы считать упущенную выручку.',
            'turnover' => 'Синхронизировать оборачиваемость или stock analytics.',
            'territorial_distribution' => 'Подключить территориальный профиль спроса для площадки.',
            'ktr' => 'Досчитать КТР после появления территориальных строк плана.',
            'ktr_baseline' => 'Зафиксировать текущий КТР как базу сравнения, чтобы следующие планы показывали улучшение или ухудшение.',
            'a_items_fast_destinations' => 'Добавить ABC и скорость доставки, чтобы A-товары приоритизировали быстрые направления.',
            'draft_creation' => 'Оставить сценарий рекомендаций/экспорта или подключить безопасное создание черновика, если площадка его поддерживает.',
            'crossdock_drop_off_point' => 'Выбрать точку отгрузки Ozon для кросс-докинга перед созданием черновика.',
            default => "Проверить частичный блок: {$label}.",
        };
    }

    private function isBlockingRequirement(string $sectionKey, string $status): bool
    {
        if ($status === 'ready') {
            return false;
        }

        return in_array($sectionKey, ['api_sources', 'calculations', 'marketplace_policy'], true);
    }

    private function requirementsDecisionText(float $coveragePercent, int $missing, int $partial): string
    {
        if ($coveragePercent >= 85 && $missing === 0) {
            return 'Ключевые требования умного автопланирования покрыты; план можно показывать как уверенную рекомендацию.';
        }

        if ($coveragePercent >= 60) {
            return "План можно использовать как защищённую рекомендацию: {$partial} пунктов частичные, {$missing} требуют данных.";
        }

        return "План пока нельзя считать умной рекомендацией: {$missing} пунктов требуют данных или доработки.";
    }

    private function summaryText(string $status, int $ready, int $partial, int $missing): string
    {
        return match ($status) {
            'ready' => "План покрывает ключевые блоки умного автопланирования: {$ready} пунктов включены.",
            'partial' => "План работает как защищённый расчёт: {$ready} пунктов включены, {$partial} частично, {$missing} требуют данных или настройки.",
            default => "Плану не хватает входных данных: {$missing} пунктов требуют подключения источников.",
        };
    }

    private function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function selectedDestinationsCount(array $params): int
    {
        return count((array) ($params['cluster_ids'] ?? [])) + count((array) ($params['warehouse_ids'] ?? []));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function selectedDestinationsLabel(array $params, string $marketplace): string
    {
        $count = $this->selectedDestinationsCount($params);
        if ($count > 0) {
            return $count . ' выбрано';
        }

        return $marketplace === 'ozon' ? 'все кластеры' : 'все склады';
    }

    /**
     * @param array<string, mixed> $economics
     * @param array<string, mixed> $freshness
     */
    private function hasEconomics(array $economics, array $freshness): bool
    {
        return $this->hasValue($economics['total_expected_profit'] ?? null)
            || (int) ($freshness['unit_economics']['items'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function summaryQtyLabel(array $summary): ?string
    {
        if ($summary === []) {
            return null;
        }

        return (int) ($summary['lines'] ?? 0) . ' строк, ' . (int) ($summary['qty'] ?? 0) . ' шт.';
    }

    /**
     * @param array<string, mixed> $territorial
     */
    private function territorialStatus(array $territorial): ?string
    {
        return $territorial['method'] ?? $territorial['status'] ?? null;
    }

    /**
     * @param array<string, mixed> $territorial
     */
    private function hasTerritorialCoverage(array $territorial): bool
    {
        return (float) ($territorial['source_coverage']['critical_coverage_percent'] ?? 0) >= 45;
    }

    /**
     * @param array<string, mixed> $territorial
     */
    private function hasDemandClosureRanking(array $territorial): bool
    {
        return ! empty($territorial['demand_closure_ranking']) || $this->hasTerritorialCoverage($territorial);
    }

    /**
     * @param array<string, mixed> $territorial
     */
    private function demandClosureLabel(array $territorial): ?string
    {
        $ranking = $territorial['demand_closure_ranking'] ?? [];
        if (is_array($ranking) && $ranking !== []) {
            $top = reset($ranking);
            if (is_array($top)) {
                $name = (string) ($top['name'] ?? '');
                $score = $top['demand_closure_score'] ?? null;

                return trim($name . ($score !== null ? ' · ' . $score . ' баллов' : ''));
            }
        }

        return $territorial['source_coverage']['human_status'] ?? null;
    }

    /**
     * @param array<string, mixed> $constraints
     */
    private function constraintsLabel(mixed $source, array $constraints): ?string
    {
        if ($this->hasValue($source)) {
            return $this->sourceLabel((string) $source);
        }

        $parts = [];
        foreach ([
            'files_count' => 'файлов',
            'matched_rules' => 'правил',
            'constraints_count' => 'правил',
            'matched_constraints_count' => 'совпавших правил',
            'marketplace_need_lines' => 'потребностей',
            'matched_marketplace_need_lines' => 'совпавших потребностей',
            'unmatched_marketplace_need_qty' => 'шт. потребности на проверку',
        ] as $key => $label) {
            $value = (int) ($constraints[$key] ?? 0);
            if ($value > 0) {
                $parts[] = $value . ' ' . $label;
            }
        }

        if ($parts === [] && $this->hasValue($constraints['source_file'] ?? null)) {
            $parts[] = (string) $constraints['source_file'];
        }

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /**
     * @param array<string, mixed> $constraints
     */
    private function marketplaceNeedsLabel(mixed $source, array $constraints): ?string
    {
        $parts = [];
        if ($this->hasValue($source)) {
            $parts[] = $this->sourceLabel((string) $source);
        }

        foreach ([
            'file_marketplace_needs_count' => 'строк потребности из файла',
            'marketplace_needs_count' => 'строк потребности',
            'matched_marketplace_need_lines' => 'совпавших потребностей',
            'total_file_marketplace_need_qty' => 'шт. потребности',
            'total_marketplace_need_qty' => 'шт. потребности',
            'unmatched_marketplace_need_qty' => 'шт. потребности на проверку',
            'marketplace_need_remaining_delta_qty' => 'шт. остаточной потребности',
        ] as $key => $label) {
            $value = (int) ($constraints[$key] ?? 0);
            if ($value > 0) {
                $parts[] = $value . ' ' . $label;
            }
        }

        return $parts !== [] ? implode(', ', array_values(array_unique($parts))) : null;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $capabilities
     */
    private function crossdockDropOffItem(string $marketplace, array $params, array $capabilities): array
    {
        if ($marketplace !== 'ozon' || empty($capabilities['supports_draft_creation'])) {
            return $this->item(
                'crossdock_drop_off_point',
                'Точка отгрузки для кросс-докинга',
                'ready',
                'Для этой площадки backend не выполняет кросс-докинг создание черновика.',
                'не требуется',
            );
        }

        $method = (string) ($params['draft_supply_method'] ?? 'direct');
        $dropOffPoint = $params['drop_off_point_warehouse_id'] ?? $params['crossdock_drop_off_point_warehouse_id'] ?? null;

        if ($method !== 'crossdock') {
            return $this->item(
                'crossdock_drop_off_point',
                'Точка отгрузки для кросс-докинга',
                'ready',
                'План использует прямое создание черновика Ozon, точка кросс-докинга не нужна.',
                'не требуется',
            );
        }

        return $this->item(
            'crossdock_drop_off_point',
            'Точка отгрузки для кросс-докинга',
            $this->hasValue($dropOffPoint) ? 'ready' : 'partial',
            $this->hasValue($dropOffPoint)
                ? 'Кросс-докинг включён, точка отгрузки выбрана и будет повторно проверена перед созданием черновика.'
                : 'Кросс-докинг включён, но перед созданием черновика нужно выбрать точку отгрузки Ozon.',
            $this->hasValue($dropOffPoint) ? 'точка #' . (string) $dropOffPoint : null,
        );
    }

    /**
     * @param array<string, mixed> $territorial
     * @param array<string, mixed> $params
     */
    private function ktrBaselineItem(array $territorial, array $params): array
    {
        $ktr = is_array($territorial['ktr'] ?? null) ? $territorial['ktr'] : [];
        $fixation = is_array($ktr['fixation'] ?? null) ? $ktr['fixation'] : [];
        $controlLoop = is_array($ktr['control_loop'] ?? null) ? $ktr['control_loop'] : [];

        $fixedBaseline = $fixation['fixed_baseline_value']
            ?? $controlLoop['fixed_baseline_value']
            ?? $params['baseline_ktr']
            ?? null;
        $trackingStatus = (string) ($fixation['tracking_status'] ?? '');

        $isFixed = $this->hasValue($fixedBaseline)
            && $trackingStatus !== 'not_fixed';

        if (! $this->hasValue($ktr['value'] ?? null)) {
            return $this->item(
                'ktr_baseline',
                'База сравнения КТР',
                'partial',
                'КТР ещё не рассчитан, поэтому базу сравнения пока нельзя зафиксировать.',
            );
        }

        return $this->item(
            'ktr_baseline',
            'База сравнения КТР',
            $isFixed ? 'ready' : 'partial',
            $isFixed
                ? 'Текущий КТР зафиксирован как база: следующие планы можно сравнивать с ним.'
                : 'КТР посчитан, но ещё не зафиксирован как база сравнения.',
            $isFixed ? 'база ' . (string) $fixedBaseline . '%' : 'ожидает фиксации',
        );
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string> $path
     */
    private function boolPath(array $array, array $path): bool
    {
        $cursor = $array;
        foreach ($path as $key) {
            if (! is_array($cursor) || ! array_key_exists($key, $cursor)) {
                return false;
            }
            $cursor = $cursor[$key];
        }

        return (bool) $cursor;
    }

    private function modeLabel(string $mode): string
    {
        return match ($mode) {
            AutoSupplyPlan::MODE_PROTECT_OOS, AutoSupplyPlan::MODE_ANTI_OOS => 'Защитить от отсутствия товара',
            AutoSupplyPlan::MODE_IMPROVE_LOCALITY => 'Улучшить локальность',
            AutoSupplyPlan::MODE_MAX_PROFIT => 'Максимум прибыли',
            AutoSupplyPlan::MODE_POST_PROMO_CAREFUL => 'Осторожно после акции',
            AutoSupplyPlan::MODE_CASH_SAFE => 'Экономия бюджета',
            default => 'Баланс',
        };
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'posting_fbo_v3' => 'заказы FBO из API Ozon',
            'ozon_order_report' => 'отчёт заказов Ozon',
            'analytics_stocks' => 'аналитика остатков маркетплейса',
            'product_info_stocks' => 'остатки товаров Ozon',
            'inventory_warehouses' => 'внутренние остатки',
            'turnover_stocks' => 'оборачиваемость Ozon',
            'average_delivery_time_summary' => 'сводка среднего времени доставки Ozon',
            'supply_orders' => 'заявки поставки',
            'constraint_file' => 'файл ограничений/потребностей',
            'marketplace_need_rules' => 'правила потребностей маркетплейса',
            'constraint_rules' => 'правила ограничений маркетплейса',
            default => $source,
        };
    }
}
