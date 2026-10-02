<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResources;
use App\Models\Payment;
use App\Models\PhoneOtp;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\Page;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** ADMIN-only plan CRUD and read-only subscription/payment/OTP browsing (Django Phase I). */
class SubscriptionAdminController extends Controller
{
    private const CHOICE = 'Select a valid choice. That choice is not one of the available choices.';

    // ------------------------------------------------------------------ plans

    public function plans(Request $request): JsonResponse
    {
        $q = SubscriptionPlan::query()->orderBy('price_amount')->orderBy('id');

        return response()->json(Page::make($q, $request, fn ($p) => SubscriptionResources::adminPlan($p)));
    }

    public function showPlan(int $plan): JsonResponse
    {
        return response()->json(SubscriptionResources::adminPlan(SubscriptionPlan::query()->findOrFail($plan)));
    }

    public function storePlan(Request $request): JsonResponse
    {
        $data = $this->validatePlan($request, false);
        $data['slug'] = Slug::unique(SubscriptionPlan::class, $data['name'], 120);
        $plan = SubscriptionPlan::create($data + ['description' => '', 'price_currency' => 'INR', 'is_active' => true]);

        return response()->json(SubscriptionResources::adminPlan($plan->refresh()), 201);
    }

    /** PUT (all required fields) and PATCH (partial). The slug is never changed. */
    public function updatePlan(Request $request, int $plan): JsonResponse
    {
        $model = SubscriptionPlan::query()->findOrFail($plan);
        $model->fill($this->validatePlan($request, $request->isMethod('PATCH')))->save();

        return response()->json(SubscriptionResources::adminPlan($model->refresh()));
    }

    public function destroyPlan(int $plan): Response|JsonResponse
    {
        $model = SubscriptionPlan::query()->findOrFail($plan);
        if ($model->subscriptions()->exists()) {
            // Django raised ProtectedError (500). A plan with history can only be deactivated.
            return response()->json(['detail' => 'This plan has subscriptions and cannot be deleted. Deactivate it instead.'], 409);
        }
        $model->delete();

        return response()->noContent();
    }

    private function validatePlan(Request $request, bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $m = SubscriptionController::MESSAGES + [
            'price_amount.numeric' => 'A valid number is required.',
            'price_amount.decimal' => 'Ensure that there are no more than 2 decimal places.',
            'price_amount.gt' => 'Price must be greater than zero.',
            'price_amount.max' => 'Ensure that there are no more than 8 digits before the decimal point.',
            'duration_days.integer' => 'A valid integer is required.',
            'duration_days.min' => 'Duration must be at least 1 day.',
            'is_active.boolean' => 'Must be a valid boolean.',
        ];

        return $request->validate([
            'name' => [$req, 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price_amount' => [$req, 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'price_currency' => ['sometimes', 'string', 'max:3'],
            'duration_days' => [$req, 'integer', 'min:1', 'max:4294967295'],
            'is_active' => ['sometimes', 'boolean'],
        ], $m);
    }

    // ---------------------------------------------------------------- browsing

    /** GET /subscriptions/admin/list/?user=&plan=&status= */
    public function subscriptions(Request $request): JsonResponse
    {
        $f = $request->validate([
            'user' => ['nullable', 'integer', 'exists:users,id'],
            'plan' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'status' => ['nullable', Rule::in([Subscription::PENDING, Subscription::ACTIVE, Subscription::EXPIRED, Subscription::CANCELLED])],
        ], $this->filterMessages());

        $q = Subscription::query()->with(['plan', 'user:id,email'])
            ->when(isset($f['user']), fn ($q) => $q->where('user_id', $f['user']))
            ->when(isset($f['plan']), fn ($q) => $q->where('plan_id', $f['plan']))
            ->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('created_at')->orderByDesc('id');

        return response()->json(Page::make($q, $request, fn ($s) => SubscriptionResources::adminSubscription($s)));
    }

    /** GET /subscriptions/admin/payments/?user=&status= */
    public function payments(Request $request): JsonResponse
    {
        $f = $request->validate([
            'user' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in([Payment::CREATED, Payment::PAID, Payment::FAILED])],
        ], $this->filterMessages());

        $q = Payment::query()->with('user:id,email')
            ->when(isset($f['user']), fn ($q) => $q->where('user_id', $f['user']))
            ->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('created_at')->orderByDesc('id');

        return response()->json(Page::make($q, $request, fn ($p) => SubscriptionResources::payment($p)));
    }

    /** GET /subscriptions/admin/otps/?user=&is_verified= - metadata only, never the hash. */
    public function otps(Request $request): JsonResponse
    {
        $f = $request->validate([
            'user' => ['nullable', 'integer', 'exists:users,id'],
            'is_verified' => ['nullable', Rule::in(['true', 'false', 'True', 'False', '1', '0', 1, 0, true, false])],
        ], $this->filterMessages());

        $q = PhoneOtp::query()->with('user:id,email')
            ->when(isset($f['user']), fn ($q) => $q->where('user_id', $f['user']))
            ->when(isset($f['is_verified']), fn ($q) => $q->where('is_verified', filter_var($f['is_verified'], FILTER_VALIDATE_BOOL)))
            ->orderByDesc('created_at')->orderByDesc('id');

        return response()->json(Page::make($q, $request, fn ($o) => SubscriptionResources::otp($o)));
    }

    private function filterMessages(): array
    {
        return [
            'user.exists' => self::CHOICE, 'plan.exists' => self::CHOICE,
            'user.integer' => 'Enter a number.', 'plan.integer' => 'Enter a number.',
            'status.in' => self::CHOICE, 'is_verified.in' => 'Select a valid choice.',
        ];
    }
}
