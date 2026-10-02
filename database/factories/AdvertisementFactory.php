<?php

namespace Database\Factories;

use App\Enums\AdPlacement;
use App\Models\Advertisement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Advertisement> */
class AdvertisementFactory extends Factory
{
    protected $model = Advertisement::class;

    public function definition(): array
    {
        $hex = Str::lower(Str::random(32));

        return [
            'name' => fake()->words(3, true),
            'placement' => AdPlacement::HOME_MIDDLE,
            'image_url' => 'https://test-cdn.b-cdn.net/advertisements/'.$hex.'.jpg',
            'bunny_storage_path' => 'advertisements/'.$hex.'.jpg',
            'target_url' => 'https://example.com/landing',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'is_active' => true,
            'priority' => 0,
        ];
    }

    public function placement(AdPlacement $p): static
    {
        return $this->state(['placement' => $p]);
    }

    public function expired(): static
    {
        return $this->state(['start_at' => now()->subDays(10), 'end_at' => now()->subDay()]);
    }

    public function upcoming(): static
    {
        return $this->state(['start_at' => now()->addDay(), 'end_at' => now()->addDays(10)]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
