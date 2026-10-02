<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('Sup3r-Secret-pass'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => null,
            'role' => Role::USER,
            'is_active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => Role::ADMIN]);
    }

    public function reporter(): static
    {
        return $this->state(['role' => Role::REPORTER]);
    }

    public function subscriber(): static
    {
        return $this->state(['role' => Role::SUBSCRIBER]);
    }
}
