<?php

namespace App\Services\Ai;

use RuntimeException;

/** Failure talking to the AI provider. The message is ALWAYS safe to store/show (no keys, no provider bodies). */
class AiProviderException extends RuntimeException {}
