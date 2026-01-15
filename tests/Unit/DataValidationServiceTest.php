<?php

namespace Tests\Unit;

use App\Services\DataValidationService;
use Tests\TestCase;

class DataValidationServiceTest extends TestCase
{
    private DataValidationService $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new DataValidationService();
    }

    public function test_validates_product_with_valid_data(): void
    {
        $result = $this->validator->validateProduct([
            'sku' => 'TEST-001',
            'name' => 'Test Product',
            'price' => 1000,
            'stock' => 50,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_fails_validation_without_sku(): void
    {
        $result = $this->validator->validateProduct([
            'name' => 'Test Product',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('SKU is required', $result['errors']);
    }

    public function test_fails_validation_without_name(): void
    {
        $result = $this->validator->validateProduct([
            'sku' => 'TEST-001',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('Name is required', $result['errors']);
    }

    public function test_fails_validation_with_negative_price(): void
    {
        $result = $this->validator->validateProduct([
            'sku' => 'TEST-001',
            'name' => 'Test Product',
            'price' => -100,
        ]);

        $this->assertFalse($result['valid']);
    }

    public function test_warns_on_unusually_high_price(): void
    {
        $result = $this->validator->validateProduct([
            'sku' => 'TEST-001',
            'name' => 'Test Product',
            'price' => 15000000, // > 10M
        ]);

        $this->assertTrue($result['valid']); // Still valid, just warning
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_validates_inventory_with_valid_data(): void
    {
        $result = $this->validator->validateInventory([
            'sku' => 'TEST-001',
            'warehouse_id' => 'WH-001',
            'quantity' => 100,
        ]);

        $this->assertTrue($result['valid']);
    }

    public function test_fails_inventory_validation_without_sku(): void
    {
        $result = $this->validator->validateInventory([
            'warehouse_id' => 'WH-001',
            'quantity' => 100,
        ]);

        $this->assertFalse($result['valid']);
    }

    public function test_fails_inventory_validation_without_warehouse_id(): void
    {
        $result = $this->validator->validateInventory([
            'sku' => 'TEST-001',
            'quantity' => 100,
        ]);

        $this->assertFalse($result['valid']);
    }
}
