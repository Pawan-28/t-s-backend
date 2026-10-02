<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Thin OpenAI Chat Completions client (alternative provider, AI_PROVIDER=openai). Uses the Http
 * facade so tests can Http::fake. Exceptions carry sanitised messages only.
 */
class OpenAiClient implements AiProvider
{
    public function name(): string
    {
        return 'OPENAI';
    }

    public function apiKey(): string
    {
        return trim((string) config('portal.openai.api_key'));
    }

    public function model(): string
    {
        return trim((string) config('portal.openai.model')) ?: 'gpt-4o-mini';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Sends a system + user message pair and returns the assistant message
     * content (a JSON string, response_format=json_object).
     *
     * @throws AiProviderException
     */
    public function completeJson(string $system, string $user): string
    {
        if (! $this->isConfigured()) {
            throw new AiProviderException('The AI provider is not configured.');
        }

        $base = rtrim(trim((string) config('portal.openai.base_url')) ?: 'https://api.openai.com/v1', '/');

        try {
            $response = Http::withToken($this->apiKey())
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout(max(5, (int) config('portal.openai.timeout', 60)))
                ->post($base.'/chat/completions', [
                    'model' => $this->model(),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                ]);
        } catch (ConnectionException) {
            throw new AiProviderException('The AI provider could not be reached (network error or timeout).');
        } catch (RequestException|\Throwable) {
            throw new AiProviderException('The AI provider request failed.');
        }

        if (! $response->successful()) {
            $status = $response->status();
            $why = match (true) {
                $status === 401 || $status === 403 => 'the provider rejected the credentials',
                $status === 429 => 'the provider rate limit or quota was reached',
                $status >= 500 => 'the provider had a server error',
                default => 'the provider rejected the request',
            };
            throw new AiProviderException("The AI provider request failed (HTTP {$status}: {$why}).");
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new AiProviderException('The AI provider returned an unexpected response shape.');
        }

        return $content;
    }
}
