<?php

namespace App\Services\Subscriptions;

use App\Enums\NotificationType;
use App\Jobs\SendWatiTemplateMessage;
use App\Mail\SubscriptionNoticeMail;
use App\Models\Article;
use App\Models\Subscription;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Subscription lifecycle notices over three channels: in-app row (NotificationService),
 * WhatsApp template (queued WATI job) and e-mail (queued mailable). Every channel is
 * best-effort: a failure is reported but never fails checkout/verify/the lifecycle jobs.
 * Call AFTER the DB transaction has committed.
 */
class SubscriptionNotifier
{
    public function __construct(private NotificationService $inApp) {}

    public function activated(Subscription $s): void
    {
        $s->loadMissing('plan', 'user');
        $date = $this->date($s);
        $this->inApp($s, NotificationType::SUBSCRIPTION_ACTIVATED, "Your {$s->plan->name} subscription is now active until {$date}.");
        $this->whatsapp($this->contactPhone($s), 'subscription_activated', [['name' => 'plan_name', 'value' => $s->plan->name]]);
        $this->mail($s->notification_email, 'Your subscription is active', "Your {$s->plan->name} subscription is active until {$date}.");
    }

    public function paymentFailed(Subscription $s): void
    {
        $s->loadMissing('plan', 'user');
        $this->inApp($s, NotificationType::PAYMENT_FAILED, "We could not verify your payment for {$s->plan->name}. Please try again.");
        // Only a phone the account holder verified via OTP, and only the account e-mail:
        // a failure notice must not be relayable to a third party typed at checkout.
        $this->whatsapp($s->user->phone_verified_at ? (string) $s->user->phone : '', 'payment_failed', [['name' => 'plan_name', 'value' => $s->plan->name]]);
        $this->mail((string) $s->user->email, 'Payment failed', "We could not verify your payment for {$s->plan->name}. Please try again.");
    }

    public function expiring(Subscription $s): void
    {
        $s->loadMissing('plan', 'user');
        $date = $this->date($s);
        $this->inApp($s, NotificationType::SUBSCRIPTION_EXPIRING, "Your {$s->plan->name} subscription expires on {$date}.");
        $this->whatsapp($this->contactPhone($s), 'subscription_expiring', [['name' => 'expires_at', 'value' => $date]]);
        $this->mail($s->notification_email, 'Your subscription is expiring soon', "Your {$s->plan->name} subscription expires on {$date}.");
    }

    public function expired(Subscription $s): void
    {
        $s->loadMissing('plan', 'user');
        $this->inApp($s, NotificationType::SUBSCRIPTION_EXPIRED, "Your {$s->plan->name} subscription has expired.");
        $this->whatsapp($this->contactPhone($s), 'subscription_expired', []);
        $this->mail($s->notification_email, 'Your subscription has expired', "Your {$s->plan->name} subscription has expired. Renew to keep full access.");
    }

    /** External channels only (Django never created an in-app row for this). */
    public function articlePublished(Subscription $s, Article $article): void
    {
        $s->loadMissing('user');
        $this->whatsapp($this->contactPhone($s), 'article_published', [['name' => 'title', 'value' => $article->title]]);
        $this->mail($s->notification_email, 'New article: '.$article->title, 'A new article you have access to was just published: '.$article->title);
    }

    private function contactPhone(Subscription $s): string
    {
        return (string) ($s->contact_phone ?: ($s->user?->phone ?? ''));
    }

    private function date(Subscription $s): string
    {
        return $s->expires_at?->format('Y-m-d') ?? '';
    }

    private function inApp(Subscription $s, NotificationType $type, string $message): void
    {
        try {
            $this->inApp->notify($s->user, $type, $message);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function whatsapp(string $phone, string $templateKey, array $params): void
    {
        if ($phone === '' || trim((string) config('portal.wati.templates.'.$templateKey)) === '') {
            return;
        }
        try {
            SendWatiTemplateMessage::dispatch($phone, $templateKey, $params);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function mail(string $to, string $subject, string $body): void
    {
        if ($to === '') {
            return;
        }
        try {
            Mail::to($to)->send(new SubscriptionNoticeMail($subject, $body));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
