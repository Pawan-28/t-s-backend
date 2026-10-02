<?php

namespace App\Services\Ai;

/** A text-analysis provider that answers one system+user prompt with a JSON object string. */
interface AiProvider
{
    /** Stored in ai_analysis_results.provider (e.g. GEMINI, OPENAI). */
    public function name(): string;

    public function model(): string;

    public function isConfigured(): bool;

    /**
     * @return string the model's JSON answer
     *
     * @throws AiProviderException (messages are sanitised: never contain keys or raw provider bodies)
     */
    public function completeJson(string $system, string $user): string;
}
