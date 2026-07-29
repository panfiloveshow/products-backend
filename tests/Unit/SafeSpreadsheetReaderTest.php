<?php

namespace Tests\Unit;

use App\Services\Spreadsheet\SafeSpreadsheetReader;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SafeSpreadsheetReaderTest extends TestCase
{
    public function test_rejects_csv_over_row_limit(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'too-many.csv',
            "sku;cost\nA;1\nB;2\nC;3\n"
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('больше 3 строк');

        (new SafeSpreadsheetReader)->read($file, 3, 8, 1024);
    }

    public function test_reads_small_xlsx_without_calculating_formulas(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'safe-xlsx-');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['sku', 'cost'],
            ['SAFE-1', 125.5],
            ['SAFE-2', '=100+50'],
        ]);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $file = new UploadedFile(
                $path,
                'safe.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            );

            $rows = (new SafeSpreadsheetReader)->read($file, 10, 8, 1024 * 1024);

            $this->assertSame('SAFE-1', $rows[1][0]);
            $this->assertSame(125.5, $rows[1][1]);
            $this->assertSame('=100+50', $rows[2][1]);
        } finally {
            @unlink($path);
        }
    }

    public function test_rejects_csv_with_oversized_line(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'oversized-line.csv',
            "sku;cost\n".str_repeat('A', 1024 * 1024 + 1).";1\n"
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('слишком длинную строку');

        (new SafeSpreadsheetReader)->read($file, 10, 8, 2 * 1024 * 1024);
    }
}
