<?php

namespace App\Domains\Wildberries\Api;

use RuntimeException;

class WildberriesRateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("WB Prices API rate limit; retry after {$retryAfterSeconds} seconds");
    }
}
