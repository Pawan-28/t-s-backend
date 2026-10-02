<?php

namespace App\Services\Import\Support;

use Illuminate\Database\QueryException;
use RuntimeException;

/** Fatal, user-facing import error (never contains row data or secrets). */
class ImportAbort extends RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode = 1, public readonly ?string $entity = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Strip bound values / DETAIL lines / quoted MySQL values out of driver errors so PII and secrets never reach the console or report. */
    public static function sanitize(\Throwable $e): string
    {
        $msg = $e instanceof QueryException && $e->getPrevious() ? $e->getPrevious()->getMessage() : $e->getMessage();
        $msg = preg_split('/\R/', $msg)[0] ?? $msg;
        $msg = preg_replace('/DETAIL:.*$/s', '', $msg) ?? $msg;
        $msg = preg_replace('/\(Connection:.*$/s', '', $msg) ?? $msg;
        $msg = preg_replace('/Key \(.*$/s', '', $msg) ?? $msg;
        // MySQL / MariaDB quote the offending VALUE in some messages: "Duplicate entry 'alice@x.test' for key ...",
        // "Incorrect string value: '\xF0...' for column", "Incorrect datetime value: '...' for column", "Data truncated ...".
        $msg = preg_replace("/(Duplicate entry )'.*?'( for key)/s", "$1'***'$2", $msg) ?? $msg;
        $msg = preg_replace("/(Incorrect [a-z]+ value: )'.*?'( for column)/s", "$1'***'$2", $msg) ?? $msg;
        $msg = preg_replace("/(Invalid (?:JSON text|datetime format)[^']*)'.*$/s", '$1***', $msg) ?? $msg;
        $msg = preg_replace('/^SQLSTATE\[[0-9A-Z]+\]: /', '', $msg) ?? $msg;

        return trim($msg);
    }
}
