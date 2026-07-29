<?php

namespace App\Exceptions;

/**
 * Запрос во внешний money-path мог быть принят Ozon, но подтверждение потеряно.
 * Автоматический повтор запрещён до ручной сверки кабинета.
 */
class OzonAmbiguousRemoteStateException extends \RuntimeException
{
}
