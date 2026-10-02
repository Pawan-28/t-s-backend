<?php

namespace App\Services\Subscriptions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Razorpay Orders API + signature checks. One-time payments only. Secrets are never logged. */
class RazorpayClient
{
    public function keyId(): string
    {
        return trim((string) config('portal.razorpay.key_id'));
    }

    private function keySecret(): string
    {
        return trim((string) config('portal.razorpay.key_secret'));
    }

    public function isConfigured(): bool
    {
        return $this->keyId() !== '' && $this->keySecret() !== '';
    }

    /**
     * @return array<string,mixed> the Razorpay order (has "id")
     *
     * @throws CheckoutException
     */
    public function createOrder(int $amountPaise, string $currency, string $receipt): array
    {
        if (! $this->isConfigured()) {
            throw new CheckoutException('Could not start checkout: payments are not configured.');
        }
        try {
            $response = Http::withBasicAuth($this->keyId(), $this->keySecret())
                ->acceptJson()->asJson()
                ->timeout(30)
                ->post(rtrim((string) config('portal.razorpay.api_base'), '/').'/orders', [
                    'amount' => $amountPaise,
                    'currency' => $currency,
                    'receipt' => $receipt,
                    'payment_capture' => 1,
                ]);
        } catch (Throwable $e) {
            Log::warning('Razorpay order creation failed (network).', ['error' => $e::class]);
            throw new CheckoutException('Could not start checkout: the payment provider is unreachable.');
        }

        $order = $response->json();
        if (! in_array($response->status(), [200, 201], true) || ! is_array($order) || ! is_string($order['id'] ?? null) || $order['id'] === '') {
            Log::warning('Razorpay order creation failed.', ['status' => $response->status()]);
            throw new CheckoutException('Could not start checkout: the payment provider rejected the request.');
        }

        return $order;
    }

    /** HMAC-SHA256("order_id|payment_id", key_secret). Fails closed without a secret. */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = $this->keySecret();
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $orderId.'|'.$paymentId, $secret), $signature);
    }

    /** HMAC-SHA256(raw body, webhook_secret). Fails closed without a secret. */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = trim((string) config('portal.razorpay.webhook_secret'));
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }
}
