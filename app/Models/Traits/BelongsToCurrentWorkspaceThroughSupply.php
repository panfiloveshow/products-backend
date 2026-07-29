<?php

namespace App\Models\Traits;

use App\Models\Supply;
use App\Support\CurrentWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant scope для дочерних сущностей Ozon supply, содержащих supply_id.
 */
trait BelongsToCurrentWorkspaceThroughSupply
{
    public static function bootBelongsToCurrentWorkspaceThroughSupply(): void
    {
        static::addGlobalScope('current_workspace', function (Builder $builder): void {
            if (CurrentWorkspace::id() !== null) {
                $builder->whereHas('supply');
            }
        });

        static::saving(function (Model $model): void {
            if (CurrentWorkspace::id() === null) {
                return;
            }

            $supplyId = (int) $model->getAttribute('supply_id');
            if ($supplyId <= 0 || ! Supply::query()->whereKey($supplyId)->exists()) {
                throw new AuthorizationException('Поставка не принадлежит текущему workspace');
            }
        });
    }
}
