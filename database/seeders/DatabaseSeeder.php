<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! User::where('username', 'admin')->exists()) {
            $admin = (new User)->forceFill([
                'name'     => 'Payroll Admin',
                'username' => 'admin',
                'email'    => 'admin@astron.lk',
                'password' => 'ChangeMe#2026',
            ]);
            $admin->save();
            PasswordPolicy::apply($admin, 'ChangeMe#2026', true); // forces a change at first login
        }

        $this->call(AccessControlSeeder::class);
    }
}
