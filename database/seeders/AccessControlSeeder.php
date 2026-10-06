<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class AccessControlSeeder extends Seeder
{
    /** Safe to re-run: syncs the permission catalogue and keeps Super Admin at "all permissions". */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = collect(config('permissions'))->flatMap(fn ($group) => array_keys($group));
        $names->each(fn ($name) => Permission::findOrCreate($name, 'web'));

        $role = Role::firstOrCreate(
            ['name' => Role::SUPER_ADMIN, 'guard_name' => 'web'],
            ['description' => 'Full access to every module', 'is_active' => true],
        );
        $role->syncPermissions($names->all());

        if (($admin = User::where('username', 'admin')->first()) && ! $admin->roles()->exists()) {
            $admin->assignRole($role);
        }
    }
}
