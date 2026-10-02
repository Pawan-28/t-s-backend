<?php

namespace App\Services\Media;

use RuntimeException;

/** Storage-API failure. Messages never contain the AccessKey or request headers. */
class BunnyStorageException extends RuntimeException {}
