<?php

namespace App\Domains\Ozon\Tariffs;

class OzonPricingMatrix
{
    private array $config;
    private array $logisticsMatrix;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? self::loadConfig();
        $this->logisticsMatrix = self::loadLogisticsMatrix();
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

    public function resolveDestinationMarkupPercent(?string $destinationCluster): float
    {
        $canonical = $this->resolveClusterName($destinationCluster);
        $map = $this->logisticsMatrix['non_local_markup_by_destination'] ?? [];

        if ($canonical !== null && array_key_exists($canonical, $map)) {
            return (float) $map[$canonical];
        }

        return 0.0;
    }

    public function resolveClusterLogistics(string $scheme, float $volume, float $price, ?string $sourceCluster, ?string $destinationCluster): array
    {
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

        if ($baseCost === null) {
            $baseCost = $this->logisticsMatrix['universal_tariffs'][$bucketLabel][$priceKey] ?? 0.0;
            $usedUniversal = true;
        }

        return [
            'source_cluster' => $sourceCanonical,
            'destination_cluster' => $destinationCanonical,
            'volume_bucket' => $bucketLabel,
            'base_cost' => round((float) $baseCost, 2),
            'tariff_source' => $usedUniversal ? 'universal' : 'official',
            'used_universal_tariff' => $usedUniversal,
            'non_local_markup_percent' => $this->resolveDestinationMarkupPercent($destinationCanonical),
            'is_local_sale' => $sourceCanonical !== null && $destinationCanonical !== null && $sourceCanonical === $destinationCanonical,
        ];
    }

    public function resolveCommission(string $scheme, ?string $category, float $price): array
    {
        $scheme = strtoupper($scheme);
        $categoryKey = $this->resolveCategoryKey($category);
        $segment = $this->resolvePriceSegment($price);
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
