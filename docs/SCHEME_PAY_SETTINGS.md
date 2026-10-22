# Scheme pay settings, provident fund default, daily/hourly rates, hours limit

## Install
1. Copy over the project. Overwrites: app/Providers/AppServiceProvider.php, config/permissions.php, app/Models/{Scheme,Employee,EmployeeSalaryVersion}.php,
   app/Services/Mihcm/{EmployeeMapper,EmployeeSyncService}.php, app/Services/{EmployeeSalaryService,AttendanceSummaryService}.php,
   app/Payroll/{AttendanceSummary,SystemVariables}.php, app/Http/Controllers/{EmployeeSalaryController,EmployeeFundController}.php,
   resources/views/schemes/show.blade.php, resources/views/employees/show.blade.php, resources/views/employees/tabs/_salary.blade.php.
   New routes live in routes/pay_settings.php, loaded by AppServiceProvider (so routes/web.php is not touched).
2. `php artisan migrate`
3. `php artisan db:seed --class=AccessControlSeeder`   (adds schemes.pay-settings to Super Admin)
4. `php artisan optimize:clear` (and `php artisan route:clear`). Restart the queue worker.
5. Administration > Schemes > (a scheme) > Pay settings. Add the first version for every scheme.

## What the migration does to existing data
* `schemes.default_fund` is set once from the old rule (MiHCM scheme "Casual" or "Contract" = EPF, all others = MSPS). Nothing changes on deploy.
* An employee whose fund already differs from their scheme's default is marked as set by hand (`provident_fund_override`), so that choice is kept.
* Existing salary lines become "monthly". Nobody gets an hours limit or a statutory exemption.

## Scheme pay settings (versioned, effective-dated, audited)
Pay cycles (must cover the month), a window for each of overtime / no-pay leave / late deductions / attendance allowance (same as the cycle, or a
custom window counted from the cycle's month), the pay-date rule, when EPF / MSPS / ETF are deducted, and bank or cash. A version is never edited:
add a new one with a later date and a reason. The "Periods for a month" table shows the exact dates before any pay run relies on them.
Permission: schemes.pay-settings (plus schemes.all). Viewing needs schemes.view (plus schemes.all).
Not used yet: the pay run (next step) reads these. The pay date is entered on the run and checked against the range.

## Default provident fund
Set on the scheme page. Applied to new employees, to employees who move scheme, at once when the default changes, and after every sync.
An employee switched by hand ("Switch to EPF/MSPS") is left alone until "Follow scheme default". No scheme names are in the code any more.
A scheme created by the sync starts as MSPS and the run notes tell you to set it.

## Rate basis, hours limit, statutory exemption (Employee > Salary)
* A fixed-amount line is monthly (as before), daily or hourly. Set it when adding a component or with "Change amount".
  Assigning a structure sets every line to monthly (structures do not carry a basis yet).
* "Pay rules": the most hours paid per month, and "No EPF / MSPS / ETF". Both are part of the salary version, so they have an effective date,
  a reason and history, and are carried to every later change.
* New formula variables: PAID_DAYS (present, or confirmed paid leave, on expected days), WORKED_HOURS (all hours worked, overtime included),
  PAID_HOURS (WORKED_HOURS limited to the hours limit), MAX_HOURS (the limit, 0 = none). The limit in force on the last day of the range is used.
* The payroll engine (next step) will pay: daily line = rate x PAID_DAYS, hourly line = rate x PAID_HOURS, monthly line = as now.
  Hourly employees should not also get separate overtime components, because PAID_HOURS already includes overtime hours.

## Known limits
* The hours limit is applied inside the range being summarised. For a scheme with two cycles a month it is not shared across the two cycles.
* Cycle start/end days are 1 to 28 (end 27 if another cycle follows).

## Tests
tests/Unit/Payroll/PayCalendarTest.php, tests/Unit/Attendance/PaidTimeTest.php: `php artisan test tests/Unit/Payroll tests/Unit/Attendance`.
