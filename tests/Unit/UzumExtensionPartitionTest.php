<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\UzumExtensionController;
use PHPUnit\Framework\TestCase;

class UzumExtensionPartitionTest extends TestCase
{
    public function test_partitions_objects_from_scalars(): void
    {
        [$accepted, $rejected, $errors] = UzumExtensionController::partitionItems('products', [
            ['productId' => '1', 'title' => 'A'],   // ok
            ['productId' => '2', 'title' => 'B'],   // ok
            'broken',            // rejected: scalar
            [],                  // rejected: empty
            ['title' => 'C'],     // rejected: no productId
        ]);

        $this->assertSame(2, $accepted);
        $this->assertSame(3, $rejected);
        $this->assertCount(3, $errors);
        $this->assertSame(2, $errors[0]['index']);
        $this->assertSame('invalid_item', $errors[0]['code']);
        $this->assertSame('invalid_product', $errors[2]['code']);
    }

    public function test_empty_payload_is_all_clean(): void
    {
        $this->assertSame([0, 0, []], UzumExtensionController::partitionItems('products', []));
    }

    public function test_dimensions_require_product_or_sku_id(): void
    {
        [$accepted, $rejected, $errors] = UzumExtensionController::partitionItems('dimensions', [
            ['product_id' => 1, 'length' => 10],
            ['sku_id' => 2, 'width' => 20],
            ['length' => 10],
        ]);

        $this->assertSame(2, $accepted);
        $this->assertSame(1, $rejected);
        $this->assertSame('invalid_dimensions', $errors[0]['code']);
    }
}
