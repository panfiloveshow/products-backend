<?php

namespace App\Domains\Wildberries\Api;

use Carbon\CarbonInterface;

final class CardListWithPhotoFilter
{
    public static function allCards(?CarbonInterface $now = null): int
    {
        return -1;
    }
}
