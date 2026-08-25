<?php

namespace App\Domains\Ozon\Tariffs;

class OzonPricingMatrix
{
    private array $config;
    private array $logisticsMatrix;
    private array $commissionTable;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? self::loadConfig();
        $this->logisticsMatrix = self::loadLogisticsMatrix();
        $this->commissionTable = self::loadCommissionTable();
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getLogisticsMatrix(): array
    {
        return $this->logisticsMatrix;
    }

    public function getVersion(): string
    {
        return (string) ($this->config['version'] ?? 'unknown');
    }

    public function getEffectiveFrom(): string
    {
        return (string) ($this->config['effective_from'] ?? now()->toDateString());
    }

    public function getAnnouncementDateForVersion(?string $version = null): string
    {
        $version = $version ?: $this->getVersion();
        $announcements = (array) ($this->config['announcement_dates'] ?? []);

        return (string) ($announcements[$version] ?? $this->getEffectiveFrom());
    }

    public function getVersionForDate(?string $date = null): string
    {
        if ($date === null) {
            return $this->getVersion();
        }

        $targetDate = $date;
        $versionHistory = (array) ($this->config['version_history'] ?? []);

        // Sort by effective_from descending
        uksort($versionHistory, fn($a, $b) => strcmp($b, $a));

        foreach ($versionHistory as $version => $effectiveFrom) {
            if ($targetDate >= $effectiveFrom) {
                return (string) $version;
            }
        }

        // Fallback to current version if no history matches
        return $this->getVersion();
    }

    public function resolvePriceSegment(float $price): string
    {
        foreach ($this->config['price_segments'] ?? [] as $segment) {
            $min = (float) ($segment['min'] ?? 0);
            $max = $segment['max'] ?? null;
            if ($price >= $min && ($max === null || $price <= (float) $max)) {
                return (string) ($segment['key'] ?? 'default');
            }
        }

        return '10000+';
    }

    public function resolveCategoryKey(?string $category): string
    {
        $value = mb_strtolower(trim((string) $category));
        if ($value === '') {
            return 'default';
        }

        return match (true) {
            str_contains($value, 'электрон') || str_contains($value, 'смартфон') || str_contains($value, 'ноутбук') => 'электроника',
            str_contains($value, 'одеж') || str_contains($value, 'обув') || str_contains($value, 'бель') => 'одежда',
            default => 'default',
        };
    }

    public function resolveRoute(?string $routeKey = null, ?string $routeLabel = null): array
    {
        $routes = $this->config['routes'] ?? [];
        $aliases = $this->config['route_aliases'] ?? [];

        if ($routeKey && isset($routes[$routeKey])) {
            return $this->formatRoute($routeKey, $routes[$routeKey], false);
        }

        $normalizedLabel = mb_strtolower(trim((string) $routeLabel));
        if ($normalizedLabel !== '') {
            foreach ($aliases as $needle => $aliasKey) {
                if (str_contains($normalizedLabel, (string) $needle) && isset($routes[$aliasKey])) {
                    return $this->formatRoute($aliasKey, $routes[$aliasKey], true);
                }
            }
        }

        $default = $this->config['default_route'] ?? [];
        $defaultKey = (string) ($default['key'] ?? 'cluster_msk');
        $route = $routes[$defaultKey] ?? [];

        return $this->formatRoute($defaultKey, $route, true);
    }

    public function resolveClusterName(?string $clusterName): ?string
    {
        $value = trim((string) $clusterName);
        if ($value === '') {
            return null;
        }

        $matrix = $this->logisticsMatrix['matrix'] ?? [];
        if (isset($matrix[$value])) {
            return $value;
        }

        foreach ($matrix as $sourceName => $destinations) {
            if ($sourceName === $value || isset($destinations[$value])) {
                return $value;
            }
        }

        $normalized = mb_strtolower($value);
        $aliases = $this->logisticsMatrix['cluster_aliases'] ?? [];
        foreach ($aliases as $fragment => $canonical) {
            if (str_contains($normalized, (string) $fragment)) {
                return (string) $canonical;
            }
        }

        foreach ($matrix as $sourceName => $destinations) {
            $sourceNormalized = mb_strtolower((string) $sourceName);
            if ($sourceNormalized === $normalized || str_contains($sourceNormalized, $normalized) || str_contains($normalized, $sourceNormalized)) {
                return (string) $sourceName;
            }

            foreach (array_keys($destinations) as $destinationName) {
                $destinationNormalized = mb_strtolower((string) $destinationName);
                if ($destinationNormalized === $normalized || str_contains($destinationNormalized, $normalized) || str_contains($normalized, $destinationNormalized)) {
                    return (string) $destinationName;
                }
            }
        }

        return $value;
    }

