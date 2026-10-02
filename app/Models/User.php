<?php

namespace App\Models;

use App\Enums\Role;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    /** Privilege/state columns are never mass-assignable; code sets them explicitly (forceFill / property). */
    protected $guarded = ['id', 'role', 'is_active', 'permissions', 'phone_verified_at', 'last_login'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_active' => 'boolean',
            'permissions' => 'array',
            'phone_verified_at' => 'datetime',
            'last_login' => 'datetime',
        ];
    }

    public function getFullNameAttribute(): string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $name !== '' ? $name : (string) $this->email;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::ADMIN;
    }

    public function isReporter(): bool
    {
        return $this->role === Role::REPORTER;
    }

    /** Effective feature permissions: every permission for an active ADMIN, the granted list otherwise, none when inactive. @return list<string> */
    public function effectivePermissions(): array
    {
        if (! $this->is_active) {
            return [];
        }
        if ($this->isAdmin()) {
            return Permissions::keys();
        }

        return Permissions::clean($this->permissions);
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->effectivePermissions(), true);
    }

    /** Admin, or granted "Manage all articles". */
    public function canManageArticles(): bool
    {
        return $this->is_active && ($this->isAdmin() || $this->hasPermission(Permissions::ARTICLES_MANAGE));
    }

    /** Admin, or granted "Review, publish & schedule". */
    public function canPublishArticles(): bool
    {
        return $this->is_active && ($this->isAdmin() || $this->hasPermission(Permissions::ARTICLES_PUBLISH));
    }

    /** May act on articles they neither wrote nor are assigned to (see them in lists, open them, use workflow actions on them). */
    public function hasArticleOversight(): bool
    {
        return $this->canManageArticles() || $this->canPublishArticles();
    }

    /** Anyone allowed to work on articles in the newsroom UI: admin, reporter, or an account granted an article permission. */
    public function isArticleStaff(): bool
    {
        return $this->is_active && ($this->isAdmin() || $this->isReporter() || $this->hasArticleOversight());
    }

    /** Admin, or granted "Manage categories & tags". */
    public function canManageTaxonomy(): bool
    {
        return $this->is_active && ($this->isAdmin() || $this->hasPermission(Permissions::TAXONOMY_MANAGE));
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function categoryAssignments(): HasMany
    {
        return $this->hasMany(ReporterCategoryAssignment::class, 'reporter_id');
    }
}
