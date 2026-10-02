<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Usage: ->middleware('role:ADMIN') or 'role:ADMIN,REPORTER'. Admin is an explicit role, no superuser bypass. */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();
        if (! $user || ! $user->is_active || ! in_array($user->role->value, $roles, true)) {
            throw new AccessDeniedHttpException('You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