    /**
     * Протухла ли ставка комиссии, снятая из API, для расчёта на дату $pricingDate.
     *
     * Ставка из API — самая точная, пока правила не сменились. С 28.08.2026 у Ozon
     * новая таблица вознаграждений, и значение, снятое до неё, занижает комиссию
     * (по «Электрическим чайникам» 31% против 54%). Если товар не пересинкован
     * после смены правил — считаем по официальной таблице, а не по старому API.
     */
    public function isCommissionObservationStale(?string $observedAt, ?string $pricingDate = null): bool
    {
        $tableEffectiveFrom = trim((string) ($this->commissionTable['effective_from'] ?? ''));
        if ($tableEffectiveFrom === '' || $this->normalizeDate($pricingDate) < $tableEffectiveFrom) {
            return false;
        }

        // Без даты наблюдения не гадаем: ставку мог передать пользователь или
        // расчёт по факту реализации. Синк её проставляет — там гейт и работает.
        $observed = trim((string) $observedAt);

        return $observed !== '' && $observed < $tableEffectiveFrom;
    }

    /**
     * Действует ли на дату наценка за нелокальную продажу.
     *
     * Ozon отменил её для всех кластеров доставки с 09.07.2026
     * (`non_local_markup_cancelled_from`). Исторические расчёты по заказам до
     * этой даты и SKU с активной 60-дневной фиксацией поставки продолжают
     * считаться по старым правилам — они передают свою дату.
     */
    public function isNonLocalMarkupActive(?string $date = null): bool
    {
        $cancelledFrom = trim((string) ($this->config['non_local_markup_cancelled_from'] ?? ''));
        if ($cancelledFrom === '') {
            return true;
        }

        return $this->normalizeDate($date) < $cancelledFrom;
    }

    /**
     * Наценка за нелокальную продажу на кластер назначения.
     *
     * Если передана дата — сначала проверяем `non_local_markup_windows`
     * (временные акции Ozon, например «ДВ 0% c 18.04 по 18.05.2026»).
     * Если окно не подошло — берём постоянное значение из
     * `non_local_markup_by_destination`.
     *
     * По умолчанию $date = сегодня; для исторических расчётов по конкретному
     * заказу передавайте дату его создания (order.in_process_at).
     */
    public function resolveDestinationMarkupPercent(?string $destinationCluster, ?string $date = null): float
    {
        $canonical = $this->resolveClusterName($destinationCluster);
        if ($canonical === null) {
            return 0.0;
        }

        $checkDate = $this->normalizeDate($date);

        if (! $this->isNonLocalMarkupActive($checkDate)) {
            return 0.0;
        }

        $windows = $this->logisticsMatrix['non_local_markup_windows'] ?? [];
        foreach ($windows as $window) {
            if (($window['destination'] ?? null) !== $canonical) {
                continue;
            }
            $from = (string) ($window['from'] ?? '');
            $to = (string) ($window['to'] ?? '');
            if ($from !== '' && $checkDate < $from) {
                continue;
            }
            if ($to !== '' && $checkDate > $to) {
                continue;
            }
            return (float) ($window['percent'] ?? 0.0);
        }

        $map = $this->logisticsMatrix['non_local_markup_by_destination'] ?? [];
        if (array_key_exists($canonical, $map)) {
            return (float) $map[$canonical];
        }

        return 0.0;
    }

