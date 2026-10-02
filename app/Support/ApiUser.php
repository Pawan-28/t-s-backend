<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The acting user for endpoints that are public but personalise output
 * (article lists/detail, search, related): a valid ACCESS token of an active
 * account, otherwise null. Refresh tokens never count as a login.
 */
class ApiUser
{
    public static function resolve(?Request $request = null): ?User
    {
        $request ??= request();
        $user = $request->bearerToken() ? $request->user() : null;
        if (! $user instanceof User || ! $user->is_active) {
            return null;
        }
        $token = $user->currentAccessToken();
        if (! $token || ! method_exists($token, 'can') || ! $token->can('access')) {
            return null;
        }

        return $user;
    }
}
