# User & role management module

## Install (on top of the starter pack)
1. `composer require spatie/laravel-permission`
2. `php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"`
3. Edit `config/permission.php` -> `'models' => ['role' => App\Models\Role::class, ...]`
4. Copy this folder over the project (overwrite when asked). Add `.env.additions` to `.env`.
5. `php artisan migrate`   (Spatie's migration must sort before `2026_12_01_*`; rename ours if not)
6. `php artisan db:seed --class=AccessControlSeeder`   (creates permissions + Super Admin, assigns it to `admin`)
7. `php artisan optimize:clear` then `npm run dev` (or `npm run build`)

Sign in as `admin`; if it still has the old password it will not be forced to change it
(only new/reset passwords are temporary). Create users at Administration > Users.

## Policy settings
`config/password_policy.php` (length, history, min/max age, lockout) and `config/accounts.php` (email domain, blocked terms).
Add permissions in `config/permissions.php` and re-run the seeder.
