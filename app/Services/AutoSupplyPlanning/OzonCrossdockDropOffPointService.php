<?php

namespace App\Services\AutoSupplyPlanning;

use App\Domains\Ozon\Api\OzonClient;
use App\Domains\Ozon\Api\SuppliesApi;
use App\Models\Integration;

class OzonCrossdockDropOffPointService
{
    /**
     * @return array{points:list<array<string, mixed>>, summary:array<string, mixed>}
     */
    public function list(Integration $integration, string $search = '', int $limit = 100): array
    {
        $api = new SuppliesApi(OzonClient::fromIntegration($integration));
        $points = $api->getCrossdockDropOffPoints($search);
        $points = array_values(array_filter(array_map(
            fn (array $point): ?array => $this->normalizePoint($point),
            $points
        )));

        usort($points, static fn (array $a, array $b): int => [
            $a['type_rank'] ?? 99,
            mb_strtolower((string) ($a['city'] ?? '')),
            mb_strtolower((string) ($a['name'] ?? '')),
        ] <=> [
            $b['type_rank'] ?? 99,
            mb_strtolower((string) ($b['city'] ?? '')),
            mb_strtolower((string) ($b['name'] ?? '')),
        ]);

        $points = array_slice($points, 0, max(1, min(200, $limit)));

        return [
            'points' => array_map(static function (array $point): array {
                unset($point['type_rank']);

                return $point;
            }, $points),
            'summary' => [
                'source' => 'ozon_warehouse_list',
                'source_label_ru' => 'Список точек отгрузки Ozon для кросс-докинга',
                'search' => $search !== '' ? $search : null,
                'total' => count($points),
                'types' => $this->typeCounts($points),
                'usage_ru' => 'Выберите точку отгрузки и передайте её ID как drop_off_point_warehouse_id перед созданием кросс-докинг черновика.',
                'safety_ru' => 'Само создание черновика всё равно выполняется только через предпросмотр, ручное подтверждение и проверку контрольного отпечатка плана.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizePoint(array $point): ?array
    {
        $id = $point['id'] ?? $point['warehouse_id'] ?? null;
        if ($id === null || trim((string) $id) === '') {
            return null;
        }

        $type = (string) ($point['type'] ?? 'sc');
        $warehouseType = (string) ($point['warehouse_type'] ?? '');

        return [
            'id' => (string) $id,
            'warehouse_id' => (int) $id,
            'name' => $point['name'] ?? null,
            'type' => $type,
            'type_label_ru' => $this->typeLabel($type, $warehouseType),
            'warehouse_type' => $warehouseType !== '' ? $warehouseType : null,
            'address' => $point['address'] ?? null,
            'city' => $point['city'] ?? null,
            'region' => $point['region'] ?? null,
            'coordinates' => $point['coordinates'] ?? null,
            'is_active' => array_key_exists('is_active', $point) ? (bool) $point['is_active'] : true,
            'drop_off_point_warehouse_id' => (int) $id,
            'select_hint_ru' => 'Используйте этот ID как точку отгрузки для кросс-докинг поставки.',
            'type_rank' => $this->typeRank($type),
        ];
    }

    private function typeLabel(string $type, string $warehouseType): string
    {
        return match ($type) {
            'pvz' => 'ПВЗ',
            'crossdock' => 'Кросс-док',
            'rfc' => 'РФЦ',
            default => str_contains($warehouseType, 'DELIVERY_POINT') ? 'ПВЗ' : 'Сортировочный центр',
        };
    }

    private function typeRank(string $type): int
    {
        return match ($type) {
            'crossdock' => 0,
            'sc' => 1,
            'pvz' => 2,
            'rfc' => 3,
            default => 9,
        };
    }

    /**
     * @param list<array<string, mixed>> $points
     * @return array<string, int>
     */
    private function typeCounts(array $points): array
    {
        $counts = [];
        foreach ($points as $point) {
            $label = (string) ($point['type_label_ru'] ?? $point['type'] ?? 'Другое');
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
