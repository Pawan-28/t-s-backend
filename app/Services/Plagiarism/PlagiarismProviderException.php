<?php

namespace App\Services\Plagiarism;

use RuntimeException;

/** Copyleaks failure. Message is always safe to store/show (no secrets, no provider bodies). */
class PlagiarismProviderException extends RuntimeException {}
