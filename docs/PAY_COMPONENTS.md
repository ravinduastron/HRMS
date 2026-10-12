# Pay components + formula engine (step 1)

## Install
1. Make sure PHP's **bcmath** extension is enabled (`php -m | findstr bcmath`; in php.ini: `extension=bcmath`).
2. Copy over the project. (Overwrites config/permissions.php, config/navigation.php and routes/web.php: they already contain the earlier modules.)
3. `php artisan migrate`
4. `php artisan db:seed --class=AccessControlSeeder`   (adds the pay-components.* permissions to Super Admin)
5. `php artisan optimize:clear`
6. Optional starter data: `php artisan db:seed --class=PayComponentSampleSeeder` (BASIC, TRANSPORT, OT_NORMAL, OT_DOUBLE, INCENTIVE, NO_PAY, LOAN; no statutory rates)
7. Payroll > Compensation > Pay components.

## Permissions
* pay-components.view      : list, details, test panel
* pay-components.manage    : create components, add versions, edit details, activate/deactivate (fixed / input / system types)
* pay-components.formulas  : needed to create or change formula and percentage components (also to change a component that is already formula-based)

## Rules
* Component code: letters/digits/underscore, unique, cannot be changed, cannot be a reserved word or system variable.
* Calculation versions are immutable. A change = a new version with a later effective date, a reason, and an audit entry.
* A formula is validated on save: syntax, unknown variables, inactive components, and circular references (the loop is named).
* A component that other active components use cannot be deactivated.
* All maths is exact decimal (bcmath); each component rounds its own result (nearest / up / down, 0-6 decimals) and later
  components use the rounded amount, as on the payslip.

## Formula language
Operators + - * / ( )  10% (= /100)  = <> < <= > >=  AND OR NOT. Functions: IF MIN MAX ROUND ROUNDUP ROUNDDOWN FLOOR CEIL ABS MOD.
Names are case-insensitive. Variables: component codes and the system variables listed in app/Payroll/SystemVariables.php.
Formulas are parsed by our own parser and evaluated in a sandbox: they can only read the variables you give them.

## Tests (engine)
tests/Unit/Payroll: DecimalTest, FormulaTest, ComponentCalculatorTest. Run with `php artisan test tests/Unit/Payroll`.
