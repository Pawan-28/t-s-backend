<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;
use App\Services\Import\Support\Rules;

/**
 * accounts_user -> users. Primary keys preserved. PBKDF2 (and bcrypt) hashes are
 * copied UNCHANGED; unusable / unsupported hashes become an unusable random
 * bcrypt hash (user must use password reset). is_superuser => ADMIN.
 * Groups / permissions / is_staff are not rebuilt.
 */
class UserImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'email', 'password', 'first_name', 'last_name', 'phone', 'phone_verified_at', 'role', 'is_active', 'is_staff', 'is_superuser', 'last_login', 'created_at', 'updated_at'];

    public function name(): string
    {
        return 'users';
    }

    public function sourceTable(): string
    {
        return 'accounts_user';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        $plan = $ctx->plan;

        $email = $plan->emails[$id] ?? mb_strtolower(trim((string) $row['email']));
        if ($email === '') {
            $email = "legacy-user-{$id}@invalid.local";
        }

        if (array_key_exists($id, $plan->phones)) {
            $phone = $plan->phones[$id];
        } else {
            $p = trim((string) $row['phone']);
            $phone = $p === '' ? null : $p;
        }

        if (self::bool($row['is_superuser'])) {
            $role = 'ADMIN';
        } else {
            $role = in_array($row['role'], Rules::roles(), true) ? $row['role'] : Rules::DEFAULT_ROLE;
        }

        [$hash] = Rules::passwordHash($row['password'], fn () => $ctx->unusableHash());

        return [
            'id' => $id,
            'email' => $email,
            'password' => $hash,
            'first_name' => self::str($row['first_name']),
            'last_name' => self::str($row['last_name']),
            'phone' => $phone,
            'phone_verified_at' => $phone === null ? null : $row['phone_verified_at'],
            'role' => $role,
            'is_active' => isset($plan->deactivate[$id]) ? false : self::bool($row['is_active']),
            'last_login' => $row['last_login'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
