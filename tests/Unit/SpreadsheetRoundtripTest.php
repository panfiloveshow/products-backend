<?php

namespace Tests\Unit;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;

class SpreadsheetRoundtripTest extends TestCase
{
    public function test_xlsx_write_and_read_roundtrip(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'SKU');
        $sheet->setCellValue('B1', 'Price');
        $sheet->setCellValue('A2', 'SKU-001');
        $sheet->setCellValue('B2', 1499.5);

        $tmpPath = tempnam(sys_get_temp_dir(), 'pb_xlsx_');
        $this->assertNotFalse($tmpPath);
        $xlsxPath = $tmpPath.'.xlsx';
        @unlink($tmpPath);

        try {
            (new XlsxWriter($spreadsheet))->save($xlsxPath);
            $this->assertFileExists($xlsxPath);

            $loaded = (new XlsxReader())->load($xlsxPath);
            $loadedSheet = $loaded->getActiveSheet();
            $this->assertSame('SKU-001', $loadedSheet->getCell('A2')->getValue());
            $this->assertEquals(1499.5, $loadedSheet->getCell('B2')->getValue());
        } finally {
            @unlink($xlsxPath);
        }
    }
}
