# Salary adjustments (increments etc.)

Change the salary amounts of the employees of ONE scheme in bulk: increment amount, increment portion (percentage) or new salary, on screen or
through one long Excel sheet. Every adjustment is staged and reviewed before it is applied, and an applied one can be rolled back.
Needs the salary structures / employee salary, pay inputs (Excel reader and writer) and statutory packages already installed.

## Install
1. Copy over the project. Overwrites: app/Providers/AppServiceProvider.php, config/permissions.php, config/navigation.php, config/reencrypt.php
   (all four already contain the earlier modules; this package only adds to them).
   New: 1 migration, 3 models, app/Payroll/SalaryAdjustment.php and SalaryAdjustmentSheet.php, app/Services/SalaryAdjustmentService.php,
   app/Http/Controllers/SalaryAdjustmentController.php, routes/salary_adjustments.php, resources/views/salary/adjustments/*,
   tests/Unit/Payroll/SalaryAdjustmentTest.php.
2. `php artisan migrate`
3. `php artisan db:seed --class=AccessControlSeeder`   (adds the salary-adjustments.* permissions to Super Admin)
4. `php artisan optimize:clear` and `php artisan route:clear`
5. Give roles the permissions. Compensation > Salary adjustments.
6. Excel needs the PHP zip and xml extensions, as for the other imports.
7. Tests: `php artisan test tests/Unit/Payroll/SalaryAdjustmentTest.php`

## Permissions
* salary-adjustments.view: list and open adjustments of your schemes (amounts masked; "Show amounts" also needs employee-salary.reveal and is audited).
* salary-adjustments.manage: prepare on screen, apply and discard SCREEN adjustments.
* salary-adjustments.import: download the sheet, upload, apply and discard EXCEL adjustments.
* salary-adjustments.rollback: roll back an applied adjustment (separate on purpose).
Users see only adjustments of schemes allocated to them (schemes.all sees every scheme); out-of-scope pages are 404.

## The three modes
* **Increment amount**: value is added (minus sign reduces). 50,000 + 2,500 = 52,500.
* **Increment portion**: value is a percentage of the current amount, added to it (10 = +10%, -5 = -5%, from -100 to 1000, up to 4 decimals).
* **New salary**: the amount becomes the value (zero or more).
Results are rounded to 2 decimals, half up. A result below 0 is an error; exactly 0 is allowed.

## What can be adjusted
Amounts of components calculated as Fixed or Variable that the employee has WITH an amount on the effective date. The rate basis (monthly, daily,
hourly) is kept. A variable component with no amount is an error (set an amount first). The employee must be in the chosen scheme, active and in payroll.
Pay rules (hours limit, statutory exemption) and every other component are carried forward unchanged.

## On screen
Compensation > Salary adjustments > New adjustment: scheme and effective date > Show employees > reason, how (mode), value, components, tick employees
(search, department, select all shown) > Preview. The same mode and value apply to every chosen component. Combinations where the employee does not
have a chosen component are left out and counted in the notes. To mix modes, use Excel.

## Excel (one long sheet)
Import from Excel: choose scheme and date, download the sheet (Employee No, Name, Component, Current amount, Mode, Value; one row per employee and
component that has an amount), fill Mode (drop-down: Increment amount / Increment portion / New salary) and Value on the rows to change, upload with a reason.
* Rows with Mode and Value both empty are ignored; a half-filled row is an error row.
* "Current amount" is printed only if you may show salary amounts (employee-salary.reveal); otherwise it is blank. Every download is audited (no amounts).
* The sheet belongs to ONE scheme (an Info sheet says which); another scheme's sheet is refused. The effective date and reason are the ones you enter
  on the upload form; new amounts are always worked out from the salary on that date, not from the printed "Current amount".
* Excel may drop leading zeros of an employee number: it is still matched when only one employee fits (with a note).

## Review, apply, discard
Both ways create a STAGED adjustment: every row is checked (employee found and in your scheme, active, component with an amount, valid mode and value,
duplicates, result not below 0) and shows the current and new amount (masked until "Show amounts"). Nothing changes until you press Apply (typed APPLY).
* Only the valid rows are applied, all together; error rows are skipped (fix and prepare them again).
* If any employee's salary changed since the preview, or the employee left payroll, NOTHING is applied and you are told to prepare it again.
* Each employee gets ONE new immutable salary version effective on the adjustment's date, through EmployeeSalaryService::setAmounts(), so history,
  encryption, scheme scope and the existing audit entries are unchanged. Rows whose new amount equals the current one are counted as unchanged.
* A back-dated adjustment shows a note (arrears belong to the pay run) and rows of employees who already have a LATER salary change are warned that
  the later version does not include the adjustment.
* Discard (staged only) changes nothing.

## Rollback
Needs salary-adjustments.rollback, a reason and a typed ROLLBACK. It adds new salary versions with the old amounts, effective on the adjustment's
date (so the history reads as if it never applied); nothing is deleted. ALL OR NOTHING: if the salary of ANY employee in the adjustment was changed
after it (their latest salary version is no longer the one the adjustment created), the whole rollback is refused and the first employee numbers are named.
Applied versions and rollback versions are linked on salary_adjustment_lines.

## Amounts and audit
Staged rows and the applied lines (old / new amount) are encrypted at rest and listed in config/reencrypt.php. No amounts are written to the audit log:
it records who, which scheme, the date, counts and the reason (staged, applied, rolled back, discarded, template downloaded, amounts revealed).

## For the pay run (not built yet)
Add a rule that an adjustment cannot be rolled back once a locked pay run has used the salary it created. Arrears for back-dated adjustments are the
pay run's job.
