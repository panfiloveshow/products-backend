<?php

namespace Tests\Unit;

use App\Services\Spreadsheet\SafeSpreadsheetWriter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

class SafeSpreadsheetWriterTest extends TestCase
{
    public function test_external_formula_like_value_is_stored_as_literal_text(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        SafeSpreadsheetWriter::setText($sheet, 'A1', '=1+1');

        $this->assertSame('=1+1', $sheet->getCell('A1')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_csv_row_neutralizes_formula_prefixes_without_changing_numeric_values(): void
    {
        $row = SafeSpreadsheetWriter::csvRow([
            '=1+1',
            '+1+1',
            '-1+1',
            '@SUM(A1:A2)',
            "\t=1+1",
            'ordinary text',
            42,
            -5.5,
        ]);

        $this->assertSame([
            "'=1+1",
            "'+1+1",
            "'-1+1",
            "'@SUM(A1:A2)",
            "'\t=1+1",
            'ordinary text',
            42,
            -5.5,
        ], $row);
    }
}
