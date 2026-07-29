<?php

namespace App\Models\Traits;

use App\Support\CurrentWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant scope для моделей с собственной колонкой workspace_id.
 */
trait BelongsToCurrentWorkspace
{
    public static function bootBelongsToCurrentWorkspace(): void
    {
        static::addGlobalScope('current_workspace', function (Builder $builder): void {
            $workspaceId = CurrentWorkspace::id();
            if ($workspaceId !== null) {
                $builder->where($builder->getModel()->qualifyColumn('workspace_id'), $workspaceId);
            }
        });

        static::creating(function (Model $model): void {
            $workspaceId = CurrentWorkspace::id();
            if ($workspaceId === null) {
                return;
            }

            $current = $model->getAttribute('workspace_id');
            if ($current !== null && (int) $current !== $workspaceId) {
                throw new AuthorizationException('Нельзя создать запись в чужом workspace');
            }

            $model->setAttribute('workspace_id', $workspaceId);
        });

        static::updating(function (Model $model): void {
            $workspaceId = CurrentWorkspace::id();
            if ($workspaceId !== null && (int) $model->getAttribute('workspace_id') !== $workspaceId) {
                throw new AuthorizationException('Нельзя изменить запись другого workspace');
            }
        });
    }
}
