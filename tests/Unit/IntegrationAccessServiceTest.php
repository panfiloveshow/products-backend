<?php

namespace Tests\Unit;

use App\Services\IntegrationAccessService;
use PHPUnit\Framework\TestCase;

class IntegrationAccessServiceTest extends TestCase
{
    public function test_extract_remote_workspace_prefers_snake_case_then_camel_case(): void
    {
        $this->assertSame(
            10,
            IntegrationAccessService::extractRemoteWorkspaceIdFromSellicoPayload([
                'work_space_id' => 10,
                'workSpaceId' => 99,
            ])
        );

        $this->assertSame(
            3,
            IntegrationAccessService::extractRemoteWorkspaceIdFromSellicoPayload([
                'workSpaceId' => 3,
            ])
        );

        $this->assertSame(
            7,
            IntegrationAccessService::extractRemoteWorkspaceIdFromSellicoPayload([
                'workspaceId' => '7',
            ])
        );

        $this->assertSame(0, IntegrationAccessService::extractRemoteWorkspaceIdFromSellicoPayload([]));
    }
}
