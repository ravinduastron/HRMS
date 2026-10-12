# Scheme-based access (employee groups)

## Install
1. Copy over the project. (Overwrites config/permissions.php, config/navigation.php, routes/web.php, User/Employee/BankAccount
   models, EmployeeController, UserController, BankMasterController, BankAccountService, EmployeeSyncService, sidebar,
   audit-timeline, employees index/show and users _form/show views: they already contain the earlier modules.)
2. `php artisan migrate`   (creates schemes + scheme_user, adds employees.scheme_id, and creates a scheme from every
   scheme_name already synced, linking the employees)
3. `php artisan db:seed --class=AccessControlSeeder`   (adds the schemes.* permissions to Super Admin)
4. `php artisan optimize:clear`

## IMPORTANT: who sees what after this change
* Super Admin: sees everything (has schemes.all).
* Every other user sees NO employees until you (a) allocate schemes (Administration > Schemes, or the Scheme access
  card on the user form) or (b) give their role the "Access ALL schemes" permission.
* Employees with no scheme are visible only to users with schemes.all.
* Audit log, Sync history, and Schemes administration are visible only to users with schemes.all.

## How it works
* Employee (and BankAccount, through its employee) have a global scope. Route-model binding uses it, so an
  out-of-scope employee URL returns 404. Console, queue and the MiHCM sync run unscoped (no signed-in user), and the sync
  explicitly bypasses the scope as well.
* The MiHCM sync matches schemes on `schemes.mihcm_name`, creates unknown ones (flagged in the run's notes) and sets
  employees.scheme_id. Rename a scheme freely (display name); if MiHCM renames it, update the "MiHCM scheme name".
* Every allocation change is audited (user.schemes_changed / scheme.users_changed).

## Tests
tests/Feature/SchemeScopeTest.php. Run on MySQL/MariaDB (generated column in the bank migration):
`php artisan test --filter=SchemeScopeTest`
