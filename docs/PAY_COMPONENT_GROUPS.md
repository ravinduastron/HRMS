# Pay component groups, MSPS base and fund type

## Install
1. Copy over the project. Overwrites: config/permissions.php, config/navigation.php, routes/web.php, PayComponent and
   PayComponentVersion models, PayComponentController, EmployeeMapper, PayComponentSampleSeeder, and the views
   payroll/components/{_form,show,index}.blade.php and employees/show.blade.php.
2. `php artisan migrate`
   * creates `pay_component_groups` with 8 starter groups and gives every existing component a group
     (by type; BASIC -> Basic, TRANSPORT -> Allowances, OT_NORMAL / OT_DOUBLE -> Overtime)
   * adds `msps_applicable` to component versions
   * renames `employees.msps_no` to `fund_no` (it always held MiHCM's epfNo) and adds `employees.provident_fund` (default `epf`)
3. `php artisan db:seed --class=AccessControlSeeder`   (adds pay-component-groups.view / .manage and employees.fund to Super Admin)
4. `php artisan optimize:clear`
5. Give the new permissions to the roles that need them. Every employee starts as EPF: set MSPS members on their
   employee page ("Switch to MSPS", needs employees.fund).

## Rules
* Every component has exactly one group of the same type. Required when creating; changeable on the component page (not versioned).
* A group "shown as one line" prints the SUM of its components, amount only. The payslip step will snapshot the group
  at calculation time, so later changes never rewrite old payslips.
* A group with active components cannot be deactivated. The type of a group that has components cannot change.
* EPF and MSPS are separate flags on each component version. Each employee is calculated on the base of their own fund.
* The MiHCM sync never writes `provident_fund`.
