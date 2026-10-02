<?php

use App\Http\Middleware\DefaultApiThrottle;
use App\Http\Middleware\EnsureAccessToken;
use App\Http\Middleware\FailOpenThrottleRequests;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\RejectMalformedUtf8;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\ScopeThrottle;
use App\Http\Middleware\SecureApiHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies (X-Forwarded-*) come from config/trustedproxy.php (env TRUSTED_PROXIES). They must NOT be read
        // with env() here: once `php artisan config:cache` has run, .env is no longer loaded and env() outside config/ is null.

        // Frontend sends no Accept header: force JSON for every API request.
        $middleware->api(prepend: [SecureApiHeaders::class, ForceJsonResponse::class, RejectMalformedUtf8::class]);
        $middleware->api(append: [DefaultApiThrottle::class]);

        // Authenticated API routes: Sanctum bearer token that carries the `access` ability.
        $middleware->group('api.auth', ['auth:sanctum', EnsureAccessToken::class]);

        // Authorisation must run BEFORE route-model binding, otherwise a non-admin probing /accounts/users/{id}
        // would see 404 (no such user) vs 403 (exists) and could enumerate user ids.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureAccessToken::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RequireRole::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RequirePermission::class);

        $middleware->alias([
            'role' => RequireRole::class,
            'perm' => RequirePermission::class,
            'throttle.scope' => ScopeThrottle::class,
            'throttle' => FailOpenThrottleRequests::class,   // named limiters (ai-check, plagiarism-check): fail open like throttle.scope
            'abilities' => CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);

        // DRF-compatible error envelopes: {"detail": "..."} or {"field": ["msg"]}.
        $detail = static fn (string $message, int $status, array $headers = []) => response()->json(['detail' => $message], $status, $headers);

        $exceptions->render(function (ValidationException $e) {
            return response()->json($e->errors(), 400);
        });
        $exceptions->render(function (AuthenticationException $e) use ($detail) {
            return $detail('Authentication credentials were not provided.', 401, ['WWW-Authenticate' => 'Bearer']);
        });
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e) use ($detail) {
            $msg = $e->getMessage();
            if ($msg === '' || str_starts_with($msg, 'This action is unauthorized')) {
                $msg = 'You do not have permission to perform this action.';
            }

            return $detail($msg, 403);
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e) use ($detail) {
            $msg = $e instanceof NotFoundHttpException && $e->getMessage() === 'Invalid page.' ? 'Invalid page.' : 'Not found.';

            return $detail($msg, 404);
        });
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($detail) {
            return $detail('Method "'.$request->method().'" not allowed.', 405, $e->getHeaders());
        });
        $exceptions->render(function (TooManyRequestsHttpException $e) use ($detail) {
            return $detail($e->getMessage() ?: 'Request was throttled.', 429, $e->getHeaders());
        });
        $exceptions->render(function (HttpExceptionInterface $e) use ($detail) {
            return $detail($e->getMessage() ?: 'Error.', $e->getStatusCode(), $e->getHeaders());
        });
        $exceptions->render(function (Throwable $e) use ($detail) {
            if (config('app.debug')) {
                return null; // default debug rendering (JSON with trace)
            }
            report($e);

            return $detail('Server error.', 500);
        });
    })->create();
