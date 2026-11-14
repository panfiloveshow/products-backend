<?php

declare(strict_types=1);

namespace App\Services\Manager;

/** Credentials live only for this request and cannot enter a JSON response/debug dump. */
final readonly class ManagerStore implements \JsonSerializable
{
    public function __construct(
        public int $workspaceId,
        public int $integrationId,
        public string $marketplace,
        public string $name,
        #[\SensitiveParameter] public string $apiKey,
        #[\SensitiveParameter] public ?string $clientId,
        public string $binding,
    ) {}

    public function jsonSerialize(): array
    {
        return ['workspace_id' => $this->workspaceId, 'integration_id' => $this->integrationId, 'marketplace' => $this->marketplace, 'name' => $this->name];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
