# Pay inputs: entry form changes

## Install
1. Copy over the project. Overwrites (compare with your copies if you changed them): app/Services/PayInputService.php,
   app/Http/Controllers/PayInputController.php, routes/pay_inputs.php, resources/views/payroll/inputs/entry.blade.php,
   resources/views/payroll/inputs/index.blade.php.
   New: 1 migration, app/Models/PayInputAddition.php.
2. `php artisan migrate`
3. `npm run build` (or keep `npm run dev` running): the pages use new Tailwind classes.
4. `php artisan optimize:clear` and `php artisan route:clear`. No new permission (adding employees needs pay-inputs.manage).

## What changed
1. **Bulk entry** (Enter on screen): tick employees (or "select all shown"), type one amount, press "Set for selected" or "Set for all shown". This only fills
   the boxes; nothing is saved until "Save amounts". Rows hidden by a filter are never touched. "Reset selected to saved values" undoes it. Only boxes that
   differ from the saved value are submitted, and Save is disabled while any box holds an invalid amount.
2. **Department**: a column on the entry form and the Inputs list, and a department filter on both. On the entry form the filters (search, department, new only)
   work in the browser, so what you typed is kept while you filter.
3. **New employees**: highlighted green with a "New" badge, on the entry form and the Inputs list, with a "New employees only" filter on both.
   There is no pay run yet, so "new" means: joined after the end of the scheme's previous pay period (when there is no previous period: after the day before
   this period starts). PayInputService::newCutoff() / isNew() hold the rule, so it can be switched to "not in the previous pay run" when the pay run exists.
4. **Add employees for this period only**: "Add employees" on the entry form opens a popup listing the payroll employees of the scheme who do not have the
   component (not on their salary, not already added), with search, department and "new only" filters and select all. Selected employees are added for this
   pay period only: the salary is NOT changed. They appear in the form at once, marked "Added for this period", without reloading (typed amounts are kept).
   "Take out of this period" removes an addition while no amount was entered for it. Additions need an Open period and are audited.
   An addition counts everywhere as having the component for that period: the entry form, the Excel template and the Excel upload.

## For the pay run (not built yet)
PayInputService::salaryMap($period, $employeeIds) already merges the additions, and addableEmployees()/addEmployees() work on pay_input_additions.
The pay run must treat an addition as the component being allocated to that employee for that period (use salaryMap(), not the salary lines alone).

## Also fixed
After saving, the page now stays on the chosen component (the redirect used a wrong parameter name before).
