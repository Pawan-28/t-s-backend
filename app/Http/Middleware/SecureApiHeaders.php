<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response hardening for the JSON API (registered on the `api` group):
 *  - X-Content-Type-Options: nosniff and a locked-down framing/referrer/CSP policy on every response;
 *  - Cache-Control: no-store (+ Vary: Authorization) whenever the request carried a bearer token or
 *    touched the auth endpoints, so tokens, personal data and entitlement-dependent article bodies are
 *    never kept by a shared cache or a browser history entry. Anonymous public reads keep whatever
 *    caching headers the route set.
 */
class SecureApiHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        if ($request->bearerToken() || $request->is('api/auth/*')) {
            $h->set('Cache-Control', 'no-store, private');
            $h->set('Pragma', 'no-cache');
            $vary = array_filter(array_map('trim', explode(',', (string) $h->get('Vary', ''))));
            if (! in_array('authorization', array_map('strtolower', $vary), true)) {
                $vary[] = 'Authorization';
            }
            $h->set('Vary', implode(', ', $vary));
        }

        return $response;
    }
}
