<?php

namespace Database\Factories;

use App\Models\Industry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Industry> */
class IndustryFactory extends Factory
{
    protected $model = Industry::class;

    public function definition(): array
    {
        $n = fake()->unique()->words(2, true).' '.Str::random(4);

        return ['name' => ucfirst($n), 'slug' => Str::slug($n), 'description' => '', 'is_active' => true, 'display_order' => 0];
    }
}
