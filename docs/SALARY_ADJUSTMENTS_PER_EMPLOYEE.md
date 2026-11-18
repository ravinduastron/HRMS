# Salary adjustments: a different value for each employee

## Install
1. Copy over the project. Overwrites: app/Http/Controllers/SalaryAdjustmentController.php, routes/salary_adjustments.php,
   resources/views/salary/adjustments/create.blade.php. No migration, no new permission.
2. `php artisan route:clear` and `php artisan optimize:clear`.

## What changed (Compensation > Salary adjustments > New adjustment)
* A switch under Reason: "The same value for all selected employees" (as before) or "A different value for each employee".
* Different value: choose the components, then the table gets one column per chosen component and one box per employee who has it.
  One mode (increment amount / portion / new salary) applies to the whole screen. An EMPTY box means no change. Employees do not
  need to be ticked: every box with a value is included.
* "Value to fill in" + column + "Fill selected" / "Fill all shown" put one value in many boxes at once (they stay editable). "Clear all values" empties them.
* "Show current amounts" (needs employee-salary.reveal) prints the current amount under each box for a few seconds. It is fetched on demand,
  never put in the page, and audited (salary_adjustment.current_viewed, no amounts logged).
* Invalid boxes turn red and Preview stays disabled. The server checks every value again.
* The values are sent as ONE field (cells_json), so a large grid cannot hit PHP's max_input_vars.
* Both ways stage the adjustment: review, apply and rollback are unchanged. A box for a component the employee does not have is not offered.
