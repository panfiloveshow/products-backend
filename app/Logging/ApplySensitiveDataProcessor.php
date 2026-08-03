<?php

namespace App\Logging;

use Illuminate\Log\Logger;

final class ApplySensitiveDataProcessor
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (method_exists($monolog, 'pushProcessor')) {
            $monolog->pushProcessor(new SensitiveDataProcessor);
        }
    }
}
