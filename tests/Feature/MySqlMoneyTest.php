<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\PaymentsTestHelpers;
use Tests\TestCase;

/** DECIMAL(10,2) money is handled as strings / integer paise: never float arithmetic (Razorpay paise are exact). */
class MySqlMoneyTest extends TestCase
{
    use PaymentsTestHelpers, RefreshDatabase;

    public function test_money_helper_is_exact_for_every_cent_amount_that_floats_get_wrong(): void
    {
        // Classic binary-float traps: 4.35*100 = 434.99999999999994, 1.15*100 = 114.99999999999999, 8.20*100 = 819.9999999999999.
        foreach ([['4.35', 435], ['1.15', 115], ['8.20', 820], ['0.29', 29], ['19.99', 1999], ['99999999.99', 9999999999], ['0.00', 0], ['0', 0], ['499', 49900], ['499.5', 49950]] as [$in, $out]) {
            $this->assertSame($out, Money::toMinorUnits($in), $in);
        }
        $this->expectException(\InvalidArgumentException::class); // more than two decimals is never silently rounded
        Money::toMinorUnits('1.005');
    }

    public function test_round_trip_through_decimal_column_for_a_large_sample_of_cent_values(): void
    {
        $failures = [];
        for ($n = 1; $n <= 250000; $n += 137) { // ~1,800 values across 0.01 .. 2,500.00 incl. every float-hostile residue
            $text = Money::fromMinorUnits($n);
            $this->assertSame($n, Money::toMinorUnits($text));
            $this->assertSame($n, Money::toMinorUnits((float) $text), 'float input goes through its shortest decimal text');
        }
        $ids = [];
        foreach ([1, 5, 10, 29, 99, 435, 1999, 49900, 99999999, 9999999999] as $n) {
            $ids[$n] = DB::table('subscription_plans')->insertGetId(['name' => "p{$n}", 'slug' => "p{$n}", 'description' => '', 'price_amount' => Money::fromMinorUnits($n), 'price_currency' => 'INR', 'duration_days' => 1]);
        }
        foreach ($ids as $n => $id) {
            $stored = (string) SubscriptionPlan::query()->find($id)->price_amount;
            if (Money::toMinorUnits($stored) !== $n) {
                $failures[] = "{$n} -> {$stored}";
            }
        }
        $this->assertSame([], $failures);
    }

    public function test_paise_equals_is_strict_about_type_and_value(): void
    {
        $this->assertTrue(Money::paiseEquals(43500, '435.00'));
        $this->assertTrue(Money::paiseEquals('43500', '435.00'));
        $this->assertFalse(Money::paiseEquals(43499, '435.00'));
        $this->assertFalse(Money::paiseEquals(43500.0, '435.00'), 'a float from JSON is not an exact paise count');
        $this->assertFalse(Money::paiseEquals('435.00', '435.00'));
        $this->assertFalse(Money::paiseEquals(null, '435.00'));
        $this->assertFalse(Money::paiseEquals(true, '435.00'));
        $this->assertSame('4.35', Money::fromMinorUnits(435));
        $this->assertSame('-0.05', Money::fromMinorUnits(-5));
    }

    public function test_checkout_sends_razorpay_integer_paise_for_float_hostile_prices(): void
    {
        $this->configurePayments();
        Mail::fake();
        foreach ([['4.35', 435], ['1.15', 115], ['8.20', 820], ['19.99', 1999], ['499.00', 49900], ['0.01', 1]] as $i => [$price, $paise]) {
            $this->fakeExternal('order_M'.$i, 200, ['id' => 'order_M'.$i]);
            $plan = $this->plan(['price_amount' => $price]);
            $user = User::factory()->create();
            $this->actingAsUser($user);
            $r = $this->postJson('/api/subscriptions/checkout/', ['plan_slug' => $plan->slug, 'email' => 'b@example.com', 'phone' => '9876543210'])->assertStatus(201);
            $this->assertSame($paise, $r->json('amount'), $price);
            Http::assertSent(function (HttpRequest $req) use ($paise) {
                $raw = $req->body();

                return str_contains($raw, '"amount":'.$paise.',') || str_contains($raw, '"amount":'.$paise.'}');
            });
            $pay = Payment::query()->where('razorpay_order_id', 'order_M'.$i)->firstOrFail();
            $this->assertSame($price, $pay->amount);
        }
    }

    public function test_webhook_amount_must_match_exactly_in_paise(): void
    {
        $this->configurePayments();
        $this->fakeExternal();
        Mail::fake();
        $user = User::factory()->create();
        [, $pay] = $this->pendingCheckout($user, $this->plan(['price_amount' => '4.35']));
        $svc = app(SubscriptionService::class);
        $event = fn ($amount) => ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_1', 'order_id' => $pay->razorpay_order_id, 'amount' => $amount, 'currency' => 'INR']]]];

        $svc->handleWebhookEvent($event(434));
        $svc->handleWebhookEvent($event(435.0));
        $this->assertSame(Payment::CREATED, $pay->refresh()->status, 'wrong / non-integer amounts never activate');
        $svc->handleWebhookEvent($event(435));
        $this->assertSame(Payment::PAID, $pay->refresh()->status);
    }
}
