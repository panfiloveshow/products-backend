<?php

namespace App\Domains\Wildberries\Api;

use Carbon\CarbonInterface;

final class CardListWithPhotoFilter
{
    private const WB_NEW_WITH_PHOTO_SCHEMA_FROM = '2026-06-03';

    public static function allCards(?CarbonInterface $now = null): int
    {
        $now ??= now();

        return $now->toDateString() >= self::WB_NEW_WITH_PHOTO_SCHEMA_FROM ? 0 : -1;
    }
}
