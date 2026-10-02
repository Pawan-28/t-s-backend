<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Page;
use App\Support\Passwords;
use App\Support\Permissions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/** Admin-only user management + Sanctum token listings + Django Groups compatibility stubs. */
class AccountsAdminController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $q = User::query()->orderByDesc('created_at')->orderByDesc('id');
        if ($role = $request->query('role')) {
            $q->where('role', $role);
        }
        if (($active = $request->query('is_active')) !== null && $active !== '') {
            $q->where('is_active', filter_var($active, FILTER_VALIDATE_BOOL));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $q->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
        }

        return response()->json(Page::make($q, $request, fn ($u) => (new UserResource($u))->resolve()));
    }

    public function showUser(User $user): JsonResponse
    {
        return (new UserResource($user))->response();
    }

    /** POST /accounts/users/ : create an account with a role, optional password-less invite is NOT supported (a password is required). */
    public function storeUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:filter', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
            'password2' => ['nullable', 'string', 'max:128'],
            'first_name' => ['nullable', 'string', 'max:150'],
            'last_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['sometimes', Rule::enum(Role::class)],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $errors = $this->identityErrors($data, null);
        if (array_key_exists('password2', $data) && $data['password2'] !== null && $data['password2'] !== $data['password']) {
            $errors['password2'][] = 'Passwords do not match.';
        }
        if ($pw = Passwords::errors($data['password'], self::normalizeEmail($data['email']))) {
            $errors['password'] = $pw;
        }
        $errors += $this->permissionErrors($data['permissions'] ?? null);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $role = Role::from($data['role'] ?? Role::USER->value);
        try {
            $user = new User([
                'email' => self::normalizeEmail($data['email']),
                'password' => Hash::make($data['password']),
                'first_name' => $data['first_name'] ?? '',
                'last_name' => $data['last_name'] ?? '',
                'phone' => ($data['phone'] ?? '') !== '' ? $data['phone'] : null,
            ]);
            $user->role = $role; // role/is_active/permissions are not mass-assignable
            $user->is_active = $data['is_active'] ?? true;
            $user->permissions = $role === Role::ADMIN ? null : Permissions::clean($data['permissions'] ?? []);
            $user->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['An account with this email or phone number already exists.']]);
        }

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'email' => ['sometimes', 'email:filter', 'max:254'],
            'password' => ['sometimes', 'nullable', 'string', 'max:128'],
            'password2' => ['sometimes', 'nullable', 'string', 'max:128'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'role' => ['sometimes', Rule::enum(Role::class)],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $actor = $request->user();
        $self = $actor->is($user);

        // Self-lockout guard (same as Django).
        if ($self) {
            if (isset($data['role']) && $data['role'] !== Role::ADMIN->value) {
                throw ValidationException::withMessages(['role' => ['You cannot remove your own admin role.']]);
            }
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => ['You cannot deactivate your own account.']]);
            }
        }
        // The system must always keep at least one active administrator.
        $losesAdmin = $user->isAdmin() && $user->is_active
            && ((isset($data['role']) && $data['role'] !== Role::ADMIN->value) || (array_key_exists('is_active', $data) && ! $data['is_active']));
        if ($losesAdmin && User::query()->where('role', Role::ADMIN->value)->where('is_active', true)->whereKeyNot($user->id)->doesntExist()) {
            throw ValidationException::withMessages(['role' => ['This is the only active administrator: promote another admin first.']]);
        }

        $errors = $this->identityErrors($data, $user);
        $newPassword = ($data['password'] ?? '') !== '' ? $data['password'] : null;
        if ($newPassword !== null) {
            if (($data['password2'] ?? null) !== null && $data['password2'] !== $newPassword) {
                $errors['password2'][] = 'Passwords do not match.';
            }
            if ($pw = Passwords::errors($newPassword, self::normalizeEmail($data['email'] ?? $user->email))) {
                $errors['password'] = $pw;
            }
        }
        $errors += $this->permissionErrors($data['permissions'] ?? null);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        try {
            DB::transaction(function () use ($data, $user, $newPassword) {
                $user->forceFill(array_filter([
                    'email' => isset($data['email']) ? self::normalizeEmail($data['email']) : null,
                    'first_name' => array_key_exists('first_name', $data) ? ($data['first_name'] ?? '') : null,
                    'last_name' => array_key_exists('last_name', $data) ? ($data['last_name'] ?? '') : null,
                    'role' => $data['role'] ?? null,
                    'is_active' => $data['is_active'] ?? null,
                    'password' => $newPassword !== null ? Hash::make($newPassword) : null,
                ], fn ($v) => $v !== null));
                if (array_key_exists('phone', $data)) {
                    $user->phone = ($data['phone'] ?? '') !== '' ? $data['phone'] : null;
                }
                if (array_key_exists('permissions', $data) || (isset($data['role']) && $data['role'] === Role::ADMIN->value)) {
                    $user->permissions = $user->isAdmin() ? null : Permissions::clean($data['permissions'] ?? $user->permissions ?? []);
                }
                $user->save();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['An account with this email or phone number already exists.']]);
        }

        // Sessions are cut when the account is deactivated or its password is changed (the admin's own current session survives a self password change).
        if (($data['is_active'] ?? true) === false) {
            $user->tokens()->delete();
        } elseif ($newPassword !== null) {
            $keep = $self ? $actor->currentAccessToken()?->id : null;
            $user->tokens()->when($keep, fn ($q) => $q->where('id', '!=', $keep))->delete();
        }

        return (new UserResource($user->refresh()))->response();
    }

    /** GET /accounts/user-permissions/ : the grantable feature permissions (the Django Permission catalog below stays empty). */
    public function permissionCatalog(): JsonResponse
    {
        $out = [];
        foreach (Permissions::catalog() as $key => $meta) {
            $out[] = ['key' => $key] + $meta;
        }

        return response()->json($out);
    }

    /** @param  array<string, mixed>  $data @return array<string, list<string>> */
    private function identityErrors(array $data, ?User $existing): array
    {
        $errors = [];
        if (isset($data['email'])) {
            $email = self::normalizeEmail($data['email']);
            $q = User::query()->where('email', $email);
            if ($existing) {
                $q->whereKeyNot($existing->id);
            }
            if ($q->exists()) {
                $errors['email'][] = 'An account with this email already exists.';
            }
        }
        if (isset($data['phone']) && $data['phone'] !== '') {
            $q = User::query()->where('phone', $data['phone']);
            if ($existing) {
                $q->whereKeyNot($existing->id);
            }
            if ($q->exists()) {
                $errors['phone'][] = 'An account with this phone number already exists.';
            }
        }

        return $errors;
    }

    /** @param  array<int, mixed>|null  $permissions @return array<string, list<string>> */
    private function permissionErrors(?array $permissions): array
    {
        $unknown = array_values(array_filter(array_map('strval', $permissions ?? []), fn ($k) => ! Permissions::exists($k)));

        return $unknown ? ['permissions' => ['Unknown permission(s): '.implode(', ', $unknown).'.']] : [];
    }

    private static function normalizeEmail(string $email): string
    {
        return AuthController::normalizeEmail($email);
    }

    // ---- Django Groups/Permissions: intentionally NOT rebuilt (they granted nothing) ----
    public function groups(Request $request): JsonResponse
    {
        return response()->json(Page::make([], $request));
    }

    public function groupsUnsupported(): JsonResponse
    {
        return response()->json(['detail' => 'Legacy Django groups are not used by this system.'], 405);
    }

    public function permissions(): JsonResponse
    {
        return response()->json([]);
    }

    // ---- Sanctum token listings (replace the SimpleJWT outstanding/blacklisted pages) ----
    public function outstandingTokens(Request $request): JsonResponse
    {
        $q = PersonalAccessToken::query()->where('tokenable_type', User::class)->orderByDesc('id');
        if ($uid = $request->query('user')) {
            $q->where('tokenable_id', $uid);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $q->where(function ($w) use ($like, $search) {
                $w->whereIn('tokenable_id', User::query()->where('email', 'like', $like)->select('id'));
                if (ctype_digit($search)) {
                    $w->orWhere('id', (int) $search);
                }
            });
        }
        $emails = fn ($ids) => User::query()->whereIn('id', $ids)->pluck('email', 'id');

        $payload = Page::make($q, $request);
        $rows = collect($payload['results']);
        $map = $emails($rows->pluck('tokenable_id')->unique());
        $payload['results'] = $rows->map(fn ($t) => $this->tokenRow($t, $map[$t->tokenable_id] ?? ''))->all();

        return response()->json($payload);
    }

    public function outstandingToken(int $id): JsonResponse
    {
        $t = PersonalAccessToken::query()->where('tokenable_type', User::class)->findOrFail($id);

        return response()->json($this->tokenRow($t, (string) User::query()->whereKey($t->tokenable_id)->value('email')));
    }

    /** Revoked Sanctum tokens are deleted, so there is no blacklist table: always empty. */
    public function blacklistedTokens(Request $request): JsonResponse
    {
        return response()->json(Page::make([], $request));
    }

    private function tokenRow(PersonalAccessToken $t, string $email): array
    {
        return [
            'id' => $t->id,
            'jti' => (string) $t->id,
            'user_email' => $email,
            'created_at' => $t->created_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'is_blacklisted' => false,
        ];
    }
}
