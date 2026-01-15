<?php

namespace Database\Factories;

use App\Models\Integration;
use Illuminate\Database\Eloquent\Factories\Factory;

class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    public function definition(): array
    {
        $marketplace = $this->faker->randomElement(['wildberries', 'ozon', 'yandex']);
        
        return [
            'name' => $this->faker->company() . ' ' . ucfirst($marketplace),
            'marketplace' => $marketplace,
            'credentials' => $this->getCredentialsForMarketplace($marketplace),
            'is_active' => true,
            'auto_sync_enabled' => true,
            'sync_interval_hours' => $this->faker->randomElement([2, 4, 6, 12]),
            'last_sync_at' => $this->faker->optional()->dateTimeBetween('-7 days', 'now'),
            'last_sync_status' => $this->faker->optional()->randomElement(['completed', 'failed']),
            'settings' => null,
        ];
    }

    private function getCredentialsForMarketplace(string $marketplace): array
    {
        return match ($marketplace) {
            'wildberries' => ['api_key' => 'test_wb_key_' . $this->faker->uuid()],
            'ozon' => [
                'client_id' => $this->faker->numerify('######'),
                'api_key' => 'test_ozon_key_' . $this->faker->uuid(),
            ],
            'yandex' => [
                'token' => 'y0_test_' . $this->faker->uuid(),
                'campaign_id' => $this->faker->numerify('######'),
            ],
            default => [],
        };
    }

    public function wildberries(): static
    {
        return $this->state(fn () => [
            'marketplace' => 'wildberries',
            'credentials' => ['api_key' => 'test_wb_key'],
        ]);
    }

    public function ozon(): static
    {
        return $this->state(fn () => [
            'marketplace' => 'ozon',
            'credentials' => ['client_id' => '123456', 'api_key' => 'test_ozon_key'],
        ]);
    }

    public function yandex(): static
    {
        return $this->state(fn () => [
            'marketplace' => 'yandex',
            'credentials' => ['token' => 'y0_test_token'],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function autoSyncDisabled(): static
    {
        return $this->state(fn () => ['auto_sync_enabled' => false]);
    }
}
