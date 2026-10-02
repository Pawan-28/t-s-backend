<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Query-string and form fields can carry raw invalid UTF-8 (JSON bodies cannot: json_decode already refuses them).
 * MySQL/MariaDB strict mode rejects such text with error 1366 ("Incorrect string value") and the framework then
 * cannot even JSON-encode its own error, which surfaces as HTTP 500. Refuse it up front with a 400, like DRF does
 * for undecodable input.
 */
class RejectMalformedUtf8
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::valid($request->query->all()) || ! self::valid($request->request->all())) {
            return response()->json(['detail' => 'Malformed request: text must be valid UTF-8.'], 400);
        }

        return $next($request);
    }

    private static function valid(mixed $value): bool
    {
        if (is_string($value)) {
            return $value === '' || mb_check_encoding($value, 'UTF-8');
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (! self::valid($k) || ! self::valid($v)) {
                    return false;
                }
            }
        }

        return true;
    }
}
