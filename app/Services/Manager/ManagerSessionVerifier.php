<?php

declare(strict_types=1);

namespace App\Services\Manager;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** A current CRM check; no session tokens or raw profiles are persisted. */
final class ManagerSessionVerifier
{
    public function verify(Request $request, array $required = []): ManagerIdentity
    {
        $workspace = filter_var($request->header('X-Sellico-Workspace'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $token = $request->bearerToken();
        if ($workspace === false || ! is_string($token) || $token === '') {
            throw new ManagerDataException('Требуются действительная сессия и рабочее пространство.', 401);
        }

        $base = rtrim((string) config('services.sellico.base_url', 'https://sellico.ru/api'), '/');
        try {
            $client = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(7)->withOptions(['allow_redirects' => false]);
            $userResponse = $client->get($base.'/user');
            if (! $userResponse->successful()) {
                throw new ManagerDataException('Не удалось подтвердить сессию.', in_array($userResponse->status(), [401, 403], true) ? $userResponse->status() : 503);
            }
            $userId = filter_var($userResponse->json('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false) {
                throw new ManagerDataException('CRM вернула непроверенную личность.', 503);
            }
            $profile = $userResponse->json();
            if (($profile['is_service_account'] ?? null) !== false) {
                throw new ManagerDataException('Требуется подтверждённая личная сессия пользователя.', ($profile['is_service_account'] ?? null) === true ? 403 : 503);
            }
            $membership = $client->get($base.'/workspaces/'.$workspace.'/user-permissions');
            if (! $membership->successful()) {
                throw new ManagerDataException('Нет подтверждённого доступа к рабочему пространству.', in_array($membership->status(), [401, 403, 404], true) ? $membership->status() : 503);
            }
            $body = $membership->json();
            if (! is_array($body) || (string) ($body['user_id'] ?? '') !== (string) $userId || ! is_array($body['permissions'] ?? null) || ! array_is_list($body['permissions'])) {
                throw new ManagerDataException('Не удалось проверить права в рабочем пространстве.', 503);
            }
            foreach (['workspace_id', 'work_space_id'] as $key) {
                if (array_key_exists($key, $body) && (string) $body[$key] !== (string) $workspace) {
                    throw new ManagerDataException('CRM вернула права другого workspace.', 503);
                }
            }
            $permissions = [];
            foreach ($body['permissions'] as $item) {
                $slug = is_string($item) ? $item : (is_array($item) ? ($item['slug'] ?? null) : null);
                if (! is_string($slug) || $slug === '' || trim($slug) !== $slug) {
                    throw new ManagerDataException('Нераспознанный список прав.', 503);
                }
                $permissions[] = $slug;
            }
            $identity = new ManagerIdentity((int) $workspace, (int) $userId, array_values(array_unique($permissions)), ($profile['is_service_account'] ?? null) === false);
            foreach ($required as $permission) {
                if (! $identity->permits($permission)) {
                    throw new ManagerDataException('Недостаточно прав для этого действия.', 403);
                }
            }
        } catch (ManagerDataException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ManagerDataException('Сервис проверки доступа временно недоступен.', 503);
        }

        return $identity;
    }
}
