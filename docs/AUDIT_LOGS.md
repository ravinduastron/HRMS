# Audit log module

## Install
1. Copy this folder over the project (overwrites: permissions/navigation config, routes, User/Role models,
   User/Role/Password/Login controllers, users/show view. Changed lines are marked `// AUDIT`).
2. Append `.env.additions` to `.env`.
3. `php artisan migrate`
4. `php artisan db:seed --class=AccessControlSeeder`   (adds audit.view / audit.export to Super Admin)
5. `php artisan optimize:clear` then `php artisan event:list | grep -i audit` (should show the Login/Logout listeners)
6. Open Administration > Audit log.

Optional scheduler (routes/console.php): `Schedule::command('audit:prune')->daily();`
Weekly integrity check: `Schedule::command('audit:verify')->weekly();`

## Applying it to any module
```php
use App\Models\Concerns\Auditable;

class Employee extends Model
{
    use Auditable;

    protected array $auditHidden  = ['nic', 'bank_account_no']; // never stored; entry shows "value hidden"
    protected array $auditExclude = ['last_seen_at'];           // ignored
    public function auditLabel(): string { return $this->full_name; }
}
```
* Attributes with `encrypted*` or `hashed` casts are hidden automatically. If you encrypt in an accessor/mutator
  or with your own service, list the attribute in `$auditHidden`.
* Detail page history: `@can('audit.view') <x-audit-timeline :model="$employee" /> @endcan`
* Business events: `Audit::log('payrun.approved', $payRun, 'September run approved', old: [...], new: [...]);`
* Relationship syncs (pivot tables, Spatie roles/permissions) fire no model events: log them manually
  (see UserController / RoleController for the pattern: capture before, sync, capture after, log if different).

## NOT captured automatically (by design of Eloquent)
`Model::query()->update()/delete()`, `DB::table()...`, `saveQuietly()`, `withoutEvents()`, `insert()`, `upsert()`,
`increment()/decrement()`, raw SQL, and changes made directly in the database. In payroll code, loop and save models
(or call `Audit::log` with a summary) for any bulk change that matters. Use `Audit::withoutAuditing()` only for imports,
and log one summary entry.

## Separate database
Recommended for production. Add to `config/database.php` > `connections`:
```php
'audit' => [
    'driver' => 'mysql',
    'host' => env('DB_AUDIT_HOST', '127.0.0.1'),
    'port' => env('DB_AUDIT_PORT', '3306'),
    'database' => env('DB_AUDIT_DATABASE', 'payroll_audit'),
    'username' => env('DB_AUDIT_USERNAME'),
    'password' => env('DB_AUDIT_PASSWORD'),
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    'timezone' => '+05:30',
],
```
Set `AUDIT_DB_CONNECTION=audit`. Run `php artisan migrate` once with an account that can CREATE tables, then
switch the app to an insert-only account:
```sql
CREATE DATABASE payroll_audit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'audit_writer'@'%' IDENTIFIED BY '...';
GRANT INSERT, SELECT ON payroll_audit.* TO 'audit_writer'@'%';   -- no UPDATE, no DELETE
```
`audit:prune` needs DELETE: run it with separate credentials (or drop old partitions instead).
