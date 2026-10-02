<?php

namespace App\Http\Resources;

use App\Models\Notification;
use App\Models\Payment;
use App\Models\PhoneOtp;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;

/**
 * Array serializers for subscriptions/payments/OTP metadata/notifications (Django shapes).
 * Money is a decimal STRING ("499.00") like DRF DecimalField. Never exposes razorpay_signature or code_hash.
 */
class SubscriptionResources
{
    private static function iso($d): ?string
    {
        return $d?->toIso8601String();
    }

    public static function plan(?SubscriptionPlan $p): ?array
    {
        if (! $p) {
            return null;
        }

        return [
            'id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'description' => $p->description,
            'price_amount' => (string) $p->price_amount, 'price_currency' => $p->price_currency,
            'duration_days' => $p->duration_days,
        ];
    }

    public static function adminPlan(SubscriptionPlan $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'description' => $p->description,
            'price_amount' => (string) $p->price_amount, 'price_currency' => $p->price_currency,
            'duration_days' => $p->duration_days, 'is_active' => $p->is_active,
            'created_at' => self::iso($p->created_at), 'updated_at' => self::iso($p->updated_at),
        ];
    }

    /** Eager-load `plan`. */
    public static function subscription(Subscription $s): array
    {
        return [
            'id' => $s->id, 'plan' => self::plan($s->plan), 'status' => $s->status,
            'started_at' => self::iso($s->started_at), 'expires_at' => self::iso($s->expires_at),
            'is_active_now' => $s->is_active_now, 'created_at' => self::iso($s->created_at),
        ];
    }

    /** Eager-load `plan`, `user`. */
    public static function adminSubscription(Subscription $s): array
    {
        return [
            'id' => $s->id, 'user_id' => $s->user_id, 'user_email' => $s->user?->email,
            'plan' => self::plan($s->plan), 'status' => $s->status,
            'started_at' => self::iso($s->started_at), 'expires_at' => self::iso($s->expires_at),
            'is_active_now' => $s->is_active_now, 'created_at' => self::iso($s->created_at),
        ];
    }

    /** Eager-load `user`. */
    public static function payment(Payment $p): array
    {
        return [
            'id' => $p->id, 'user_email' => $p->user?->email, 'subscription_id' => $p->subscription_id,
            'razorpay_order_id' => $p->razorpay_order_id, 'razorpay_payment_id' => $p->razorpay_payment_id,
            'amount' => (string) $p->amount, 'currency' => $p->currency, 'status' => $p->status,
            'failure_reason' => $p->failure_reason,
            'created_at' => self::iso($p->created_at), 'updated_at' => self::iso($p->updated_at),
        ];
    }

    /** Eager-load `user`. Metadata only. */
    public static function otp(PhoneOtp $o): array
    {
        return [
            'id' => $o->id, 'user_email' => $o->user?->email, 'phone' => $o->phone, 'attempts' => $o->attempts,
            'expires_at' => self::iso($o->expires_at), 'is_verified' => $o->is_verified,
            'created_at' => self::iso($o->created_at),
        ];
    }

    /** Eager-load `article`. */
    public static function notification(Notification $n): array
    {
        return [
            'id' => $n->id, 'notification_type' => $n->notification_type->value,
            'article_slug' => $n->article?->slug, 'article_title' => $n->article?->title,
            'message' => $n->message, 'is_read' => $n->is_read, 'created_at' => self::iso($n->created_at),
        ];
    }

    /** Eager-load `article`, `recipient`. */
    public static function adminNotification(Notification $n): array
    {
        return [
            'id' => $n->id, 'recipient_id' => $n->recipient_id, 'recipient_email' => $n->recipient?->email,
            'notification_type' => $n->notification_type->value,
            'article_slug' => $n->article?->slug, 'article_title' => $n->article?->title,
            'message' => $n->message, 'is_read' => $n->is_read, 'created_at' => self::iso($n->created_at),
        ];
    }
}
