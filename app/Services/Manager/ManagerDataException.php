<?php

declare(strict_types=1);

namespace App\Services\Manager;

final class ManagerDataException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 503, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }
}
