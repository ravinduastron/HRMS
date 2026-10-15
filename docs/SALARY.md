# Salary structures and employee salary

Requires the searchable-dropdown package (`<x-combobox>`, resources/js/app.js) to be installed first.

## Install
1. Copy over the project. Overwrites: config/permissions.php, config/navigation.php, config/reencrypt.php, routes/web.php,
   Employee model, EmployeeController, employees/show.blade.php (they contain the earlier modules).
2. `php artisan migrate`   (4 new tables; amounts are stored encrypted; a later migration allows several changes per date)
3. `php artisan db:seed --class=AccessControlSeeder`   (adds the permissions below to Super Admin)
4. `php artisan optimize:clear`
5. Give roles the new permissions. Salary tab, structures: Payroll > Compensation > Salary structures.

## Model
* **Structure** = template. Belongs to ONE scheme, has its own name (unique within the scheme), a list of pay components and an
  optional default amount for fixed-amount components. Users see and manage only the structures of schemes allocated to
  them (schemes.all sees every scheme). Out-of-scope pages return 404.
* **Employee salary** = the employee's own record. Assigning a structure COPIES its components onto the employee (a structure
  can only be assigned to employees of the same scheme). After that the employee's components are independent:
  editing, deactivating or deleting the structure never changes anyone already assigned.
* On the employee you can assign/replace the structure, add a component, remove a component, or change a fixed amount.
  Every action needs an effective date (any date for now) and a reason, and creates a new immutable version. History is kept.
* Only components calculated as "fixed" carry an amount (monthly equivalent, encrypted at rest). Formula, percentage, input and
  system components are listed without an amount.
* A component whose formula uses other components cannot be saved without them (on a structure or on an employee).
* Several changes can share one effective date (assign a structure, then set amounts, add and remove components). Each is its own
  history row; the last one saved is the salary in force. "Set amounts" saves many fixed amounts as ONE change.
* The Salary tab can show the salary as at any date ("View salary as at", or "View" on a history row). After a change it opens on
  the date that was changed. Amounts stay masked; "Show amounts" reveals the amounts for the date being viewed (audited).
* A back-dated change builds on the version in force on that date and does not alter later versions (a warning is shown).
  Arrears for back-dated changes belong to the pay run step.

## Permissions
salary-structures.view / .manage; employee-salary.view (tab, amounts masked), employee-salary.reveal ("Show amounts": one
button for all lines, audited on every click, hidden again after config/salary.php reveal_seconds), employee-salary.manage.

## Audit
Structure create/update and component-list changes, salary assigned/changed (which components, effective date and reason, never
amounts), and every reveal. `employee_salary_lines.amount` is in config/reencrypt.php for APP_KEY rotation.
