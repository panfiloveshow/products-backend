<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

class CostPriceParserService
{
    /**
     * Алиасы для колонки артикула
     */
    private array $skuAliases = [
        'артикул',
        'артикул продавца',
        'sku',
        'vendor_code',
        'vendorcode',
        'артикул_продавца',
        'код товара',
        'код',
        'offer_id',
        'offerid',
    ];

    /**
     * Алиасы для колонки себестоимости
     */
    private array $priceAliases = [
        'себестоимость',
        'cost',
        'cost_price',
        'costprice',
        'цена закупки',
        'закупочная цена',
        'закупка',
        'цена',
        'price',
    ];

    /**
     * Парсинг загруженного файла
     * 
     * @param UploadedFile $file
     * @return array{success: bool, items?: array, summary?: array, error?: string, details?: string}
     */
    public function parse(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        try {
            $rows = match ($extension) {
                'xlsx', 'xls' => $this->parseExcel($file),
                'csv' => $this->parseCsv($file),
                default => throw new \InvalidArgumentException("Неподдерживаемый формат файла: {$extension}"),
            };
        } catch (\InvalidArgumentException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'details' => 'Поддерживаемые форматы: .xlsx, .xls, .csv',
            ];
        } catch (\Exception $e) {
            Log::error('Cost price file parse error', [
                'file' => $file->getClientOriginalName(),
                'extension' => $extension,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => 'Ошибка при чтении файла',
                'details' => $e->getMessage(),
            ];
        }

        if (empty($rows)) {
            return [
                'success' => false,
                'error' => 'Файл пустой или не содержит данных',
            ];
        }

        // Определяем индексы колонок
        $headerRow = array_shift($rows);
        $columnIndexes = $this->findColumnIndexes($headerRow);

        if ($columnIndexes['sku'] === null) {
            return [
                'success' => false,
                'error' => 'Не удалось определить колонку с артикулом',
                'details' => 'Ожидаемые заголовки: ' . implode(', ', $this->skuAliases),
            ];
        }

        if ($columnIndexes['price'] === null) {
            return [
                'success' => false,
                'error' => 'Не удалось определить колонку с себестоимостью',
                'details' => 'Ожидаемые заголовки: ' . implode(', ', $this->priceAliases),
            ];
        }

        // Парсим строки данных
        $items = [];
        $seenSkus = [];
        $valid = 0;
        $invalid = 0;

        foreach ($rows as $rowIndex => $row) {
            $sku = $this->cleanString($row[$columnIndexes['sku']] ?? '');
            $priceRaw = $row[$columnIndexes['price']] ?? '';

            if ($columnIndexes['sku'] === $columnIndexes['price'] || $this->cleanString($priceRaw) === '') {
                $split = $this->splitCombinedSkuAndPrice($sku);
                if ($split !== null) {
                    [$sku, $priceRaw] = $split;
                } elseif ($columnIndexes['sku'] === $columnIndexes['price']) {
                    // Артикул и цена в одной колонке, но цену извлечь не удалось
                    // (например, чисто числовой артикул без цены). Не подставляем
                    // сам артикул как себестоимость — помечаем строку без цены.
                    $priceRaw = '';
                }
            }
            
            // Пропускаем пустые строки
            if (empty($sku) && empty($priceRaw)) {
                continue;
            }

            // Пропускаем дубликаты SKU (оставляем первый)
            if (isset($seenSkus[$sku])) {
                continue;
            }
            $seenSkus[$sku] = true;

            // Валидация SKU
            if (empty($sku)) {
                $items[] = [
                    'sku' => '',
                    'cost_price' => null,
                    'status' => 'invalid',
                    'error' => 'Пустой артикул',
                    'row' => $rowIndex + 2, // +2 потому что 1-based и заголовок
                ];
                $invalid++;
                continue;
            }

            // Парсинг и валидация цены
            $costPrice = $this->parsePrice($priceRaw);

            if ($costPrice === null) {
                $items[] = [
                    'sku' => $sku,
                    'cost_price' => null,
                    'status' => 'invalid',
                    'error' => 'Некорректная себестоимость: ' . $priceRaw,
                    'row' => $rowIndex + 2,
                ];
                $invalid++;
                continue;
            }

            if ($costPrice < 0) {
                $items[] = [
                    'sku' => $sku,
                    'cost_price' => null,
                    'status' => 'invalid',
                    'error' => 'Себестоимость не может быть отрицательной',
                    'row' => $rowIndex + 2,
                ];
                $invalid++;
                continue;
            }

            $items[] = [
                'sku' => $sku,
                'cost_price' => $costPrice,
                'status' => 'valid',
            ];
            $valid++;
        }

        if (empty($items)) {
            return [
                'success' => false,
                'error' => 'Не найдено ни одной строки с данными',
            ];
        }

        return [
            'success' => true,
            'data' => [
                'items' => $items,
                'summary' => [
                    'total' => count($items),
                    'valid' => $valid,
                    'invalid' => $invalid,
                ],
            ],
        ];
    }

    /**
     * Парсинг Excel файла (.xlsx, .xls)
     */
    private function parseExcel(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $worksheet = $spreadsheet->getActiveSheet();
        
        $rows = [];
        foreach ($worksheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            
            $rowData = [];
            foreach ($cellIterator as $cell) {
                $rowData[] = $cell->getValue();
            }
            $rows[] = $rowData;
        }

        return $rows;
    }

    /**
     * Парсинг CSV файла с автоопределением разделителя
     */
    private function parseCsv(UploadedFile $file): array
    {
        $content = file_get_contents($file->getPathname());
        
        // Определяем кодировку и конвертируем в UTF-8 если нужно
        $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        
        // Убираем BOM если есть
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        
        // Определяем разделитель по первой строке
        $firstLine = strtok($content, "\n");
        $delimiter = $this->detectCsvDelimiter($firstLine);
        
        // Парсим CSV
        $rows = [];
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = $row;
        }
        
