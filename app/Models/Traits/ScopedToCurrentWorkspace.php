<?php

namespace App\Models\Traits;

use App\Support\CurrentWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant scope для Integration, где историческое имя колонки — work_space_id.
 */
trait ScopedToCurrentWorkspace
{
    public static function bootScopedToCurrentWorkspace(): void
    {
        static::addGlobalScope('current_workspace', function (Builder $builder): void {
            $workspaceId = CurrentWorkspace::id();
            if ($workspaceId !== null) {
                $builder->where($builder->getModel()->qualifyColumn('work_space_id'), $workspaceId);
            }
        });

        static::saving(function (Model $model): void {
            $workspaceId = CurrentWorkspace::id();
            if ($workspaceId === null) {
                return;
            }

            $ownerId = $model->getAttribute('work_space_id');
            if ($ownerId !== null && (int) $ownerId !== $workspaceId) {
                throw new AuthorizationException('Интеграция не принадлежит текущему workspace');
            }

            if ($ownerId === null) {
                $model->setAttribute('work_space_id', $workspaceId);
            }
        });
    }
}
