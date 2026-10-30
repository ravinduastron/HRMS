# Loan module changes

## Install
1. Copy over the project. Overwrites: config/navigation.php, routes/loans.php, app/Http/Controllers/LoanController.php,
   resources/views/loans/create.blade.php. New: resources/views/employees/tabs/_loans.blade.php.
2. ONE manual edit in resources/views/employees/show.blade.php (it is large):
   a) in the $tabs array, after the 'leave' line, add
        'loans'      => ['label' => 'Loans', 'ready' => true, 'visible' => auth()->user()->can('loans.view')],
   b) after the line   @can('leave.view')@include('employees.tabs._leave')@endcan   add
        @can('loans.view')@include('employees.tabs._loans')@endcan
3. `php artisan route:clear` and `php artisan optimize:clear`. No migration, no new permission.

## What changed
* "Import from Excel" is no longer in the sidebar. It is still on the Loans screen (needs loans.import); the routes are unchanged.
* New loan: choose a SCHEME, then an EMPLOYEE (searchable dropdowns). Schemes listed = the user's allocated schemes
  (all schemes, plus "No scheme", for schemes.all). The employee list is loaded when a scheme is chosen
  (GET payroll/loans/employees?scheme=...): active employees of that scheme, limited by the normal scheme scope.
  The form posts employee_id; the scheme is only a filter and is not stored on the loan. An employee outside the user's schemes is refused.
* Employee page > Loans tab (needs loans.view): that employee's loans with outstanding balance, next installment, status,
  a link to each loan, and "New loan", which opens the form with the scheme and employee already chosen.
