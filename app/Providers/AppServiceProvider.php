<?php

namespace App\Providers;

use App\Services\Ai\AiProvider;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\OpenAiClient;
use App\Services\EntitlementService;
use App\Support\DjangoPbkdf2Hasher;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EntitlementService::class);

        // Which AI provider answers "AI analysis": AI_PROVIDER=gemini|openai; unset -> gemini when a Gemini key exists.
        $this->app->bind(AiProvider::class, function ($app) {
            $choice = (string) config('portal.ai.provider');
            if (! in_array($choice, ['gemini', 'openai'], true)) {
                $choice = trim((string) config('portal.gemini.api_key')) !== '' ? 'gemini' : 'openai';
            }

            return $choice === 'gemini' ? $app->make(GeminiClient::class) : $app->make(OpenAiClient::class);
        });
    }

    public function boot(): void
    {
        // Verify imported Django pbkdf2_sha256 hashes; every other hash is bcrypt.
        // needsRehash() is true for legacy hashes so login upgrades them.
        Hash::extend('bcrypt', fn ($app) => new DjangoPbkdf2Hasher(new BcryptHasher($app['config']['hashing.bcrypt'] ?? [])));
    }
}
