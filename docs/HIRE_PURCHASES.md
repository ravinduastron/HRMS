# Hire purchases

Employees buy products from vendors. The vendor gives the monthly installment and the number of installments; the company deducts the installment
from the employee's salary and pays the vendors the monthly totals. If the employee leaves, the company stops the deductions (what happens
with the vendor afterwards is not the company's responsibility).

## Install
1. Copy over the project. Overwrites: app/Providers/AppServiceProvider.php, config/permissions.php, config/navigation.php, config/reencrypt.php.
   New: routes/hire_purchases.php, 1 migration, 4 models + 1 scope, app/Payroll/HirePurchase/HirePurchaseSchedule.php, app/Services/HirePurchaseService.php,
   3 controllers, resources/views/hire-purchases/*, resources/views/employees/tabs/_hire_purchases.blade.php, database/seeders/HirePurchaseComponentSeeder.php,
   tests/Unit/HirePurchase/HirePurchaseScheduleTest.php.
2. ONE manual edit in resources/views/employees/show.blade.php (it is large):
   a) in the $tabs array, after the 'loans' line, add
        'hirepurchases' => ['label' => 'Hire purchases', 'ready' => true, 'visible' => auth()->user()->can('hire-purchases.view')],
   b) after the line   @can('loans.view')@include('employees.tabs._loans')@endcan   add
        @can('hire-purchases.view')@include('employees.tabs._hire_purchases')@endcan
3. `php artisan migrate`
4. `php artisan db:seed --class=AccessControlSeeder`          (adds the hire-purchases.* and hire-purchase-vendors.manage permissions to Super Admin)
5. `php artisan db:seed --class=HirePurchaseComponentSeeder`  (creates the deduction HIRE_PURCHASE in a group "Hire purchase"; safe to re-run)
6. `php artisan optimize:clear` and `php artisan route:clear`
7. Give roles the permissions. Add vendors (Payroll > Hire purchases > Vendors), then create hire purchases.
8. Tests: `php artisan test tests/Unit/HirePurchase`

## Permissions
* hire-purchases.view: list, detail, vendors list, monthly deductions, the employee Hire purchases tab.
* hire-purchases.manage: create, change future deductions, "Record as deducted".
* hire-purchases.stop: stop the deductions.
* hire-purchase-vendors.manage: add, edit, deactivate vendors.
Everything follows the scheme restrictions: users see only hire purchases of employees in their schemes (out-of-scope pages are 404).

## Rules
* **Vendor**: code (cannot change), name, contact person, phone, email, address. Contact details only. Never deleted, only deactivated
  (existing hire purchases are not affected; an inactive vendor cannot be chosen for new ones).
* **New hire purchase**: scheme, then employee; vendor; pay component (any active deduction, HIRE_PURCHASE is preselected when it exists);
  what was bought; installment amount; number of installments (1 to 120); first deduction month; optional reference (blank = HP-000123) and notes.
  Installments are always equal, one per month, from the first month. A reference must be unique for the employee.
  An employee who has left or is inactive cannot be given a new hire purchase.
* **Change future deductions** (hire purchase page): choose the month the change applies from, the new installment amount and the new number of
  installments. The unpaid installments from that month are marked "replaced" and new ones are created. Installments already deducted are never touched
  and a change cannot start in a month that was already deducted. Optional "Deduct again from" (a later month) skips the months in between. A preview shows
  installment, number, months and total to deduct before and after. Every change is kept in the history with who, when and why.
* **Stop deductions**: needs a reason and a typed confirmation. All unpaid installments become "stopped", the hire purchase closes as "stopped".
  It is FINAL: to deduct again, enter a new hire purchase. Deducted installments stay.
* **Completed**: when the last installment is deducted the hire purchase closes as "completed".
* **Employees who leave**: nothing is stopped automatically. A hire purchase of an employee who is inactive here or in MiHCM, or whose last working day
  has passed, is flagged "Employee has left" on the list, the detail page, the employee tab and the monthly deductions screen (with a warning).
  A person decides to stop. HirePurchaseService::hasLeft() is what the pay run should use to flag the same employees.
* **Monthly deductions** (Payroll > Hire purchases > Monthly deductions): per month and scheme, three views: By hire purchase, By vendor (grouped, with a
  total per vendor and the number of employees: the amounts to pay the vendors at month end) and By pay item. All export to CSV. Installments of earlier
  months never deducted show as Overdue. "Record as deducted" (typed confirmation) rolls the month's installments forward until the pay run does it.
* **Amounts** (hire purchase, installments, change history) are encrypted at rest and listed in config/reencrypt.php. No amounts are written to the audit
  log (it records who, what, which months and how many installments; the amounts are in the encrypted change history).

## For the pay run (not built yet)
* HirePurchaseService::dueForPeriod($month, $employeeIds) gives each employee's installments for the month (earlier unpaid ones flagged overdue), with
  the pay component of each line; componentAmounts($lines) gives the amount per pay component for the payslip.
* The run must call recordDeduction($installment, $date) for every installment when it locks, inside a transaction, and flag employees for whom
  hasLeft() is true.

## Decisions built in
Money to the vendors is not recorded (only the totals are shown); no bank details on vendors; equal installments only; a stop is final; a skipped
month is done through "Change future deductions".
