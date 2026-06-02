<?php

namespace App\Services\AutoSupplyPlanning;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

class MarketplaceConstraintFileParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(UploadedFile|string $file, string $marketplace): array
    {
        $rows = $this->readRows($file);
        if ($rows === []) {
            return [
                'success' => false,
                'message' => 'Файл пуст или не содержит строк с ограничениями',
                'errors' => ['Не найдены строки данных'],
            ];
        }

        $marketplace = $marketplace === 'yandex' ? 'yandex_market' : $marketplace;
        $headerInfo = $this->extractHeaderAndDataRows($rows);
        if ($headerInfo === null) {
            return [
                'success' => false,
                'message' => 'Не найдена строка заголовков файла ограничений',
                'errors' => ['Файл должен содержать колонки товара, склада/кластера, лимита, потребности или коэффициента'],
            ];
        }

        $headers = $headerInfo['headers'];
        $rows = $headerInfo['rows'];
        $constraints = [];
        $errors = [];
        $warnings = [];
        if ($headerInfo['skipped_preamble_rows'] > 0) {
            $warnings[] = "Пропущено служебных строк до заголовков: {$headerInfo['skipped_preamble_rows']}";
        }
        $isOzon = $marketplace === 'ozon';

        foreach ($rows as $index => $row) {
            $lineNumber = $headerInfo['header_row_number'] + $index + 1;
            $assoc = $this->associateRow($headers, $row);

            if ($this->isBlankRow($assoc)) {
                continue;
            }

            $sku = $this->firstValue($assoc, ['sku', 'offer_id', 'article', 'vendor_code', 'seller_sku', 'fbo_sku', 'barcode']);
            $id = $isOzon
                ? $this->firstValue($assoc, ['cluster_id', 'cluster', 'cluster_name', 'macrolocal_cluster_id', 'macrolocal_cluster_name', 'destination'])
                : $this->firstValue($assoc, ['warehouse_id', 'warehouse', 'warehouse_name', 'destination']);
            $name = $isOzon
                ? $this->firstValue($assoc, ['cluster_name', 'cluster', 'macrolocal_cluster_name', 'destination'])
                : $this->firstValue($assoc, ['warehouse_name', 'warehouse', 'destination']);

            if (($id === null || $id === '') && ($sku === null || $sku === '')) {
                $warnings[] = "Строка {$lineNumber}: не найден ни артикул, ни " . ($isOzon ? 'кластер' : 'склад') . ', строка пропущена';
                continue;
            }

            $maxQtyRaw = $this->firstValue($assoc, ['max_qty', 'limit_qty', 'available_qty', 'capacity', 'supply_limit', 'remaining_limit', 'allowed_qty']);
            $needQtyRaw = $this->firstValue($assoc, ['need_qty', 'required_qty', 'demand_qty', 'recommended_qty', 'warehouse_need_qty', 'destination_need_qty']);
            $isAvailableRaw = $this->firstValue($assoc, ['is_available', 'available', 'status', 'availability_status', 'allow_unload', 'can_supply']);
            $isBlockedRaw = $this->firstValue($assoc, ['is_blocked', 'blocked', 'supply_forbidden', 'supply_ban']);
            $coefficientRaw = $this->firstValue($assoc, ['coefficient']);
            $acceptanceCoefficientRaw = $this->firstValue($assoc, ['acceptance_coefficient']);
            $deliveryCoefficientRaw = $this->firstValue($assoc, ['delivery_coefficient']);
            $storageCoefficientRaw = $this->firstValue($assoc, ['storage_coefficient']);
            $logisticsCoefficientRaw = $this->firstValue($assoc, ['logistics_coefficient']);
            $fallbackCoefficientRaw = $coefficientRaw
                ?? $acceptanceCoefficientRaw
                ?? $deliveryCoefficientRaw
                ?? $storageCoefficientRaw
                ?? $logisticsCoefficientRaw;
            $reason = $this->firstValue($assoc, ['reason', 'comment', 'note', 'restriction_reason', 'limit_reason']);
            $sourceType = $needQtyRaw !== null && $needQtyRaw !== ''
                ? ($maxQtyRaw !== null && $maxQtyRaw !== '' ? 'constraint_and_need' : 'marketplace_need')
                : 'marketplace_constraint';

            $constraint = [
                $isOzon ? 'cluster_id' : 'warehouse_id' => $id !== null && $id !== '' ? $this->normalizeId($id) : null,
                $isOzon ? 'cluster_name' : 'warehouse_name' => $name,
                'sku' => $sku !== null && $sku !== '' ? (string) $sku : null,
                'max_qty' => $maxQtyRaw !== null && $maxQtyRaw !== '' ? max(0, (int) round($this->parseNumber($maxQtyRaw))) : null,
                'need_qty' => $needQtyRaw !== null && $needQtyRaw !== '' ? max(0, (int) round($this->parseNumber($needQtyRaw))) : null,
                'is_available' => $this->parseAvailability($isAvailableRaw, $isBlockedRaw),
                'coefficient' => $fallbackCoefficientRaw !== null && $fallbackCoefficientRaw !== '' ? $this->parseCoefficient($fallbackCoefficientRaw) : null,
                'acceptance_coefficient' => $acceptanceCoefficientRaw !== null && $acceptanceCoefficientRaw !== '' ? $this->parseCoefficient($acceptanceCoefficientRaw) : null,
                'delivery_coefficient' => $deliveryCoefficientRaw !== null && $deliveryCoefficientRaw !== '' ? $this->parseCoefficient($deliveryCoefficientRaw) : null,
                'storage_coefficient' => $storageCoefficientRaw !== null && $storageCoefficientRaw !== '' ? $this->parseCoefficient($storageCoefficientRaw) : null,
                'logistics_coefficient' => $logisticsCoefficientRaw !== null && $logisticsCoefficientRaw !== '' ? $this->parseCoefficient($logisticsCoefficientRaw) : null,
                'reason' => $reason ?: $this->defaultReason($maxQtyRaw, $isAvailableRaw, $isBlockedRaw),
                'scope' => $this->constraintScope($id, $sku, $isOzon),
                'source_type' => $sourceType,
            ];

            if ($constraint['max_qty'] === null && $constraint['need_qty'] === null && $constraint['is_available'] === true && $constraint['coefficient'] === null) {
                $warnings[] = "Строка {$lineNumber}: нет лимита, потребности, доступности или коэффициента";
            }

            $constraints[] = array_filter($constraint, static fn ($value): bool => $value !== null && $value !== '');
        }

        $constraintKey = $isOzon ? 'cluster_constraints' : 'warehouse_constraints';

        $constraintCollection = collect($constraints);
        $sourceTypeCounts = $constraintCollection
            ->countBy(fn (array $item): string => (string) ($item['source_type'] ?? 'marketplace_constraint'))
            ->all();
        $coefficientFields = ['coefficient', 'acceptance_coefficient', 'delivery_coefficient', 'storage_coefficient', 'logistics_coefficient'];
        $coefficientLines = $constraintCollection
            ->filter(fn (array $item): bool => collect($coefficientFields)->contains(fn (string $field): bool => isset($item[$field])))
            ->count();
        $blockedLines = $constraintCollection
            ->filter(fn (array $item): bool => array_key_exists('is_available', $item) && $item['is_available'] === false)
            ->count();
        $limitLines = $constraintCollection
            ->filter(fn (array $item): bool => isset($item['max_qty']))
            ->count();

        return [
            'success' => true,
            'message' => 'Файл ограничений разобран',
            'data' => [
                'marketplace' => $isOzon ? 'Ozon' : ($marketplace === 'wildberries' ? 'Wildberries' : $marketplace),
                'file' => $this->fileMetadata($file),
                $constraintKey => $constraints,
                'summary' => [
                    'rows_total' => count($rows),
                    'constraints_count' => count($constraints),
                    'marketplace_needs_count' => $constraintCollection->filter(fn (array $item): bool => isset($item['need_qty']))->count(),
                    'total_marketplace_need_qty' => $constraintCollection->sum(fn (array $item): int => isset($item['need_qty']) ? (int) $item['need_qty'] : 0),
                    'sku_specific_count' => $constraintCollection->filter(fn (array $item): bool => !empty($item['sku'] ?? null))->count(),
                    'global_sku_count' => $constraintCollection->filter(fn (array $item): bool => !empty($item['sku'] ?? null) && empty($item[$isOzon ? 'cluster_id' : 'warehouse_id'] ?? null))->count(),
                    'destination_only_count' => $constraintCollection->filter(fn (array $item): bool => empty($item['sku'] ?? null) && !empty($item[$isOzon ? 'cluster_id' : 'warehouse_id'] ?? null))->count(),
                    'source_type_counts' => $sourceTypeCounts,
                    'limit_lines_count' => $limitLines,
                    'blocked_lines_count' => $blockedLines,
                    'coefficient_lines_count' => $coefficientLines,
                    'planning_roles' => [
                        'used_as_constraints' => $limitLines > 0 || $blockedLines > 0,
                        'used_as_marketplace_needs' => ($sourceTypeCounts['marketplace_need'] ?? 0) > 0 || ($sourceTypeCounts['constraint_and_need'] ?? 0) > 0,
                        'used_as_coefficients' => $coefficientLines > 0,
                    ],
                    'decision_ru' => $this->summaryDecisionText(
                        constraintsCount: count($constraints),
                        limitLines: $limitLines,
                        blockedLines: $blockedLines,
                        marketplaceNeedsCount: $constraintCollection->filter(fn (array $item): bool => isset($item['need_qty']))->count(),
                        coefficientLines: $coefficientLines,
                        warningsCount: count($warnings),
                    ),
                    'warnings_count' => count($warnings),
                    'errors_count' => count($errors),
                    'header_row_number' => $headerInfo['header_row_number'],
                    'skipped_preamble_rows' => $headerInfo['skipped_preamble_rows'],
                    'human_status' => count($constraints) > 0
                        ? 'Ограничения готовы к использованию в расчёте'
                        : 'Не найдено применимых ограничений',
                    'parser_version' => 'marketplace-constraints-4',
                    'supported_fields' => [
                        'товар + кластер/склад',
                        'глобальный лимит SKU',
                        'лимит направления целиком',
                        'доступность направления и обратные флаги запрета',
                        'отдельные коэффициенты приёмки/доставки/логистики/хранения',
                        'потребность склада/кластера как отдельный факт',
                    ],
                ],
                'warnings' => $warnings,
                'errors' => $errors,
            ],
        ];
    }

    private function summaryDecisionText(
        int $constraintsCount,
        int $limitLines,
        int $blockedLines,
        int $marketplaceNeedsCount,
        int $coefficientLines,
        int $warningsCount,
    ): string {
        if ($constraintsCount <= 0) {
            return 'Файл разобран, но применимых правил не найдено: проверьте заголовки и строки данных.';
        }

        $parts = ['Файл будет использоваться как источник планирования.'];
        if ($limitLines > 0 || $blockedLines > 0) {
            $parts[] = "Ограничения: {$limitLines} строк с лимитами, {$blockedLines} закрытых направлений.";
        }
        if ($marketplaceNeedsCount > 0) {
            $parts[] = "Потребности маркетплейса: {$marketplaceNeedsCount} строк пойдут отдельным фактом в модуль выбора.";
        }
        if ($coefficientLines > 0) {
            $parts[] = "Коэффициенты: {$coefficientLines} строк будут влиять на территориальный балл.";
        }
        if ($warningsCount > 0) {
            $parts[] = "Есть предупреждения: проверьте строки файла перед расчётом.";
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function fileMetadata(UploadedFile|string $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);
        $size = $path && is_file($path) ? filesize($path) : null;
        $hash = $path && is_file($path) ? hash_file('sha256', $path) : null;

        return [
            'name' => $name,
            'size_bytes' => $size !== false ? $size : null,
            'sha256' => $hash,
            'parsed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<list<mixed>>
     */
    private function readRows(UploadedFile|string $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : $file;
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();

            return array_values(array_filter(
                $sheet->toArray(null, true, true, false),
                fn (array $row): bool => ! $this->isRawBlankRow($row)
            ));
        }

        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $delimiter = $this->detectDelimiter($content);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (! $this->isRawBlankRow($row)) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }

    private function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\n") ?: $content;
        $scores = [
            ';' => substr_count($firstLine, ';'),
            ',' => substr_count($firstLine, ','),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($scores);

        return (string) array_key_first($scores);
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = str_replace(['ё', '№'], ['е', ''], $header);
        $header = preg_replace('/[()\[\]{}]/u', '', $header) ?? $header;
        $header = str_replace([' ', '-', '.', '/', '\\', ':', ',', ';'], ['_', '_', '_', '_', '_', '_', '_', '_'], $header);
        $header = preg_replace('/_+/u', '_', $header) ?? $header;
        $header = trim($header, '_');

        return match ($header) {
            'кластер_id', 'id_кластера', 'clusterid', 'cluster_id', 'cluster', 'id_макрокластера', 'макрокластер_id', 'macrolocal_cluster_id', 'clusterids', 'идентификатор_кластера', 'ид_кластера' => 'cluster_id',
            'кластер', 'название_кластера', 'cluster_name', 'макрокластер', 'макрокластер_доставки', 'название_макрокластера', 'macrolocal_cluster_name', 'кластер_поставки', 'регион_кластера', 'название_макрокластера_доставки' => 'cluster_name',
            'склад_id', 'id_склада', 'warehouseid', 'warehouse_id', 'warehouse', 'warehouseids', 'идентификатор_склада', 'ид_склада' => 'warehouse_id',
            'склад', 'название_склада', 'warehouse_name', 'склад_поставки', 'точка_поставки', 'пункт_назначения', 'склад_назначения', 'склад_приемки', 'склад_приёмки' => 'warehouse_name',
            'направление', 'регион', 'destination', 'география', 'geo', 'федеральный_округ', 'регион_доставки', 'направление_поставки' => 'destination',
            'артикул', 'sku', 'offer_id', 'offerid', 'vendor_code', 'код_товара', 'артикул_продавца', 'seller_sku', 'fbo_sku', 'ozon_sku', 'sku_ozon', 'ozon_id', 'баркод', 'barcode', 'штрихкод', 'штрих_код', 'ean', 'gtin', 'nm_id', 'nmid', 'ваш_sku', 'ваш_артикул', 'артикул_wb', 'артикул_ozon', 'код_номенклатуры' => 'sku',
            'макс_количество', 'максимальное_количество', 'максимум_к_поставке', 'лимит', 'лимит_шт', 'лимит_поставки', 'доступно_шт', 'доступно_к_поставке_шт', 'доступно_для_поставки_шт', 'остаток_лимита', 'остаток_лимита_шт', 'свободный_лимит', 'свободный_лимит_шт', 'max_qty', 'limit_qty', 'available_qty', 'supply_limit', 'remaining_limit', 'allowed_qty', 'можно_поставить_шт', 'доступное_количество', 'разрешенное_количество', 'разрешённое_количество', 'ограничение_шт', 'лимит_на_поставку', 'максимальная_поставка' => 'max_qty',
            'потребность', 'потребность_склада', 'потребность_склада_шт', 'потребность_кластера', 'потребность_кластера_шт', 'нужно_поставить', 'нужно_поставить_шт', 'рекомендовано_поставить', 'рекомендовано_поставить_шт', 'need_qty', 'required_qty', 'demand_qty', 'recommended_qty', 'warehouse_need_qty', 'destination_need_qty', 'объем_потребности', 'объем_потребности_шт', 'объём_потребности', 'объём_потребности_шт', 'количество_к_поставке', 'количество_к_поставке_шт', 'рекомендация_к_поставке', 'рекомендуемое_количество', 'план_поставки', 'минимальная_потребность' => 'need_qty',
            'доступен', 'доступность', 'статус', 'статус_поставки', 'статус_приемки', 'статус_приёмки', 'is_available', 'available', 'availability_status', 'allowunload', 'allow_unload', 'можно_поставить', 'can_supply', 'разрешена_поставка', 'приемка_доступна', 'приёмка_доступна', 'доступно_для_поставки' => 'is_available',
            'запрет', 'запрет_поставки', 'поставка_запрещена', 'запрещено_поставлять', 'заблокировано', 'заблокирован', 'blocked', 'is_blocked', 'supply_forbidden', 'supply_ban', 'приемка_закрыта', 'приёмка_закрыта' => 'is_blocked',
            'коэффициент', 'коэф', 'coef', 'coefficient', 'итоговый_коэффициент', 'общий_коэффициент' => 'coefficient',
            'коэффициент_приемки', 'коэффициент_приёмки', 'коэф_приемки', 'коэф_приёмки', 'коэффициент_приемки_коробов', 'коэффициент_приёмки_коробов', 'коэф_приемки_коробов', 'коэф_приёмки_коробов', 'коэффициент_приемки_монопаллет', 'коэффициент_приёмки_монопаллет', 'acceptance_coefficient', 'acceptancecoef', 'acceptance_coef', 'warehouse_coefficient' => 'acceptance_coefficient',
            'коэффициент_доставки', 'коэф_доставки', 'коэффициент_логистики_до_клиента', 'delivery_coefficient', 'deliverycoef', 'delivery_coef' => 'delivery_coefficient',
            'коэффициент_хранения', 'коэф_хранения', 'коэффициент_платного_хранения', 'storage_coefficient', 'storagecoef', 'storage_coef' => 'storage_coefficient',
            'коэффициент_логистики', 'коэф_логистики', 'коэффициент_перевозки', 'logistics_coefficient', 'logisticscoef', 'logistics_coef' => 'logistics_coefficient',
            'причина', 'причина_ограничения', 'комментарий', 'comment', 'note', 'reason', 'restriction_reason', 'limit_reason' => 'reason',
            default => $header,
        };
    }

    /**
     * @param list<list<mixed>> $rows
     * @return array{headers:list<string>, rows:list<list<mixed>>, header_row_number:int, skipped_preamble_rows:int}|null
     */
    private function extractHeaderAndDataRows(array $rows): ?array
    {
        $bestIndex = null;
        $bestScore = 0;

        foreach ($rows as $index => $row) {
            $headers = array_map(fn ($header): string => $this->normalizeHeader((string) $header), $row);
            $score = $this->headerScore($headers);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }

            if ($score >= 3) {
                break;
            }
        }

        if ($bestIndex === null || $bestScore < 2) {
            return null;
        }

        return [
            'headers' => array_map(fn ($header): string => $this->normalizeHeader((string) $header), $rows[$bestIndex]),
            'rows' => array_slice($rows, $bestIndex + 1),
            'header_row_number' => $bestIndex + 1,
            'skipped_preamble_rows' => $bestIndex,
        ];
    }

    /**
     * @param list<string> $headers
     */
    private function headerScore(array $headers): int
    {
        $knownHeaders = [
            'cluster_id', 'cluster_name', 'warehouse_id', 'warehouse_name', 'destination',
            'sku', 'max_qty', 'need_qty', 'is_available', 'is_blocked', 'coefficient',
            'acceptance_coefficient', 'delivery_coefficient', 'storage_coefficient',
            'logistics_coefficient', 'reason',
        ];

        $unique = array_unique(array_filter($headers, static fn (string $header): bool => $header !== ''));
        $knownCount = count(array_intersect($unique, $knownHeaders));
        $hasDestination = count(array_intersect($unique, ['cluster_id', 'cluster_name', 'warehouse_id', 'warehouse_name', 'destination'])) > 0;
        $hasRule = count(array_intersect($unique, ['max_qty', 'need_qty', 'is_available', 'is_blocked', 'coefficient', 'acceptance_coefficient', 'delivery_coefficient', 'storage_coefficient', 'logistics_coefficient'])) > 0;

        return $knownCount + ($hasDestination ? 1 : 0) + ($hasRule ? 1 : 0);
    }

    /**
     * @param list<string> $headers
     * @param list<mixed> $row
     * @return array<string, mixed>
     */
    private function associateRow(array $headers, array $row): array
    {
        $assoc = [];
        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }
            $assoc[$header] = isset($row[$index]) ? trim((string) $row[$index]) : null;
        }

        return $assoc;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isBlankRow(array $row): bool
    {
        return $this->isRawBlankRow(array_values($row));
    }

    /**
     * @param list<mixed> $row
     */
    private function isRawBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private function firstValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }

        return null;
    }

    private function normalizeId(string $value): int|string
    {
        $value = trim($value);

        return ctype_digit($value) ? (int) $value : $value;
    }

    private function parseAvailability(?string $value, ?string $blockedValue = null): bool
    {
        if ($blockedValue !== null && trim($blockedValue) !== '') {
            return ! $this->parseBooleanLike($blockedValue);
        }

        if ($value === null || trim($value) === '') {
            return true;
        }

        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace('ё', 'е', $normalized);

        if (in_array($normalized, [
            'нет', 'нельзя', 'запрещено', 'закрыт', 'закрыто', 'закрыта', 'недоступен', 'недоступно',
            'нет мест', 'мест нет', 'false', '0', 'no', 'blocked', 'unavailable', 'not_available',
        ], true)) {
            return false;
        }

        foreach (['закрыт', 'запрещен', 'запрещено', 'недоступ', 'нет мест', 'нельзя'] as $closedMarker) {
            if (str_contains($normalized, $closedMarker)) {
                return false;
            }
        }

        return true;
    }

    private function parseBooleanLike(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace('ё', 'е', $normalized);

        if (in_array($normalized, [
            'да', 'true', '1', 'yes', 'y', 'есть', 'запрещено', 'закрыто', 'закрыт', 'закрыта',
            'blocked', 'ban', 'banned', 'forbidden',
        ], true)) {
            return true;
        }

        foreach (['запрещен', 'запрещено', 'закрыт', 'заблокирован', 'ban', 'forbidden'] as $blockedMarker) {
            if (str_contains($normalized, $blockedMarker)) {
                return true;
            }
        }

        if (in_array($normalized, [
            'нет', 'false', '0', 'no', 'n', 'нет запрета', 'разрешено', 'открыто', 'доступно',
        ], true)) {
            return false;
        }

        return false;
    }

    private function parseNumber(string $value): float
    {
        $normalized = trim($value);
        $normalized = str_replace(["\xc2\xa0", ' ', '×', 'x', 'X'], '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?? '';

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function parseCoefficient(string $value): float
    {
        $isPercent = str_contains($value, '%');
        $number = $this->parseNumber($value);

        return $isPercent && $number > 10
            ? round($number / 100, 4)
            : $number;
    }

    private function defaultReason(?string $maxQtyRaw, ?string $isAvailableRaw, ?string $isBlockedRaw = null): ?string
    {
        if ($isBlockedRaw !== null && $this->parseBooleanLike($isBlockedRaw)) {
            return 'Направление запрещено в файле ограничений';
        }

        if ($isAvailableRaw !== null && ! $this->parseAvailability($isAvailableRaw)) {
            return 'Направление закрыто в файле ограничений';
        }

        if ($maxQtyRaw !== null && $maxQtyRaw !== '' && $this->parseNumber($maxQtyRaw) <= 0) {
            return 'Лимит поставки равен нулю';
        }

        return null;
    }

    private function constraintScope(?string $id, ?string $sku, bool $isOzon): string
    {
        $hasDestination = $id !== null && trim($id) !== '';
        $hasSku = $sku !== null && trim($sku) !== '';

        return match (true) {
            $hasDestination && $hasSku => $isOzon ? 'кластер + SKU' : 'склад + SKU',
            $hasDestination => $isOzon ? 'кластер целиком' : 'склад целиком',
            $hasSku => 'SKU во всех направлениях',
            default => 'не определено',
        };
    }
}
