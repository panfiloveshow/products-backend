<?php

namespace App\Exceptions;

/**
 * Ошибка до внешнего money-path вызова. Повтор безопасен после исправления данных.
 */
class OzonPreconditionException extends \RuntimeException
{
}
