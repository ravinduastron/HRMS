<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use Auditable; // AUDIT

    public const SUPER_ADMIN = 'Super Admin';

    protected $casts = ['is_active' => 'boolean'];

    public function isSuperAdmin(): bool
    {
        return $this->name === self::SUPER_ADMIN;
    }
}
