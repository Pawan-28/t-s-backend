<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;

/**
 * Laravel's `throttle:<named limiter>` middleware with the SAME fail-open policy as ScopeThrottle: when the
 * configured cache store (database / file / Redis) is unreachable the request is let through and the failure
 * is logged, so a rate-limit backend outage never becomes a site outage. Limits themselves (Limit::perMinute
 * in AnalyticsAiServiceProvider) and the 429 response are unchanged. Registered as the `throttle` alias.
 * Only the limiter's own cache calls are guarded; exceptions from the controller/downstream propagate.
 */
class FailOpenThrottleRequests extends ThrottleRequests
{
    protected function handleRequest($request, Closure $next, array $limits)
    {
        try {
            foreach ($limits as $limit) {
                if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                    throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
                }
            }

            foreach ($limits as $limit) {
                if (! $limit->afterCallback) {
                    $this->limiter->hit($limit->key, $limit->decaySeconds);
                }
            }
        } catch (ThrottleRequestsException|HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Rate limiter backend unavailable; failing open: '.$e->getMessage());

            return $next($request);
        }

        $response = $next($request);

        try {
            foreach ($limits as $limit) {
                if ($limit->afterCallback && ($limit->afterCallback)($response)) {
                    $this->limiter->hit($limit->key, $limit->decaySeconds);
                }

                $response = $this->addHeaders(
                    $response,
                    $limit->maxAttempts,
                    $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts)
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Rate limiter backend unavailable after the request; failing open: '.$e->getMessage());
        }

        return $response;
    }
}
