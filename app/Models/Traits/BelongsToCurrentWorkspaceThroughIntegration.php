<?php

namespace App\Models\Traits;

use App\Models\Integration;
use App\Support\CurrentWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Fail-closed tenant scope для моделей с колонкой integration_id.
 *
 * В HTTP-запросе после sellico.permission чтение автоматически ограничивается
 * интеграциями текущего workspace, а создание/изменение с чужим integration_id
 * отклоняется. В queue/console доверенного request-контекста нет, поэтому
 * фоновые задачи продолжают явно работать по переданному integration_id.
 */
trait BelongsToCurrentWorkspaceThroughIntegration
{
    public static function bootBelongsToCurrentWorkspaceThroughIntegration(): void
    {
        static::addGlobalScope('current_workspace', function (Builder $builder): void {
            $workspaceId = CurrentWorkspace::id();
            if ($workspaceId === null) {
                return;
            }

            $model = $builder->getModel();
            $builder->whereIn(
                $model->qualifyColumn('integration_id'),
                Integration::query()
                    ->select('id')
                    ->where('work_space_id', $workspaceId)
            );
        });

        static::saving(function (Model $model): void {
            $workspaceId = CurrentWorkspace::id();
            if ($workspaceId === null) {
                return;
            }

            $integrationId = (int) $model->getAttribute('integration_id');
            $isAccessible = $integrationId > 0
                && Integration::query()
                    ->whereKey($integrationId)
                    ->where('work_space_id', $workspaceId)
                    ->exists();

            if (! $isAccessible) {
                throw new AuthorizationException('Интеграция не принадлежит текущему workspace');
            }
        });
    }
}
