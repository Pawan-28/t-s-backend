<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** The frontend sends no Accept header; force JSON so Laravel never redirects/renders HTML. */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
