<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResources;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\CheckoutException;
use App\Services\Subscriptions\RazorpayClient;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Wati\PhoneNumber;
use App\Support\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/** Public plans, checkout, payment verification, Razorpay webhook, "my subscription". */
class SubscriptionController extends Controller
{
    public const MESSAGES = [
        'required' => 'This field is required.',
        'email' => 'Enter a valid email address.',
        'string' => 'Not a valid string.',
        'max' => 'Ensure this field has no more than :max characters.',
    ];

    public function __construct(private SubscriptionService $subscriptions) {}

    /** GET /subscriptions/plans/ (public, active only, ordered by price). */
    public function plans(Request $request): JsonResponse
    {
        $q = SubscriptionPlan::query()->where('is_active', true)->orderBy('price_amount')->orderBy('id');

        return response()->json(Page::make($q, $request, fn ($p) => SubscriptionResources::plan($p)));
    }

    /** POST /subscriptions/checkout/ -> 201 {subscription_id, order_id, amount, currency, key_id, prefill}. */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_slug' => ['required', 'string', 'max:120', 'regex:/^[-a-zA-Z0-9_]+$/'],
            'email' => ['required', 'string', 'email:filter', 'max:254'],
            'phone' => ['required', 'string', 'max:20', function (string $attr, mixed $value, \Closure $fail) {
                if (! PhoneNumber::looksValid(PhoneNumber::normalize((string) $value))) {
                    $fail('Enter a valid phone number, e.g. 9876543210 (no country code needed).');
                }
            }],
        ], self::MESSAGES + ['plan_slug.regex' => 'Enter a valid “slug” consisting of letters, numbers, underscores or hyphens.']);

        try {
            $order = $this->subscriptions->startCheckout(
                $request->user(),
                $data['plan_slug'],
                mb_strtolower(trim($data['email'])),
                PhoneNumber::normalize($data['phone']),
            );
        } catch (CheckoutException $e) {
            return response()->json(['detail' => $e->getMessage()], 400);
        }

        return response()->json($order, 201);
    }

    /** POST /subscriptions/verify/ -> 200 subscription | 400 {detail}. Idempotent. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:100'],
            'razorpay_payment_id' => ['required', 'string', 'max:100'],
            'razorpay_signature' => ['required', 'string', 'max:255'],
        ], self::MESSAGES);

        try {
            $subscription = $this->subscriptions->verifyAndActivate(
                $request->user(), $data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature']
            );
        } catch (CheckoutException $e) {
            return response()->json(['detail' => $e->getMessage()], 400);
        }

        return response()->json(SubscriptionResources::subscription($subscription));
    }

    /** POST /subscriptions/webhook/ - Razorpay server-to-server; authenticated by signature only. */
    public function webhook(Request $request, RazorpayClient $razorpay): Response|JsonResponse
    {
        $raw = $request->getContent();
        if (! $razorpay->verifyWebhookSignature($raw, (string) $request->header('X-Razorpay-Signature', ''))) {
            return response()->json(['detail' => 'Invalid webhook signature.'], 400);
        }

        $payload = json_decode($raw, true);
        if (is_array($payload)) {
            try {
                $this->subscriptions->handleWebhookEvent($payload);
            } catch (CheckoutException $e) {
                // e.g. payment vanished between lookup and lock: nothing to do, still ack.
            } catch (\Throwable $e) {
                Log::error('Razorpay webhook processing failed.', ['error' => $e::class]);

                return response()->json(['detail' => 'Server error.'], 500); // let Razorpay retry
            }
        }

        return response('', 200);
    }

    /** GET /subscriptions/me/ -> latest subscription or JSON null. */
    public function me(Request $request): Response|JsonResponse
    {
        $sub = SubscriptionService::currentFor($request->user());

        // Django returns a bare JSON null (response()->json(null) would emit {}).
        return $sub
            ? response()->json(SubscriptionResources::subscription($sub))
            : response('null', 200, ['Content-Type' => 'application/json']);
    }

    /** GET /subscriptions/active-subscribers/ (ADMIN) -> {active_subscribers}. Distinct users. */
    public function activeSubscribers(): JsonResponse
    {
        $count = Subscription::query()->where('status', Subscription::ACTIVE)->where('expires_at', '>', now())
            ->distinct()->count('user_id');

        return response()->json(['active_subscribers' => $count]);
    }
}
