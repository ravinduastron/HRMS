# Employees (MiHCM sync) module

## Install
1. Copy over the project (overwrites config/permissions.php, config/navigation.php, routes/web.php: they already contain the earlier modules).
2. Append `.env.additions` to `.env` and fill in MIHCM_PRIMARY_KEY / MIHCM_SECONDARY_KEY.
3. `php artisan migrate`
4. `php artisan db:seed --class=AccessControlSeeder`   (grants the new employees.* permissions to Super Admin)
5. `php artisan optimize:clear`
6. Make sure a queue worker is running (`composer run dev` starts one, or `php artisan queue:work`).
7. Employees > Sync history > "Run sync now". The first run is a baseline: everyone is "imported", not "new".

Scheduling (routes/console.php):
    Schedule::command('mihcm:sync-employees --queue')->dailyAt('02:00')->withoutOverlapping();
Run inline from the shell: `php artisan mihcm:sync-employees`

## Rules built in
* Match key: employee_no (MiHCM employeeNumber, matched case-insensitively). MiHCM sends no other stable id, so if an
  employee's number is ever changed in MiHCM it shows up as one deactivated + one new employee.
* The sync never writes is_active / is_payroll_active.
* The API is fetched completely first; DB changes are applied in one transaction. A failed or partial fetch changes nothing.
* Employees no longer returned by the API are marked MiHCM-inactive, unless that would exceed
  `mihcm.max_missing_percent` (default 50%), in which case nothing is deactivated and the run shows a warning.
* NIC values are masked in sync changes and in the audit log (config/mihcm.php hidden_fields, Employee::$auditHidden).

## Adding bank / salary later
Create `employee_bank_accounts` / `employee_salaries` with `employee_id` FK -> employees.id (never employee_no),
add the relationship on Employee, create `employees/tabs/_bank.blade.php`, and set 'ready' => true for the tab in
employees/show.blade.php. Payroll queries should use `Employee::payrollEligible()`.

## Sync history in a separate (log) database
Set `MIHCM_LOG_CONNECTION` (e.g. `audit`) then `php artisan migrate` once: existing runs/changes are copied across and
removed from the main DB. The DB user for that connection needs, on `employee_sync_runs` and `employee_sync_changes`:
`INSERT, SELECT, UPDATE` (runs are updated while a sync progresses). No DELETE needed.

## SSL error "cURL error 60" on Windows (XAMPP/WAMP)
1. Download https://curl.se/ca/cacert.pem and save it, e.g. `C:\php\extras\ssl\cacert.pem`.
2. Run `php --ini` to find the php.ini the CLI/queue worker uses. In it set:
       curl.cainfo = "C:\php\extras\ssl\cacert.pem"
       openssl.cafile = "C:\php\extras\ssl\cacert.pem"
   (also in Apache's php.ini if it differs)
3. Restart Apache and the queue worker. Local-only shortcut: MIHCM_VERIFY_SSL=false, then `php artisan config:clear`.
