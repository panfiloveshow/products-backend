<?php

namespace App\Providers;

use App\Events\ShipmentStatusChanged;
use App\Listeners\SendShipmentStatusNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Маппинг событий и слушателей
     */
    protected $listen = [
        ShipmentStatusChanged::class => [
            SendShipmentStatusNotification::class,
        ],
    ];

    /**
     * Регистрация событий
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Определить, должны ли события и слушатели автоматически обнаруживаться
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
