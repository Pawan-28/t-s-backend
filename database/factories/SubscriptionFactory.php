<?php

namespace Database\Factories;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'plan_id' => SubscriptionPlanFactory::new(),
            'status' => Subscription::PENDING,
            'contact_email' => '',
            'contact_phone' => '',
        ];
    }

    public function active(int $days = 30): static
    {
        return $this->state(fn () => ['status' => Subscription::ACTIVE, 'started_at' => now(), 'expires_at' => now()->addDays($days)]);
    }

    public function expiresAt($when): static
    {
        return $this->state(['status' => Subscription::ACTIVE, 'started_at' => now()->subDays(30), 'expires_at' => $when]);
    }
}
