<?php

namespace App\Services\Media;

use RuntimeException;

/** An uploaded file failed validation; message is safe to show to the caller. */
class ImageValidationException extends RuntimeException {}
