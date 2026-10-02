<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;
use App\Services\Import\Support\Rules;

/** Razorpay ids are kept; the signature is copied to the DB but never logged or reported. */
class PaymentImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'subscription_id', 'user_id', 'razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature', 'amount', 'currency', 'status', 'failure_reason', 'created_at', 'updated_at'];

    public function name(): string
    {
        return 'payments';
    }

    public function sourceTable(): string
    {
        return 'subscriptions_payment';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        if (! $ctx->has('subscriptions', $row['subscription_id'])) {
            $ctx->skip('payments', $id, 'PAY-SUBSCRIPTION-ORPHAN', 'subscription '.($row['subscription_id'] ?? 'NULL').' does not exist or was not imported');

            return null;
        }
        if (! $ctx->has('users', $row['user_id'])) {
            $ctx->skip('payments', $id, 'PAY-USER-ORPHAN', 'user '.($row['user_id'] ?? 'NULL').' does not exist');

            return null;
        }

        return [
            'id' => $id,
            'subscription_id' => (int) $row['subscription_id'],
            'user_id' => (int) $row['user_id'],
            'razorpay_order_id' => $ctx->plan->value('payments', 'razorpay_order_id', $id, $row['razorpay_order_id']),
            'razorpay_payment_id' => self::str($row['razorpay_payment_id']),
            'razorpay_signature' => self::str($row['razorpay_signature']),
            'amount' => $row['amount'],
            'currency' => $row['currency'],
            'status' => in_array($row['status'], Rules::PAYMENT_STATUSES, true) ? $row['status'] : Rules::DEFAULT_PAYMENT_STATUS,
            'failure_reason' => self::str($row['failure_reason']),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
