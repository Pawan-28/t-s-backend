<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Runs after auth:sanctum. Only tokens carrying the `access` ability may call
 * the API (a refresh token must never authenticate a request), and inactive
 * accounts are rejected immediately.
 */
class EnsureAccessToken
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user || ! $token || ! method_exists($token, 'can') || ! $token->can('access') || ! $user->is_active) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
