<?php

namespace App\Services\Manager;

/** Identity verified by CRM for this request; never constructed from browser IDs. */
final readonly class ManagerIdentity
{
    public const ATTRIBUTE = 'manager_identity';

    public function __construct(public int $workspaceId, public int $userId, public array $permissions, public bool $personalConfirmed = false) {}

    public function permits(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
