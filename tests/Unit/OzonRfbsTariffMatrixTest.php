<?php

namespace Tests\Unit;

use App\Domains\Ozon\Tariffs\OzonRfbsTariffMatrix;
use PHPUnit\Framework\TestCase;

class OzonRfbsTariffMatrixTest extends TestCase
{
    public function test_resolves_default_rate_by_price_and_chargeable_weight(): void
    {
        $matrix = new OzonRfbsTariffMatrix(require dirname(__DIR__, 2).'/config/ozon_rfbs_tariffs.php');

        $result = $matrix->resolve(2500, 4.0, 8.0);

        $this->assertSame(400.0, $result['cost']);
        $this->assertSame('От 1 000 до 3 000 ₽', $result['price_band_label']);
        $this->assertSame('От 6 до 14 кг', $result['weight_band_label']);
        $this->assertSame(8.0, $result['chargeable_weight_kg']);
        $this->assertSame('rfbs_default', $result['source']);
    }

    public function test_custom_rates_override_defaults_without_changing_band_shape(): void
    {
        $defaults = require dirname(__DIR__, 2).'/config/ozon_rfbs_tariffs.php';
        $customRates = $defaults['rates'];
        $customRates[0][0] = 77;
        $matrix = new OzonRfbsTariffMatrix($defaults);

        $result = $matrix->resolve(499, 0.5, 0.2, [
            'enabled' => true,
            'rates' => $customRates,
        ]);

        $this->assertSame(77.0, $result['cost']);
        $this->assertSame('rfbs_custom', $result['source']);
    }

    public function test_invalid_custom_shape_falls_back_to_defaults(): void
    {
        $matrix = new OzonRfbsTariffMatrix(require dirname(__DIR__, 2).'/config/ozon_rfbs_tariffs.php');

        $configuration = $matrix->configuration(['rates' => [[999]]]);

        $this->assertSame('default', $configuration['source']);
        $this->assertSame(50.0, $configuration['rates'][0][0]);
    }
}
