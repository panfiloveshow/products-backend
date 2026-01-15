<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $marketplace = $this->faker->randomElement(['wildberries', 'ozon', 'yandex']);
        
        return [
            'sku' => $this->faker->unique()->bothify('SKU-####-???'),
            'name' => $this->faker->words(3, true),
            'barcode' => $this->faker->ean13(),
            'price' => $this->faker->randomFloat(2, 100, 10000),
            'old_price' => $this->faker->optional()->randomFloat(2, 100, 15000),
            'stock' => $this->faker->numberBetween(0, 500),
            'description' => $this->faker->optional()->paragraph(),
            'images' => [$this->faker->imageUrl()],
            'category' => $this->faker->word(),
            'brand' => $this->faker->company(),
            'rating' => $this->faker->optional()->randomFloat(1, 1, 5),
            'reviews_count' => $this->faker->numberBetween(0, 1000),
            'marketplace' => $marketplace,
            'marketplace_id' => $this->faker->numerify('########'),
            'url' => $this->faker->url(),
            'characteristics' => [],
            'integration_id' => null,
        ];
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

    public function withStock(int $stock): static
    {
        return $this->state(fn () => ['stock' => $stock]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['stock' => 0]);
    }
}
