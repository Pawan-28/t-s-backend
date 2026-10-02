<?php

namespace App\Services\Subscriptions;

use RuntimeException;

/** A checkout/verification request that cannot be fulfilled (rendered as HTTP 400 {"detail"}). */
class CheckoutException extends RuntimeException {}
