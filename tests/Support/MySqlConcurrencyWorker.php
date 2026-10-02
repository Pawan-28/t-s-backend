<?php

/**
 * Child process of MySqlConcurrencyTest: boots the app, waits for a shared start instant so several
 * workers hit InnoDB at the same moment, runs ONE action and prints a single JSON line.
 *
 *   php MySqlConcurrencyWorker.php <mode> <start-microtime> '<json args>'
 */

use App\Models\ArticleImage;
use App\Models\User;
use App\Services\Media\ArticleImageService;
use App\Services\Otp\OtpException;
use App\Services\Otp\OtpService;
use App\Services\Subscriptions\CheckoutException;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Workflow\ArticleWorkflowService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$mode, $start, $args] = [$argv[1], (float) $argv[2], json_decode($argv[3] ?? '[]', true)];
config([
    'portal.razorpay.key_id' => 'rzp_test_KEYID', 'portal.razorpay.key_secret' => 'test_key_secret_value',
    'portal.razorpay.webhook_secret' => 'test_webhook_secret_value',
]);
Http::fake();
Mail::fake();

while (microtime(true) < $start) {
    usleep(200);
}

$out = ['mode' => $mode, 'pid' => getmypid()];
try {
    switch ($mode) {
        case 'webhook':
            app(SubscriptionService::class)->handleWebhookEvent(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
                'id' => $args['payment_id'], 'order_id' => $args['order_id'], 'amount' => $args['amount'], 'currency' => 'INR']]]]);
            $out['result'] = 'done';
            break;
        case 'verify':
            $sig = hash_hmac('sha256', $args['order_id'].'|'.$args['payment_id'], 'test_key_secret_value');
            $sub = app(SubscriptionService::class)->verifyAndActivate(User::findOrFail($args['user_id']), $args['order_id'], $args['payment_id'], $sig);
            $out['result'] = $sub->status;
            break;
        case 'publish_due':
            $out['result'] = app(ArticleWorkflowService::class)->publishDueSchedule($args['schedule_id']);
            break;
        case 'publish_all':
            $out['result'] = app(ArticleWorkflowService::class)->publishDueSchedules();
            break;
        case 'otp_verify':
            try {
                app(OtpService::class)->verify(User::findOrFail($args['user_id']), $args['code']);
                $out['result'] = 'ok';
            } catch (OtpException $e) {
                $out['result'] = $e->getMessage();
            }
            break;
        case 'featured':
            $img = ArticleImage::findOrFail($args['image_id']);
            app(ArticleImageService::class)->update($img, ['is_featured' => true]);
            $out['result'] = 'done';
            break;
        case 'deadlock':
            // Opposite lock order in two processes = a genuine InnoDB deadlock (1213); DB::transaction(.., 3) must retry.
            $attempts = 0;
            DB::transaction(function () use ($args, &$attempts) {
                $attempts++;
                DB::table('users')->where('id', $args['first'])->lockForUpdate()->first();
                usleep(700000);
                DB::table('users')->where('id', $args['second'])->lockForUpdate()->first();
                DB::table('users')->where('id', $args['first'])->update(['first_name' => 'w'.getmypid()]);
            }, 3);
            $out['result'] = 'done';
            $out['attempts'] = $attempts;
            break;
        default:
            throw new InvalidArgumentException("unknown mode {$mode}");
    }
} catch (CheckoutException $e) {
    $out['result'] = 'checkout:'.$e->getMessage();
} catch (Throwable $e) {
    $out['error'] = get_class($e).': '.$e->getMessage();
}
echo "\n".json_encode($out)."\n";
