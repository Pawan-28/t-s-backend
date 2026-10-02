<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResources;
use App\Models\Notification;
use App\Support\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * In-app notifications. The user endpoints are hard-scoped to the caller: another user's
 * notification is a 404, never a 403 (Django parity, no existence oracle).
 */
class NotificationController extends Controller
{
    private function own(Request $request)
    {
        return Notification::query()->where('recipient_id', $request->user()->id);
    }

    public function index(Request $request): JsonResponse
    {
        $q = $this->own($request)->with('article:id,slug,title')->orderByDesc('created_at')->orderByDesc('id');

        return response()->json(Page::make($q, $request, fn ($n) => SubscriptionResources::notification($n)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $n = $this->own($request)->with('article:id,slug,title')->findOrFail($id);

        return response()->json(SubscriptionResources::notification($n));
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $n = $this->own($request)->with('article:id,slug,title')->findOrFail($id);
        if (! $n->is_read) {
            $n->forceFill(['is_read' => true])->save();
        }

        return response()->json(SubscriptionResources::notification($n));
    }

    /** POST /notifications/mark-all-read/ (additive, not in Django). */
    public function markAllRead(Request $request): JsonResponse
    {
        $n = $this->own($request)->where('is_read', false)->update(['is_read' => true]);

        return response()->json(['updated' => $n]);
    }

    /** GET /notifications/unread-count/ (additive, not in Django). */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $this->own($request)->where('is_read', false)->count()]);
    }

    // ------------------------------------------------------------------ admin

    /** GET /notifications/admin/?recipient=&notification_type=&is_read= */
    public function adminIndex(Request $request): JsonResponse
    {
        $f = $request->validate([
            'recipient' => ['nullable', 'integer', 'exists:users,id'],
            'notification_type' => ['nullable', Rule::enum(NotificationType::class)],
            'is_read' => ['nullable', Rule::in(['true', 'false', 'True', 'False', '1', '0'])],
        ], [
            'recipient.exists' => 'Select a valid choice. That choice is not one of the available choices.',
            'recipient.integer' => 'Enter a number.',
            'notification_type.enum' => 'Select a valid choice. That choice is not one of the available choices.',
            'is_read.in' => 'Select a valid choice.',
        ]);

        $q = Notification::query()->with(['article:id,slug,title', 'recipient:id,email'])
            ->when(isset($f['recipient']), fn ($q) => $q->where('recipient_id', $f['recipient']))
            ->when(isset($f['notification_type']), fn ($q) => $q->where('notification_type', $f['notification_type']))
            ->when(isset($f['is_read']), fn ($q) => $q->where('is_read', filter_var($f['is_read'], FILTER_VALIDATE_BOOL)))
            ->orderByDesc('created_at')->orderByDesc('id');

        return response()->json(Page::make($q, $request, fn ($n) => SubscriptionResources::adminNotification($n)));
    }

    public function adminShow(int $id): JsonResponse
    {
        $n = Notification::query()->with(['article:id,slug,title', 'recipient:id,email'])->findOrFail($id);

        return response()->json(SubscriptionResources::adminNotification($n));
    }

    public function adminMarkRead(int $id): JsonResponse
    {
        $n = Notification::query()->with(['article:id,slug,title', 'recipient:id,email'])->findOrFail($id);
        if (! $n->is_read) {
            $n->forceFill(['is_read' => true])->save();
        }

        return response()->json(SubscriptionResources::adminNotification($n));
    }
}
