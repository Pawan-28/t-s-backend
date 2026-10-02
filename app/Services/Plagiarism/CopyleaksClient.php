<?php

namespace App\Services\Plagiarism;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Copyleaks Scans API client (same endpoints Django used). The bearer token is
 * cached (Cache) until shortly before it expires. Never logs/returns secrets.
 */
class CopyleaksClient
{
    public const LOGIN_URL = 'https://id.copyleaks.com/v3/account/login/api';

    public const SUBMIT_URL = 'https://api.copyleaks.com/v3/scans/submit/file/';

    public const RESULT_URL = 'https://api.copyleaks.com/v3.4/downloads/%s/result';

    public const TOKEN_CACHE_KEY = 'copyleaks:access_token';

    public function isConfigured(): bool
    {
        return $this->email() !== '' && $this->apiKey() !== '';
    }

    private function loginUrl(): string
    {
        $b = rtrim((string) config('portal.copyleaks.login_base'), '/');

        return $b !== '' ? $b.'/v3/account/login/api' : self::LOGIN_URL;
    }

    private function submitUrl(): string
    {
        $b = rtrim((string) config('portal.copyleaks.api_base'), '/');

        return $b !== '' ? $b.'/v3/scans/submit/file/' : self::SUBMIT_URL;
    }

    private function resultUrl(): string
    {
        $b = rtrim((string) config('portal.copyleaks.api_base'), '/');

        return $b !== '' ? $b.'/v3.4/downloads/%s/result' : self::RESULT_URL;
    }

    private function email(): string
    {
        return trim((string) config('portal.copyleaks.email'));
    }

    private function apiKey(): string
    {
        return trim((string) config('portal.copyleaks.api_key'));
    }

    private function http(?string $token = null): PendingRequest
    {
        $req = Http::acceptJson()->asJson()->connectTimeout(10)->timeout(max(5, (int) config('portal.copyleaks.timeout', 30)));

        return $token ? $req->withToken($token) : $req;
    }

    private function token(bool $forceRefresh = false): string
    {
        if (! $this->isConfigured()) {
            throw new PlagiarismProviderException('The plagiarism provider is not configured.');
        }
        if ($forceRefresh) {
            Cache::forget(self::TOKEN_CACHE_KEY);
        } elseif (is_string($cached = Cache::get(self::TOKEN_CACHE_KEY)) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->http()->post($this->loginUrl(), ['email' => $this->email(), 'key' => $this->apiKey()]);
        } catch (\Throwable $e) {
            throw new PlagiarismProviderException($e instanceof ConnectionException
                ? 'Copyleaks login failed (network error or timeout).'
                : 'Copyleaks login failed.');
        }
        if ($response->status() !== 200) {
            throw new PlagiarismProviderException("Copyleaks login failed (HTTP {$response->status()}).");
        }
        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new PlagiarismProviderException('Copyleaks login response did not include an access token.');
        }

        // Copyleaks tokens last ~48h and report an absolute `.expires`; cache a bit shorter.
        $ttl = 3600;
        $expires = ($response->json() ?? [])['.expires'] ?? null;
        if (is_string($expires)) {
            try {
                $ttl = (int) now()->diffInSeconds(Carbon::parse($expires), false) - 300;
            } catch (\Throwable) {
                $ttl = 3600;
            }
        }
        Cache::put(self::TOKEN_CACHE_KEY, $token, (int) max(60, min($ttl, 47 * 3600)));

        return $token;
    }

    /** Runs an authenticated call, re-logging in once on a 401 (expired/revoked cached token). */
    private function authed(callable $call): Response
    {
        $token = $this->token();
        try {
            $response = $call($this->http($token), $token);
            if ($response->status() === 401) {
                $token = $this->token(true);
                $response = $call($this->http($token), $token);
            }

            return $response;
        } catch (PlagiarismProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PlagiarismProviderException($e instanceof ConnectionException
                ? 'Copyleaks request failed (network error or timeout).'
                : 'Copyleaks request failed.');
        }
    }

    /** @throws PlagiarismProviderException */
    public function submitTextScan(string $scanId, string $text, string $webhookUrl): void
    {
        $response = $this->authed(fn (PendingRequest $r) => $r->put($this->submitUrl().rawurlencode($scanId), [
            'base64' => base64_encode($text),
            'filename' => "article-{$scanId}.txt",
            'properties' => [
                'webhooks' => ['status' => $webhookUrl],
                'sandbox' => (bool) config('portal.copyleaks.sandbox', true),
            ],
        ]));

        if (! in_array($response->status(), [200, 201], true)) {
            throw new PlagiarismProviderException("Copyleaks submit-scan failed (HTTP {$response->status()}).");
        }
    }

    /**
     * Full result report of a completed scan, fetched with OUR credentials (so a
     * forged webhook can never inject scores).
     *
     * @return array<string,mixed>
     *
     * @throws PlagiarismProviderException
     */
    public function getScanResult(string $scanId): array
    {
        $response = $this->authed(fn (PendingRequest $r) => $r->get(sprintf($this->resultUrl(), rawurlencode($scanId))));

        if ($response->status() !== 200) {
            throw new PlagiarismProviderException("Copyleaks result fetch failed (HTTP {$response->status()}).");
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new PlagiarismProviderException('Copyleaks result response was not valid JSON.');
        }

        return $json;
    }
}