    /**
     * Скидка на комиссию за локальный заказ, в процентных пунктах.
     *
     * С 31.07.2026 локальность влияет не на логистику, а на ставку продажи:
     * Ozon поднял базовую комиссию FBO и возвращает часть скидкой, если товар
     * лежит в кластере покупателя. С 30.08 скидка уменьшена, а для кластеров
     * из `excluded_destinations` не действует вовсе.
     *
     * Возвращает 0 для схем вне `schemes` (скидка объявлена только для FBO),
     * для дат вне окон и для исключённых кластеров назначения.
     */
    public function resolveLocalityCommissionDiscountPp(
        string $scheme,
        ?string $destinationCluster = null,
        ?string $date = null
    ): float {
        $discount = (array) ($this->config['locality_commission_discount'] ?? []);
        if ($discount === []) {
            return 0.0;
        }

        $schemes = array_map('strtoupper', (array) ($discount['schemes'] ?? []));
        if ($schemes !== [] && ! in_array(strtoupper($scheme), $schemes, true)) {
            return 0.0;
        }

        $checkDate = $this->normalizeDate($date);
        $canonical = $this->resolveClusterName($destinationCluster);

        foreach ((array) ($discount['windows'] ?? []) as $window) {
            $from = (string) ($window['from'] ?? '');
            $to = (string) ($window['to'] ?? '');
            if ($from !== '' && $checkDate < $from) {
                continue;
            }
            if ($to !== '' && $checkDate > $to) {
                continue;
            }

            $excluded = (array) ($window['excluded_destinations'] ?? []);
            if ($canonical !== null && in_array($canonical, $excluded, true)) {
                return 0.0;
            }

            return (float) ($window['percent_points'] ?? 0.0);
        }

        return 0.0;
    }

    public function resolveClusterLogistics(
        string $scheme,
        float $volume,
        float $price,
        ?string $sourceCluster,
        ?string $destinationCluster,
        ?string $date = null
    ): array {
        $scheme = strtoupper($scheme);
        $bucketLabel = $this->resolveTariffBucketKey($this->resolveVolumeBucketLabel($volume));
        $priceKey = $price <= 300.0 ? 'up_to_300' : 'over_300';
        $sourceCanonical = $this->resolveClusterName($sourceCluster);
        $destinationCanonical = $this->resolveClusterName($destinationCluster);

        $baseCost = null;
        $usedUniversal = false;

        if ($sourceCanonical !== null && $destinationCanonical !== null) {
            $baseCost = $this->logisticsMatrix['matrix'][$sourceCanonical][$destinationCanonical][$bucketLabel][$priceKey] ?? null;
        }

        $rawBaseCost = $baseCost;
        $estimateMarkupPercent = 0.0;

        if ($baseCost === null) {
            $rawBaseCost = (float) ($this->logisticsMatrix['universal_tariffs'][$bucketLabel][$priceKey] ?? 0.0);
            $estimateMarkupPercent = max(
                0.0,
                (float) ($this->config['universal_logistics_fallback_markup_percent'] ?? 50.0)
            );
            $baseCost = $rawBaseCost * (1 + $estimateMarkupPercent / 100);
            $usedUniversal = true;
        }

        return [
            'source_cluster' => $sourceCanonical,
            'destination_cluster' => $destinationCanonical,
            'volume_bucket' => $bucketLabel,
            'base_cost' => round((float) $baseCost, 2),
            'unadjusted_base_cost' => round((float) ($rawBaseCost ?? $baseCost), 2),
            'estimate_markup_percent' => round($estimateMarkupPercent, 2),
            'tariff_source' => $usedUniversal ? 'universal' : 'official',
            'used_universal_tariff' => $usedUniversal,
            'non_local_markup_percent' => $this->resolveDestinationMarkupPercent($destinationCanonical, $date),
            'is_local_sale' => $sourceCanonical !== null && $destinationCanonical !== null && $sourceCanonical === $destinationCanonical,
        ];
    }

    public function resolveCommission(string $scheme, ?string $category, float $price, ?string $date = null): array
    {
        $scheme = strtoupper($scheme);
        $categoryKey = $this->resolveCategoryKey($category);
        $segment = $this->resolvePriceSegment($price);

        // С 28.08.2026 действует официальная таблица вознаграждений по
        // категориям — она точнее самодельного резерва по трём группам.
        $tableEffectiveFrom = (string) ($this->commissionTable['effective_from'] ?? '');
        if ($tableEffectiveFrom !== '' && $this->normalizeDate($date) >= $tableEffectiveFrom) {
            $tableRate = $this->resolveCommissionFromOfficialTable($scheme, $category, $price);
            if ($tableRate !== null) {
                return [
                    'category_key' => $categoryKey,
                    'price_segment' => $segment,
                    'sales_fee_percent' => $tableRate,
                    'tariff_source' => 'ozon_category_table',
                    'tariff_effective_from' => $tableEffectiveFrom,
                    'is_fallback' => false,
                ];
            }
        }

        $commissions = $this->config['commissions'] ?? [];

        $rate = $commissions[$categoryKey][$scheme][$segment]
            ?? $commissions['default'][$scheme][$segment]
            ?? 0.0;

        return [
            'category_key' => $categoryKey,
            'price_segment' => $segment,
            'sales_fee_percent' => (float) $rate,
            'tariff_source' => $categoryKey === 'default' ? 'repo_fallback' : 'official',
            'tariff_effective_from' => $this->getEffectiveFrom(),
            'is_fallback' => $categoryKey === 'default',
        ];
    }