        fclose($handle);
        
        return $rows;
    }

    /**
     * Определение разделителя CSV (точка с запятой или запятая)
     */
    private function detectCsvDelimiter(string $line): string
    {
        $semicolonCount = substr_count($line, ';');
        $commaCount = substr_count($line, ',');
        
        // Если точек с запятой больше — это русская локаль
        return $semicolonCount >= $commaCount ? ';' : ',';
    }

    /**
     * Поиск индексов колонок по заголовкам
     */
    private function findColumnIndexes(array $headerRow): array
    {
        $skuIndex = null;
        $priceIndex = null;
        // Price-матч, попавший в ту же колонку, что и артикул. Используется только как
        // запасной вариант для combined-формата ("sku price" в одной ячейке), когда
        // отдельной колонки с ценой нет.
        $priceCollidingIndex = null;

        foreach ($headerRow as $index => $header) {
            $normalized = $this->normalizeHeader($header);
            $isSku = $this->matchesAliases($normalized, $this->skuAliases);
            $isPrice = $this->matchesAliases($normalized, $this->priceAliases);

            if ($skuIndex === null && $isSku) {
                $skuIndex = $index;
            }

            if ($isPrice) {
                // Отдельная колонка с ценой имеет приоритет: иначе заголовок вроде
                // «Артикул и цена» забирал бы цену в колонку артикула, и себестоимость
                // подставлялась бы равной артикулу (баг с числовыми артикулами).
                if ($priceIndex === null && $index !== $skuIndex) {
                    $priceIndex = $index;
                } elseif ($priceCollidingIndex === null && $index === $skuIndex) {
                    $priceCollidingIndex = $index;
                }
            }
        }

        // Отдельной колонки с ценой нет, но заголовок артикула совпал и с ценой —
        // это combined-формат: цена в той же ячейке, разбираем её ниже сплитом.
        if ($priceIndex === null && $priceCollidingIndex !== null) {
            $priceIndex = $priceCollidingIndex;
        }

        // Если заголовки не найдены — используем колонки 0 и 1
        if ($skuIndex === null && $priceIndex === null && count($headerRow) >= 2) {
            Log::info('Cost price parser: headers not found, using columns 0 and 1');
            $skuIndex = 0;
            $priceIndex = 1;
        }

        return [
            'sku' => $skuIndex,
            'price' => $priceIndex,
        ];
    }

    /**
     * Нормализация заголовка для сравнения
     */
    private function normalizeHeader(?string $header): string
    {
        if ($header === null) {
            return '';
        }
        
        // Приводим к нижнему регистру, убираем лишние пробелы
        $normalized = mb_strtolower(trim($header));
        // Заменяем пробелы и подчеркивания на единый формат
        $normalized = preg_replace('/[\s_]+/', ' ', $normalized);
        
        return $normalized;
    }

    /**
     * Проверка соответствия заголовка алиасам
     */
    private function matchesAliases(string $header, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            if ($header === $alias || str_contains($header, $alias)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Парсинг числа с поддержкой разных форматов
     * Поддерживает: 535,68 | 535.68 | 1 234,56 | 1,234.56
     */
    private function parsePrice($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Если уже число — возвращаем
        if (is_numeric($value)) {
            return (float) $value;
        }

        // Приводим к строке
        $cleaned = (string) $value;
        
        // Убираем пробелы (разделители тысяч)
        $cleaned = str_replace([' ', "\xC2\xA0"], '', $cleaned); // обычный пробел и неразрывный
        
        // Определяем формат числа
        // Если есть и точка и запятая — определяем что является десятичным разделителем
        $hasComma = strpos($cleaned, ',') !== false;
        $hasDot = strpos($cleaned, '.') !== false;
        
        if ($hasComma && $hasDot) {
            // Оба разделителя — последний является десятичным
            $lastComma = strrpos($cleaned, ',');
            $lastDot = strrpos($cleaned, '.');
            
            if ($lastComma > $lastDot) {
                // Формат: 1.234,56 (европейский)
                $cleaned = str_replace('.', '', $cleaned);
                $cleaned = str_replace(',', '.', $cleaned);
            } else {
                // Формат: 1,234.56 (американский)
                $cleaned = str_replace(',', '', $cleaned);
            }
        } elseif ($hasComma) {
            // Только запятая — это десятичный разделитель (русский формат)
            $cleaned = str_replace(',', '.', $cleaned);
        }
        // Если только точка — оставляем как есть
        
        // Убираем всё кроме цифр и точки
        $cleaned = preg_replace('/[^\d.]/', '', $cleaned);
        
        // Проверяем что получилось валидное число
        if (!is_numeric($cleaned)) {
            return null;
        }
        
        return (float) $cleaned;
    }

    /**
     * Защита от файлов, которые Excel/Numbers импортировал одной колонкой:
     * "001/black 1290" должно стать ["001/black", "1290"], а не ценой 0011290.
     *
     * @return array{0:string,1:string}|null
     */
    private function splitCombinedSkuAndPrice(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (! preg_match('/^(.+?)\s+(-?\d[\d\s\x{00A0}]*(?:[,.]\d{1,2})?)\s*(?:₽|руб\.?)?\s*$/u', $value, $matches)) {
            return null;
        }

        $sku = trim($matches[1]);
        $price = trim($matches[2]);

        if ($sku === '' || $price === '') {
            return null;
        }

        return [$sku, $price];
    }

    /**
     * Очистка строки от лишних символов
     */
    private function cleanString($value): string
    {
        if ($value === null) {
            return '';
        }
        
        return trim((string) $value);
    }
}
