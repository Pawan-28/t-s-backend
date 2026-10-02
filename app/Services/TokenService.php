<?php

namespace App\Services;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum-only session tokens: one short-lived ACCESS token and one long-lived
 * REFRESH token per login. Refresh does NOT rotate/invalidate the refresh token
 * (this fixes the Django ~14-minute session drop); logout revokes both.
 */
class TokenService
{
    public function issuePair(User $user): array
    {
        return [
            'access' => $this->issueAccess($user),
            'refresh' => $user->createToken('refresh', ['refresh'], now()->addDays(config('portal.auth.refresh_days')))->plainTextToken,
        ];
    }

    public function issueAccess(User $user): string
    {
        return $user->createToken('access', ['access'], now()->addMinutes(config('portal.auth.access_minutes')))->plainTextToken;
    }

    /** Returns the owning active user for a valid, unexpired refresh token; otherwise null. */
    public function userForRefreshToken(mixed $plain): ?User
    {
        $token = $this->findRefreshToken($plain);

        return $token?->tokenable instanceof User && $token->tokenable->is_active ? $token->tokenable : null;
    }

    public function findRefreshToken(mixed $plain): ?PersonalAccessToken
    {
        if (! is_string($plain) || $plain === '') {
            return null;
        }
        $token = PersonalAccessToken::findToken($plain);
        if (! $token || ! $token->can('refresh') || ($token->expires_at && $token->expires_at->isPast())) {
            return null;
        }

        return $token;
    }
}