    /**
     * Ставка вознаграждения по официальной таблице Ozon, действующей с 28.08.2026.
     *
     * Матчим по названию: тип товара → категория → основная категория. Если
     * ничего не совпало, возвращаем null — вызывающий код останется на текущих
     * данных (из API или резерва), а не подставит заниженные 10%.
     */
    public function resolveCommissionFromOfficialTable(string $scheme, ?string $category, float $price): ?float
    {
        $schemeKey = match (strtoupper($scheme)) {
            'FBO' => 'FBO',
            'FBO_FRESH', 'FRESH' => 'FBO_FRESH',
            'FBS', 'EXPRESS' => 'FBS',
            'RFBS', 'REALFBS' => 'RFBS',
            default => null,
        };
        if ($schemeKey === null) {
            return null;
        }

        $index = null;
        foreach ($this->commissionCandidates($category) as $key) {
            $index = $this->commissionTable['by_type'][$key]
                ?? $this->commissionTable['by_category'][$key]
                ?? $this->commissionTable['by_main_category'][$key]
                ?? null;
            if ($index !== null) {
                break;
            }
        }
        if ($index === null) {
            return null;
        }

        $rates = $this->commissionTable['rate_sets'][$index][$schemeKey] ?? null;
        if ($rates === null) {
            return null;
        }

        // Ozon 24.08.2026: объявленные с 28.08 тарифы снижены на 3 п.п. («Меры
        // поддержки», вместо скидки за локальность). Поправка живёт в конфиге
        // таблицы и обнуляется при перегенерации по новой официальной таблице.
        $adjustmentPp = (float) ($this->commissionTable['global_adjustment_pp'] ?? 0.0);

        // RFBS в таблице — одна ставка на все ценовые сегменты.
        if (! is_array($rates)) {
            return max(0.0, (float) $rates + $adjustmentPp);
        }

        $tier = match (true) {
            $price <= 100.0 => 0,
            $price <= 300.0 => 1,
            default => 2,
        };

        return isset($rates[$tier]) ? max(0.0, (float) $rates[$tier] + $adjustmentPp) : null;
    }

    /**
     * Варианты названия для поиска в таблице вознаграждений.
     *
     * Товар хранит категорию как «Основная категория > Категория»
     * (например «Галантерея и аксессуары > Аксессуары»), поэтому пробуем сперва
     * самую конкретную часть, затем более общие и строку целиком.
     *
     * @return list<string>
     */
    private function commissionCandidates(?string $category): array
    {
        $raw = trim((string) $category);
        if ($raw === '') {
            return [];
        }

        $candidates = [];
        if (str_contains($raw, '>')) {
            foreach (array_reverse(explode('>', $raw)) as $part) {
                $key = $this->normalizeCommissionKey($part);
                if ($key !== '') {
                    $candidates[] = $key;
                }
            }
        }

        $whole = $this->normalizeCommissionKey($raw);
        if ($whole !== '') {
            $candidates[] = $whole;
        }

        return array_values(array_unique($candidates));
    }

