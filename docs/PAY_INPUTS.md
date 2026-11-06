# Pay inputs

Amounts entered for a pay period: bonuses, incentives, variable allowances. One value per pay period, employee and component.
Needs the pay periods package installed first.

## Install
1. Copy over the project. Overwrites: app/Providers/AppServiceProvider.php, config/permissions.php, config/navigation.php, config/reencrypt.php
   (all four already contain the loans, hire purchases and pay periods changes).
   New: 1 migration, 4 models, app/Payroll/PayInputSheet.php, app/Services/PayInputService.php and PayInputImportService.php, 2 controllers,
   routes/pay_inputs.php, resources/views/payroll/inputs/*, tests/Unit/Payroll/PayInputSheetTest.php.
2. `php artisan migrate`
3. `php artisan db:seed --class=AccessControlSeeder`   (adds pay-inputs.view / manage / import to Super Admin)
4. `php artisan optimize:clear` and `php artisan route:clear`
5. Give roles the permissions. Payroll > Pay inputs.
6. Needs the PHP zip and xml extensions (Excel files), as for the loan import.
7. Tests: `php artisan test tests/Unit/Payroll/PayInputSheetTest.php`

## Permissions
pay-inputs.view (see inputs and history), pay-inputs.manage (enter, replace, remove on screen), pay-inputs.import (template, upload, apply, reverse).
Users see only inputs of employees in their schemes and uploads of their schemes (schemes.all sees everything). Out-of-scope records are 404.

## What can be entered
Any ACTIVE pay component whose method is "Entered each period" (input) or "Variable amount" (variable). For a variable component the value
entered for a period replaces the amount on the employee's salary for that period only.
An input can be entered only for an employee who: is in the scheme of the pay period, is active and in payroll, and has the component on their
salary at the END of the period. Others are not listed on the screen and are errors in an upload.

## Rules
* **Open periods only.** Every change checks that the pay period is Open (PayPeriodService::assertOpen). Closed or locked = no changes, no uploads,
  no reversals. Reopen the period (pay-periods.reopen) to change inputs again.
* **Replace.** A new amount replaces the value in force; the old one is kept in the history (pay_input_history: old and new amount, who, when, source,
  upload). The history is never edited or deleted. Zero is allowed (it overrides a variable amount with nothing); negatives are not.
* **Remove** is on the Inputs screen (needs manage); it is recorded in the history. An Excel upload cannot remove: an empty cell changes nothing.
* **Screen entry**: pick scheme and period, pick a component, fill the amounts of the employees, Save. Blank = leave as it is.
* **Excel**: Import from Excel > pick the period > download the sheet (one row per employee who has at least one chosen component, one column per
  component, titled with the component code; existing values are filled in) > fill > upload. The file is checked row by row (employee found in your schemes
  and in the scheme of the period, active, component on salary, amount valid, duplicates). Nothing changes until you confirm (typed APPLY); only the valid
  rows are applied, all together. A sheet belongs to ONE pay period (an "Info" sheet inside says which): another period's sheet is refused.
  Excel may drop leading zeros of an employee number: it is still matched when only one employee fits (with a note).
* **Reverse an upload** (while the period is Open): values it entered are removed and values it replaced are put back. Refused if any of its values was
  changed afterwards (on screen or by another upload): correct those on the screen.
* **Approval**: auto-approved (approval_status columns are ready for the approval engine). No notes field.
* **Amounts** (inputs, history, staged rows) are encrypted at rest and listed in config/reencrypt.php. No amounts are written to the audit log (it records
  who, which component, how many values, which period).

## For the pay run (not built yet)
PayInputService::amountsForRun($period, $employeeIds) returns employee id => [component id => amount] (approved values). For a variable
component a value there overrides the amount on the salary for the run; for an "input" component it is the amount.
A pay run must also require the period to be Closed before it locks it, and must not read inputs of a period that is still Open without warning.
