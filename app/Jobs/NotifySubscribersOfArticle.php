<?php

namespace App\Jobs;

use App\Enums\AccessLevel;
use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionNotifier;
use App\Services\Wati\WatiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * "Article published -> notify ACTIVE subscribers" fan-out for ONE article, dispatched by the workflow the moment it
 * publishes (Django: notify_subscribers_of_published_articles; the periodic catch-up scan for articles published
 * through other paths is `subscriptions:notify-published`, which shares the same claim column).
 * Only published, non-PUBLIC articles qualify (a PUBLIC article is readable by everyone). The article is CLAIMED
 * atomically via subscribers_notified_at, so the publish-time dispatch, the catch-up scan and concurrent workers
 * can never notify the same article twice. External channels are best-effort and never fail the job.
 */
class NotifySubscribersOfArticle implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $articleId) {}

    public function handle(): void
    {
        $claimed = Article::query()
            ->whereKey($this->articleId)
            ->where('status', ArticleStatus::PUBLISHED->value)
            ->where('access_level', '!=', AccessLevel::PUBLIC->value)
            ->whereNull('subscribers_notified_at')
            ->update(['subscribers_notified_at' => now()]);
        if ($claimed === 0) {
            return; // not eligible or somebody else already handled it
        }

        $article = Article::query()->find($this->articleId);
        try {
            $seen = [];
            Subscription::query()
                ->where('status', Subscription::ACTIVE)->where('expires_at', '>', now())
                ->with('user')
                ->chunkById(200, function ($subscriptions) use ($article, &$seen) {
                    foreach ($subscriptions as $subscription) {
                        if (isset($seen[$subscription->user_id])) {
                            continue; // one message per subscriber even with several active subscriptions
                        }
                        $seen[$subscription->user_id] = true;
                        $this->notifyOne($subscription, $article);
                    }
                });
        } catch (Throwable $e) {
            // Release the claim so a retry / the next scan can finish the fan-out.
            Article::query()->whereKey($this->articleId)->update(['subscribers_notified_at' => null]);
            throw $e;
        }
    }

    private function notifyOne(Subscription $subscription, Article $article): void
    {
        try {
            $notifier = SubscriptionNotifier::class;
            if (class_exists($notifier)) {
                app($notifier)->articlePublished($subscription, $article);

                return;
            }

            $wati = WatiClient::class;
            $phone = (string) ($subscription->contact_phone ?: ($subscription->user?->phone ?? ''));
            $template = (string) config('portal.wati.templates.article_published');
            if (class_exists($wati) && $phone !== '' && trim($template) !== '') {
                app($wati)->sendTemplate($phone, $template, [['name' => 'title', 'value' => $article->title]]);
            }
            $to = $subscription->notification_email;
            if ($to !== '') {
                Mail::raw('A new article you have access to was just published: '.$article->title, function ($m) use ($to, $article) {
                    $m->to($to)->subject('New article: '.$article->title);
                });
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
