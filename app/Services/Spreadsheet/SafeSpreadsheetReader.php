<?php

namespace App\Services\Spreadsheet;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ZipArchive;

/**
 * Ограниченный reader для недоверенных XLS/XLSX/CSV.
 *
 * Проверяет физический и распакованный размер, число ZIP entries, листов,
 * строк и колонок до полной загрузки workbook. Формулы не вычисляются.
 */
final class SafeSpreadsheetReader
{
    private const MAX_ZIP_ENTRIES = 2048;
    private const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;
    private const MAX_COMPRESSION_RATIO = 100;
    private const MAX_WORKSHEETS = 8;
    private const MAX_CSV_LINE_BYTES = 1024 * 1024;

    /**
     * @return list<list<mixed>>
     */
    public function read(
        UploadedFile|string $file,
        int $maxRows,
        int $maxColumns,
        int $maxFileBytes,
    ): array {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);

        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException('Не удалось прочитать загруженный файл');
        }

        $size = filesize($path);
        if ($size === false || $size > $maxFileBytes) {
            throw new \InvalidArgumentException('Файл превышает допустимый размер');
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx', 'xls' => $this->readExcel($path, $extension, $maxRows, $maxColumns),
            'csv', 'txt' => $this->readCsv($path, $maxRows, $maxColumns, $maxFileBytes),
            default => throw new \InvalidArgumentException('Поддерживаются только XLSX, XLS и CSV'),
        };
    }

    /**
     * @return list<list<mixed>>
     */
    private function readExcel(string $path, string $extension, int $maxRows, int $maxColumns): array
    {
        if ($extension === 'xlsx') {
            $this->assertSafeZipContainer($path);
        }

        $identifiedType = IOFactory::identify($path);
        $allowedType = $extension === 'xlsx' ? 'Xlsx' : 'Xls';
        if (strcasecmp($identifiedType, $allowedType) !== 0) {
            throw new \InvalidArgumentException('Содержимое файла не соответствует его расширению');
        }

        $reader = IOFactory::createReader($identifiedType);
        $reader->setReadDataOnly(true);
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $worksheetInfo = $reader->listWorksheetInfo($path);
        if ($worksheetInfo === [] || count($worksheetInfo) > self::MAX_WORKSHEETS) {
            throw new \InvalidArgumentException('Недопустимое количество листов в файле');
        }

        foreach ($worksheetInfo as $info) {
            if ((int) ($info['totalRows'] ?? 0) > $maxRows) {
                throw new \InvalidArgumentException("Файл содержит больше {$maxRows} строк");
            }
            if ((int) ($info['totalColumns'] ?? 0) > $maxColumns) {
                throw new \InvalidArgumentException("Файл содержит больше {$maxColumns} колонок");
            }
        }

        $firstSheet = (string) ($worksheetInfo[0]['worksheetName'] ?? '');
        if ($firstSheet !== '') {
            $reader->setLoadSheetsOnly([$firstSheet]);
        }
        $reader->setReadFilter(new BoundedReadFilter($maxRows, $maxColumns));

        $spreadsheet = $reader->load($path);
        try {
            $sheet = $spreadsheet->getSheet(0);
            $rows = min($maxRows, max(1, (int) ($worksheetInfo[0]['totalRows'] ?? 1)));
            $columns = min($maxColumns, max(1, (int) ($worksheetInfo[0]['totalColumns'] ?? 1)));
            $lastColumn = Coordinate::stringFromColumnIndex($columns);

            return $sheet->rangeToArray(
                "A1:{$lastColumn}{$rows}",
                null,
                false,
                false,
                false
            );
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * @return list<list<string|null>>
     */
    private function readCsv(string $path, int $maxRows, int $maxColumns, int $maxFileBytes): array
    {
        $content = file_get_contents($path, false, null, 0, $maxFileBytes + 1);
        if ($content === false || $content === '') {
            return [];
        }
        if (strlen($content) > $maxFileBytes) {
            throw new \InvalidArgumentException('Файл превышает допустимый размер');
        }
        if ($this->hasOversizedCsvLine($content)) {
            throw new \InvalidArgumentException('CSV содержит слишком длинную строку');
        }

        $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $firstLine = strtok($content, "\n") ?: $content;
        $delimiter = $this->detectDelimiter($firstLine);
        $handle = fopen('php://temp/maxmemory:5242880', 'w+b');
        if ($handle === false) {
            throw new \InvalidArgumentException('Не удалось открыть CSV');
        }

        try {
            fwrite($handle, $content);
            rewind($handle);

            $rows = [];
            while (($row = fgetcsv($handle, self::MAX_CSV_LINE_BYTES, $delimiter)) !== false) {
                if (count($row) > $maxColumns) {
                    throw new \InvalidArgumentException("CSV содержит больше {$maxColumns} колонок");
                }

                $rows[] = $row;
                if (count($rows) > $maxRows) {
                    throw new \InvalidArgumentException("CSV содержит больше {$maxRows} строк");
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function detectDelimiter(string $line): string
    {
        $scores = [
            ';' => substr_count($line, ';'),
            ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
        ];
        arsort($scores);

        return (string) array_key_first($scores);
    }

    private function hasOversizedCsvLine(string $content): bool
    {
        $lineStart = 0;
        $length = strlen($content);

        for ($offset = 0; $offset < $length; $offset++) {
            if ($content[$offset] !== "\n" && $content[$offset] !== "\r") {
                continue;
            }

            if ($offset - $lineStart > self::MAX_CSV_LINE_BYTES) {
                return true;
            }
            $lineStart = $offset + 1;
        }

        return $length - $lineStart > self::MAX_CSV_LINE_BYTES;
    }

    private function assertSafeZipContainer(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('Повреждённый XLSX-файл');
        }

        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                throw new \InvalidArgumentException('XLSX содержит слишком много внутренних файлов');
            }

            $uncompressedBytes = 0;
            $compressedBytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    throw new \InvalidArgumentException('Не удалось проверить структуру XLSX');
                }

                $name = (string) ($stat['name'] ?? '');
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, '../')) {
                    throw new \InvalidArgumentException('XLSX содержит небезопасный внутренний путь');
                }

                $uncompressedBytes += (int) ($stat['size'] ?? 0);
                $compressedBytes += (int) ($stat['comp_size'] ?? 0);
                if ($uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new \InvalidArgumentException('Распакованный XLSX превышает допустимый размер');
                }
            }

            if ($uncompressedBytes > 0 && $uncompressedBytes > max(1, $compressedBytes) * self::MAX_COMPRESSION_RATIO) {
                throw new \InvalidArgumentException('Подозрительно высокий коэффициент сжатия XLSX');
            }
        } finally {
            $zip->close();
        }
    }
}
