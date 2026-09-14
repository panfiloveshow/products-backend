<?php

namespace Tests\Unit;

use App\Domains\Wildberries\Api\CardApi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WildberriesBuyerPriceTest extends TestCase
{
    public function test_buyer_price_is_taken_from_public_card_in_rubles(): void
    {
        Http::fake([
            'card.wb.ru/cards/v4/detail*' => Http::response([
                'products' => [
                    ['id' => 142547385, 'sizes' => [['price' => ['basic' => 38500, 'product' => 27100]]]],
                    ['id' => 7, 'sizes' => [['price' => ['basic' => 1000]]]], // нет в наличии — цены покупателя нет
                ],
            ]),
        ]);

        $this->assertSame(['142547385' => 271.0], (new CardApi)->getBuyerPricesByNmIds([142547385, 7]));
    }
}
