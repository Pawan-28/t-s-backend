<?php

namespace Database\Factories;

use App\Models\Subcategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Subcategory> */
class SubcategoryFactory extends Factory
{
    protected $model = Subcategory::class;

    public function definition(): array
    {
        $n = fake()->unique()->words(2, true).' '.Str::random(4);

        return ['category_id' => CategoryFactory::new(), 'name' => ucfirst($n), 'slug' => Str::slug($n), 'description' => '', 'display_order' => 0, 'is_active' => true];
    }
}
