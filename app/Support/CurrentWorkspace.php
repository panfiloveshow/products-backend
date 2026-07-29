<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * Доверенный tenant-контекст текущего HTTP-запроса.
 *
 * Значение записывает только CheckSellicoPermission после успешной проверки
 * token + workspace в Sellico. Модели не читают workspace напрямую из
 * заголовков или body, чтобы подмена входного параметра не меняла tenant scope.
 */
final class CurrentWorkspace
{
    public const REQUEST_ATTRIBUTE = 'sellico_workspace_id';

    public static function bind(Request $request, mixed $workspaceId): int
    {
        $workspaceId = filter_var($workspaceId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($workspaceId === false) {
            throw new AuthorizationException('Некорректный workspace');
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $workspaceId);

        return $workspaceId;
    }

    public static function id(?Request $request = null): ?int
    {
        if ($request === null) {
            if (! app()->bound('request')) {
                return null;
            }

            $request = app('request');
        }

        $workspaceId = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return is_int($workspaceId) && $workspaceId > 0 ? $workspaceId : null;
    }

    public static function forget(Request $request): void
    {
        $request->attributes->remove(self::REQUEST_ATTRIBUTE);
    }

    public static function require(Request $request): int
    {
        $workspaceId = self::id($request);
        if ($workspaceId === null) {
            throw new AuthorizationException('Workspace не прошёл проверку доступа');
        }

        return $workspaceId;
    }

    public static function authorize(Request $request, int $workspaceId): void
    {
        if (self::require($request) !== $workspaceId) {
            throw new AuthorizationException('Нет доступа к указанному workspace');
        }
    }
}
