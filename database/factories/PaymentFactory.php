<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'subscription_id' => SubscriptionFactory::new(),
            'user_id' => UserFactory::new(),
            'razorpay_order_id' => 'order_'.Str::random(14),
            'amount' => '499.00',
            'currency' => 'INR',
            'status' => Payment::CREATED,
        ];
    }

    public function forSubscription(Subscription $s): static
    {
        return $this->state(['subscription_id' => $s->id, 'user_id' => $s->user_id]);
    }
}
