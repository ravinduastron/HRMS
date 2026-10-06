<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public const SUPER_ADMIN = 'Super Admin';

    protected $casts = ['is_active' => 'boolean'];

    public function isSuperAdmin(): bool
    {
        return $this->name === self::SUPER_ADMIN;
    }
}
