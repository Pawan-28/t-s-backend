<?php

namespace App\Services\Import\Support;

use App\Enums\AccessLevel;
use App\Enums\AdPlacement;
use App\Enums\ArticleStatus;
use App\Enums\NotificationType;
use App\Enums\ReviewAction;
use App\Enums\Role;

/**
 * Single source of truth for value rules shared by the pre-import validator and
 * the importers, so what is reported is exactly what is applied.
 */
class Rules
{
    public const SUBSCRIPTION_STATUSES = ['PENDING', 'ACTIVE', 'EXPIRED', 'CANCELLED'];

    public const PAYMENT_STATUSES = ['CREATED', 'PAID', 'FAILED'];

    public const SCHEDULE_STATUSES = ['PENDING', 'EXECUTED', 'CANCELLED'];

    public const CHECK_STATUSES = ['PENDING', 'COMPLETED', 'FAILED'];

    /** Defaults applied (only with --resolve-defaults) for out-of-range enum values. */
    public const DEFAULT_ARTICLE_STATUS = 'DRAFT';        // never accidentally public

    public const DEFAULT_ACCESS_LEVEL = 'RESTRICTED';     // most restrictive: never leak a body

    public const DEFAULT_ROLE = 'USER';                   // least privilege

    public const DEFAULT_SUBSCRIPTION_STATUS = 'CANCELLED'; // grants no entitlement, no lifecycle jobs

    public const DEFAULT_PAYMENT_STATUS = 'FAILED';

    public const DEFAULT_SCHEDULE_STATUS = 'CANCELLED';

    public const DEFAULT_CHECK_STATUS = 'FAILED';

    /** Placement renamed in Django migration advertisements.0002. */
    public const PLACEMENT_RENAMES = ['SIDEBAR' => 'HOME_SIDEBAR'];

    public static function values(string $enum): array
    {
        return array_map(fn ($c) => $c->value, $enum::cases());
    }

    public static function roles(): array
    {
        return self::values(Role::class);
    }

    public static function articleStatuses(): array
    {
        return self::values(ArticleStatus::class);
    }

    public static function accessLevels(): array
    {
        return self::values(AccessLevel::class);
    }

    public static function placements(): array
    {
        return self::values(AdPlacement::class);
    }

    public static function notificationTypes(): array
    {
        return self::values(NotificationType::class);
    }

    public static function reviewActions(): array
    {
        return self::values(ReviewAction::class);
    }

    /** @return array{0: string, 1: bool} [hash to store, replaced?] */
    public static function passwordHash(?string $hash, callable $unusableFactory): array
    {
        $hash = (string) $hash;
        if (preg_match('/^pbkdf2_sha256\$\d+\$[^$]+\$[A-Za-z0-9+\/=]+$/', $hash) || preg_match('/^\$2[abxy]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash)) {
            return [$hash, false];
        }

        return [$unusableFactory(), true];
    }

    /** Django hash algorithm label for reporting (no secrets). */
    public static function hashAlgorithm(?string $hash): string
    {
        $hash = (string) $hash;
        if ($hash === '') {
            return 'blank';
        }
        if ($hash[0] === '!') {
            return 'unusable';
        }
        if (str_starts_with($hash, '$2')) {
            return 'bcrypt';
        }
        $p = explode('$', $hash, 2)[0];

        return $p !== '' && strlen($p) < 30 ? $p : 'unknown';
    }

    /** Normalise a phone: strip separators; valid E.164-ish -> normalised value. */
    public static function normalizePhone(string $raw): array
    {
        $trim = trim($raw);
        $stripped = preg_replace('/[\s\-().]/', '', $trim) ?? $trim;
        $valid = (bool) preg_match('/^\+?[0-9]{7,15}$/', $stripped);

        return ['value' => $valid ? $stripped : $trim, 'valid' => $valid, 'changed' => $valid && $stripped !== $trim];
    }
}
