<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = ['name', 'username', 'email', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected ?Collection $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'password'             => 'hashed',
            'is_active'            => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at'  => 'datetime',
            'last_failed_login_at' => 'datetime',
            'locked_until'         => 'datetime',
        ];
    }

    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class);
    }

    /* ---------- Permissions: only ACTIVE roles grant access ---------- */

    public function effectivePermissionNames(): Collection
    {
        return $this->permissionCache ??= $this->loadMissing('roles.permissions')
            ->roles->where('is_active', true)
            ->flatMap(fn ($role) => $role->permissions)
            ->pluck('name')->unique()->values();
    }

    // Spatie's Gate integration calls this, so @can, can: middleware and $user->can() all respect role status.
    public function checkPermissionTo($permission, $guardName = null): bool
    {
        $name = is_object($permission) ? ($permission->value ?? $permission->name ?? null) : $permission;

        return is_string($name) && $this->effectivePermissionNames()->contains($name);
    }

    /* ---------- Password ageing ---------- */

    public function passwordExpired(): bool
    {
        return $this->password_changed_at !== null
            && $this->password_changed_at->copy()->addDays(config('password_policy.max_age_days'))->isPast();
    }

    /* ---------- Lockout ---------- */

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function registerFailedLogin(): void
    {
        $cfg = config('password_policy');

        if ($this->last_failed_login_at && $this->last_failed_login_at->lt(now()->subMinutes($cfg['lockout_reset_minutes']))) {
            $this->failed_login_attempts = 0;
        }

        $this->failed_login_attempts++;
        $this->last_failed_login_at = now();

        if ($this->failed_login_attempts >= $cfg['lockout_threshold']) {
            $this->locked_until = now()->addMinutes($cfg['lockout_minutes']);
            $this->failed_login_attempts = 0;
        }

        $this->save();
    }

    public function clearFailedLogins(): void
    {
        $this->forceFill(['failed_login_attempts' => 0, 'last_failed_login_at' => null, 'locked_until' => null])->save();
    }
}
