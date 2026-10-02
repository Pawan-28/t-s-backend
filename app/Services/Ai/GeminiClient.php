<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini (Generative Language API, generateContent). The API key travels in the
 * x-goog-api-key header, never in the URL, so it cannot end up in access/error logs.
 * Uses the Http facade so tests can Http::fake. Exceptions carry sanitised messages only.
 */
class GeminiClient implements AiProvider
{
    public function name(): string
    {
        return 'GEMINI';
    }

    public function apiKey(): string
    {
        return trim((string) config('portal.gemini.api_key'));
    }

    public function model(): string
    {
        return trim((string) config('portal.gemini.model')) ?: 'gemini-3.5-flash';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function completeJson(string $system, string $user): string
    {
        if (! $this->isConfigured()) {
            throw new AiProviderException('The AI provider is not configured (set GEMINI_API_KEY).');
        }

        $base = rtrim(trim((string) config('portal.gemini.base_url')) ?: 'https://generativelanguage.googleapis.com/v1beta', '/');
        $model = rawurlencode($this->model());

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey()])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout(max(5, (int) config('portal.gemini.timeout', 60)))
                ->post("{$base}/models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'responseMimeType' => 'application/json',
                    ],
                ]);
        } catch (ConnectionException) {
            throw new AiProviderException('The AI provider could not be reached (network error or timeout).');
        } catch (\Throwable) {
            throw new AiProviderException('The AI provider request failed.');
        }

        if (! $response->successful()) {
            $status = $response->status();
            $why = match (true) {
                $status === 400 => 'the provider rejected the request or the API key',
                $status === 401 || $status === 403 => 'the provider rejected the credentials',
                $status === 404 => 'the model was not found - check GEMINI_MODEL',
                $status === 429 => 'the provider rate limit or quota was reached',
                $status >= 500 => 'the provider had a server error',
                default => 'the provider rejected the request',
            };
            throw new AiProviderException("The AI provider request failed (HTTP {$status}: {$why}).");
        }

        if ($response->json('promptFeedback.blockReason')) {
            throw new AiProviderException('The AI provider blocked this article (safety filter).');
        }

        $text = '';
        foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
            if (is_array($part) && is_string($part['text'] ?? null) && empty($part['thought'])) {
                $text .= $part['text'];
            }
        }
        if (trim($text) === '') {
            throw new AiProviderException('The AI provider returned an unexpected response shape.');
        }

        return $text;
    }
}
