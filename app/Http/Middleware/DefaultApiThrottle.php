<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** DRF-equivalent default limits: anon 100/min per IP, authenticated 1000/min per user. */
class DefaultApiThrottle
{
    public function __construct(private ScopeThrottle $throttle) {}

    public function handle(Request $request, Closure $next)
    {
        // Only a token that really authenticates earns the (higher) per-user budget; a
        // bogus/expired Authorization header must not lift the anonymous per-IP limit.
        $scope = $request->bearerToken() && $request->user() ? 'user' : 'anon';

        return $this->throttle->handle($request, $next, $scope);
    }
}