    private function normalizeCommissionKey(?string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));

        return mb_strtolower((string) $normalized);
    }

    public function resolveLogistics(string $scheme, float $volume, ?string $routeKey = null, ?string $routeLabel = null): array
    {
        $scheme = strtoupper($scheme);
        $route = $this->resolveRoute($routeKey, $routeLabel);
        $matrix = $route[$scheme] ?? [];
        $bucket = $this->resolveVolumeBucket($volume);

        $baseCost = match ($bucket) {
            'up_to_1l' => (float) ($matrix['up_to_1l'] ?? 0),
            'up_to_3l' => (float) ($matrix['up_to_3l'] ?? 0),
            'up_to_10l' => (float) ($matrix['up_to_10l'] ?? 0),
            default => (float) ($matrix['up_to_10l'] ?? 0) + (max($volume, 10.0) - 10.0) * (float) ($matrix['over_10l_per_liter'] ?? 0),
        };

        return [
            'route_key' => $route['route_key'],
            'route_label' => $route['route_label'],
            'is_local_sale' => (bool) $route['is_local_sale'],
            'non_local_markup_percent' => (float) $route['non_local_markup_percent'],
            'volume_bucket' => $bucket,
            'base_cost' => round($baseCost, 2),
            'tariff_source' => $route['tariff_source'],
            'tariff_effective_from' => $this->getEffectiveFrom(),
            'is_fallback' => (bool) $route['is_fallback'],
        ];
    }

    public function getSchemeCosts(string $scheme): array
    {
        return $this->config['scheme_costs'][strtoupper($scheme)] ?? [];
    }

    private function normalizeDate(?string $date): string
    {
        $value = trim((string) ($date ?? ''));
        if ($value === '') {
            $value = function_exists('now') ? now()->toDateString() : date('Y-m-d');
        }

        return substr($value, 0, 10);
    }

    private function resolveVolumeBucket(float $volume): string
    {
        return match (true) {
            $volume <= 1.0 => 'up_to_1l',
            $volume <= 3.0 => 'up_to_3l',
            $volume <= 10.0 => 'up_to_10l',
            default => 'over_10l',
        };
    }

    private function resolveVolumeBucketLabel(float $volume): string
    {
        $ranges = $this->logisticsMatrix['volume_ranges'] ?? [];
        foreach ($ranges as $range) {
            $min = (float) ($range['min'] ?? 0);
            $max = $range['max'] ?? null;
            if ($volume >= $min && ($max === null || $volume <= (float) $max)) {
                return (string) ($range['label'] ?? '');
            }
        }

        $last = end($ranges);

        return (string) ($last['label'] ?? 'От 800,001 л');
    }

    private function resolveTariffBucketKey(string $bucketLabel): string
    {
        $target = preg_replace('/\s+/u', '', mb_strtolower($bucketLabel));
        foreach (array_keys($this->logisticsMatrix['universal_tariffs'] ?? []) as $key) {
            $normalizedKey = preg_replace('/\s+/u', '', mb_strtolower((string) $key));
            if ($normalizedKey === $target) {
                return (string) $key;
            }
        }

        return $bucketLabel;
    }

    private function formatRoute(string $routeKey, array $route, bool $fallback): array
    {
        return [
            ...$route,
            'route_key' => $routeKey,
            'route_label' => (string) ($route['label'] ?? $routeKey),
            'tariff_source' => $fallback ? 'repo_fallback' : 'official',
            'tariff_effective_from' => $this->getEffectiveFrom(),
            'is_fallback' => $fallback,
        ];
    }

    private static function loadConfig(): array
    {
        if (function_exists('app')) {
            try {
                $app = app();
                if ($app && $app->bound('config')) {
                    return (array) config('ozon_unit_economics', []);
                }
            } catch (\Throwable) {
                // Fall back to direct config file loading for plain PHPUnit tests.
            }
        }

        $path = dirname(__DIR__, 4).'/config/ozon_unit_economics.php';

        if (is_file($path)) {
            $loaded = require $path;

            return is_array($loaded) ? $loaded : [];
        }

        return [];
    }

    private static function loadCommissionTable(): array
    {
        if (function_exists('app')) {
            try {
                $app = app();
                if ($app && $app->bound('config')) {
                    return (array) config('ozon_commissions_2026_08_28', []);
                }
            } catch (\Throwable) {
                // Fall back to direct config file loading for plain PHPUnit tests.
            }
        }

        $path = dirname(__DIR__, 4).'/config/ozon_commissions_2026_08_28.php';

        if (is_file($path)) {
            $loaded = require $path;

            return is_array($loaded) ? $loaded : [];
        }

        return [];
    }

    private static function loadLogisticsMatrix(): array
    {
        if (function_exists('app')) {
            try {
                $app = app();
                if ($app && $app->bound('config')) {
                    return (array) config('ozon_logistics_matrix', []);
                }
            } catch (\Throwable) {
                // Fall back to direct config file loading for plain PHPUnit tests.
            }
        }

        $path = dirname(__DIR__, 4).'/config/ozon_logistics_matrix.php';

        if (is_file($path)) {
            $loaded = require $path;

            return is_array($loaded) ? $loaded : [];
        }

        return [];
    }
}
