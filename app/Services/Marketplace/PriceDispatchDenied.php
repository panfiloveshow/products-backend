<?php

namespace App\Services\Marketplace;

use DomainException;

/** Only fixed public denials, never an upstream body, credential or account ID. */
final class PriceDispatchDenied extends DomainException
{
    private function __construct(private readonly string $denialCode, string $message)
    {
        parent::__construct($message);
    }

    public static function fromServiceError(mixed $code): ?self
    {
        return match ($code) {
            'price_dispatch_blocked_by_active_write' => new self($code, 'По этому кабинету есть незавершённая ценовая команда. Дождитесь проверки её результата; новая отправка заблокирована.'),
            'price_dispatch_retired' => new self($code, 'Прямая отправка цен из товарного сервиса выключена: цены меняются только через лично согласованные команды Сели. Запрос к площадке не отправлен.'),
            'price_dispatch_binding_unknown' => new self($code, 'Привязка прежней незавершённой команды WB не подтверждена. Сначала нужен её разбор; новая отправка заблокирована.'),
            default => null,
        };
    }

    public function publicData(): array
    {
        return ['code' => $this->denialCode, 'message' => $this->getMessage()];
    }
}
