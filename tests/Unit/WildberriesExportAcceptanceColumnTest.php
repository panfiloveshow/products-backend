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
        // Приёмка живёт рядом с хранением (S), а не в хвосте — менеджеры её там не находили.
        $this->assertSame('Приёмка, ₽', $columns['T']['header']);
        $this->assertSame('acceptance_cost', $columns['T']['field']);
        $this->assertSame('Хранение, ₽', $columns['S']['header']);

        // Буква каждого поля из раскладки — формулы должны ссылаться ровно на них.
        $letterOf = [];
        foreach ($columns as $letter => $def) {
            $letterOf[$def['field']] = $letter;
        }

        $sheet = (new Spreadsheet())->getActiveSheet();
        (new \ReflectionMethod($controller, 'writeWildberriesExportRow'))->invoke($controller, $sheet, 5, [
            'sku' => '123',
            'product_name' => 'Тест',
            'fulfillment_type' => 'FBS',
            'price' => 1000,
            'acceptance_cost' => 25.0,
        ]);

        $acc = $letterOf['acceptance_cost'];
        $this->assertSame(25.0, $sheet->getCell("{$acc}5")->getValue());
        $this->assertStringContainsString("-{$acc}5", (string) $sheet->getCell($letterOf['to_settlement_account'].'5')->getValue());
        $this->assertStringContainsString("{$acc}5", (string) $sheet->getCell($letterOf['total_expenses_percent'].'5')->getValue());
        $this->assertStringContainsString("{$acc}5", (string) $sheet->getCell($letterOf['target_price'].'5')->getValue());
        // «Чистая прибыль» ссылается на «На р/с», маржа — на прибыль.
        $this->assertStringContainsString($letterOf['to_settlement_account'].'5', (string) $sheet->getCell($letterOf['net_profit'].'5')->getValue());
        $this->assertStringContainsString($letterOf['net_profit'].'5', (string) $sheet->getCell($letterOf['margin_percent'].'5')->getValue());
    }
}
