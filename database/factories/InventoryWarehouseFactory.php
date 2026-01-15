<?php

namespace Database\Factories;

use App\Models\InventoryWarehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryWarehouseFactory extends Factory
{
    protected $model = InventoryWarehouse::class;

    public function definition(): array
    {
        $marketplace = $this->faker->randomElement(['wildberries', 'ozon', 'yandex']);
        
        return [
            'sku' => $this->faker->bothify('SKU-####-???'),
            'warehouse_id' => $this->faker->bothify('WH-####'),
            'warehouse_name' => $this->faker->city() . ' Склад',
            'marketplace' => $marketplace,
            'fulfillment_type' => $this->faker->randomElement(['fbo', 'fbs']),
            'quantity' => $this->faker->numberBetween(0, 500),
            'reserved' => $this->faker->numberBetween(0, 50),
            'in_transit' => $this->faker->numberBetween(0, 30),
            'sales_7_days' => $this->faker->numberBetween(0, 100),
            'sales_14_days' => $this->faker->numberBetween(0, 200),
            'sales_30_days' => $this->faker->numberBetween(0, 500),
            'average_daily_sales' => $this->faker->randomFloat(2, 0, 20),
            'days_of_stock' => $this->faker->numberBetween(0, 90),
            'turnover_days' => $this->faker->randomFloat(1, 0, 60),
        ];
    }

    public function forSku(string $sku): static
    {
        return $this->state(fn () => ['sku' => $sku]);
    }

    public function wildberries(): static
    {
        return $this->state(fn () => ['marketplace' => 'wildberries']);
    }

    public function ozon(): static
    {
        return $this->state(fn () => ['marketplace' => 'ozon']);
    }

    public function yandex(): static
    {
        return $this->state(fn () => ['marketplace' => 'yandex']);
    }

    public function withQuantity(int $quantity): static
    {
        return $this->state(fn () => ['quantity' => $quantity]);
    }

    public function empty(): static
    {
        return $this->state(fn () => ['quantity' => 0]);
    }
}
