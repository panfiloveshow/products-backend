<?php

namespace App\Services\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class SafeSpreadsheetWriter
{
    /**
     * Write external text without allowing PhpSpreadsheet to infer a formula.
     */
    public static function setText(Worksheet $sheet, string $coordinate, mixed $value): void
    {
        $sheet->setCellValueExplicit(
            $coordinate,
            $value === null ? '' : (string) $value,
            DataType::TYPE_STRING
        );
    }

    /**
     * Protect string cells that spreadsheet applications may interpret as formulas.
     *
     * Non-string values remain numeric/date-compatible for CSV consumers.
     */
    public static function csvCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<int, mixed>
     */
    public static function csvRow(array $row): array
    {
        return array_map(self::csvCell(...), $row);
    }
}
