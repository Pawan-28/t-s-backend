<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Usage: ->middleware('perm:ads.manage') or 'perm:a,b' (any one). Active ADMINs hold every permission. */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();
        if (! $user || ! $user->is_active) {
            throw new AccessDeniedHttpException('You do not have permission to perform this action.');
        }
        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }
        throw new AccessDeniedHttpException('You do not have permission to perform this action.');
    }
}
