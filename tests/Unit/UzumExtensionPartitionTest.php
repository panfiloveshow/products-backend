<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\UzumExtensionController;
use PHPUnit\Framework\TestCase;

class UzumExtensionPartitionTest extends TestCase
{
    public function test_partitions_objects_from_scalars(): void
    {
        [$accepted, $rejected, $errors] = UzumExtensionController::partitionItems([
            ['title' => 'A'],   // ok
            ['title' => 'B'],   // ok
            'broken',            // rejected: scalar
            [],                  // rejected: empty
        ]);

        $this->assertSame(2, $accepted);
        $this->assertSame(2, $rejected);
        $this->assertCount(2, $errors);
        $this->assertSame(2, $errors[0]['index']);
        $this->assertSame('invalid_item', $errors[0]['code']);
    }

    public function test_empty_payload_is_all_clean(): void
    {
        $this->assertSame([0, 0, []], UzumExtensionController::partitionItems([]));
    }
}
