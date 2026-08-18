<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\UnitEconomicsCacheController;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

/**
 * Excel-экспорт WB: колонка «Приёмка, ₽» существует, значение пишется,
 * формулы «На р/с» и «Всего затрат» её вычитают (расходились с вебом).
 */
class WildberriesExportAcceptanceColumnTest extends TestCase
{
    public function test_export_has_acceptance_column_and_formulas_subtract_it(): void
    {
        // Конструктор тянет DI-зависимости, которые тесту не нужны.
        $controller = (new \ReflectionClass(UnitEconomicsCacheController::class))->newInstanceWithoutConstructor();

        $columns = (new \ReflectionMethod($controller, 'wildberriesExportColumns'))->invoke($controller);
        $this->assertArrayHasKey('AJ', $columns);
        $this->assertSame('Приёмка, ₽', $columns['AJ']['header']);
        $this->assertSame('acceptance_cost', $columns['AJ']['field']);

        $sheet = (new Spreadsheet())->getActiveSheet();
        (new \ReflectionMethod($controller, 'writeWildberriesExportRow'))->invoke($controller, $sheet, 5, [
            'sku' => '123',
            'product_name' => 'Тест',
            'fulfillment_type' => 'FBS',
            'price' => 1000,
            'acceptance_cost' => 25.0,
        ]);

        $this->assertSame(25.0, $sheet->getCell('AJ5')->getValue());
        $this->assertStringContainsString('-AJ5', (string) $sheet->getCell('W5')->getValue());
        $this->assertStringContainsString('AJ5', (string) $sheet->getCell('V5')->getValue());
        $this->assertStringContainsString('AJ5', (string) $sheet->getCell('AH5')->getValue());
    }
}
