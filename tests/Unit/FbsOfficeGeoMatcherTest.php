<?php

namespace Tests\Unit;

use App\Domains\Wildberries\Tariffs\FbsOfficeGeoMatcher;
use PHPUnit\Framework\TestCase;

class FbsOfficeGeoMatcherTest extends TestCase
{
    /** @var array<string, array{warehouse_name:string, geo_name:string}> */
    private array $tariffs = [
        'Коледино' => ['warehouse_name' => 'Коледино', 'geo_name' => 'Центральный федеральный округ'],
        'Обухово' => ['warehouse_name' => 'Обухово', 'geo_name' => 'Центральный федеральный округ'],
        'Санкт-Петербург Уткина Заводь' => ['warehouse_name' => 'Санкт-Петербург Уткина Заводь', 'geo_name' => 'Северо-Западный федеральный округ'],
        'Маркетплейс: Центральный федеральный округ' => ['warehouse_name' => 'Маркетплейс: Центральный федеральный округ', 'geo_name' => 'Центральный федеральный округ'],
    ];

    public function test_matches_office_to_warehouse_geo(): void
    {
        $match = FbsOfficeGeoMatcher::match('Москва (СК Обухово)', $this->tariffs);

        $this->assertNotNull($match);
        $this->assertSame('Обухово', $match['warehouse_name']);
        $this->assertSame('Центральный федеральный округ', $match['geo_name']);
    }

    public function test_marketplace_rows_are_never_matched_as_offices(): void
    {
        // «Маркетплейс: …» — сами тарифы FBS, офисом быть не могут.
        $this->assertNull(FbsOfficeGeoMatcher::match('Маркетплейс', $this->tariffs));
    }

    public function test_unknown_office_returns_null(): void
    {
        $this->assertNull(FbsOfficeGeoMatcher::match('Хабаровск (СЦ Восточный)', $this->tariffs));
    }

    public function test_multiword_warehouse_requires_all_tokens(): void
    {
        // Офис со словом «Санкт-Петербург» без «Уткина Заводь» не должен
        // цепляться за многословный склад частично… но полный набор — матчится.
        $match = FbsOfficeGeoMatcher::match('СПб (СЦ Санкт-Петербург Уткина Заводь)', $this->tariffs);

        $this->assertNotNull($match);
        $this->assertSame('Северо-Западный федеральный округ', $match['geo_name']);
    }
}
