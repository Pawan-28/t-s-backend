<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Named-scope rate limiter driven by config('portal.throttle') (same scopes and
 * numbers as the Django project). Usage: ->middleware('throttle.scope:auth_login_ip,auth_login_identity').
 * Counters live in the configured cache store (config cache.limiter, else the default CACHE_STORE:
 * database | file | redis). Fails OPEN: if that store is unreachable the request is let through (logged),
 * so a rate-limit outage never becomes a site outage.
 */
class ScopeThrottle
{
    private const PERIODS = ['second' => 1, 'sec' => 1, 'minute' => 60, 'min' => 60, 'hour' => 3600, 'day' => 86400];

    public function handle(Request $request, Closure $next, string ...$scopes)
    {
        $hits = [];
        try {
            foreach ($scopes as $scope) {
                $cfg = config("portal.throttle.$scope");
                if (! $cfg) {
                    continue;
                }
                [$max, $decay] = self::parse($cfg['rate']);
                $key = 'throttle:'.$scope.':'.self::identity($request, $cfg['key']);
                if (RateLimiter::tooManyAttempts($key, $max)) {
                    $wait = RateLimiter::availableIn($key);
                    throw new TooManyRequestsHttpException($wait, "Request was throttled. Expected available in {$wait} seconds.");
                }
                $hits[] = [$key, $decay];
            }
            foreach ($hits as [$key, $decay]) {
                RateLimiter::hit($key, $decay);
            }
        } catch (TooManyRequestsHttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Rate limiter backend unavailable; failing open: '.$e->getMessage());
        }

        return $next($request);
    }

    public static function parse(string $rate): array
    {
        [$n, $period] = array_pad(explode('/', $rate, 2), 2, 'minute');

        return [(int) $n, self::PERIODS[strtolower(trim($period))] ?? 60];
    }

    public static function identity(Request $request, string $type): string
    {
        $email = static fn () => substr(hash('sha256', mb_strtolower(trim((string) $request->input('email')))), 0, 32);

        return match ($type) {
            'user' => $request->user() ? 'user-'.$request->user()->getAuthIdentifier() : 'ip-'.$request->ip(),
            'identity' => $request->ip().'-'.$email(),
            'account' => $email(),
            default => 'ip-'.$request->ip(),
        };
    }
}
