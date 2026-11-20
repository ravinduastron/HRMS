# Cost centers

A tree of up to 3 levels (Division > Department > Section, names in config/costcenters.php), assigned to employees with an effective date.
Example: Manufacturing > Packing > (Office, Floor); Manufacturing > Production > (Office, Floor); Finance > Accounting.

## Install
1. Copy over the project. Overwrites nothing. New: config/costcenters.php, 1 migration, 2 models, app/Payroll/CostCenterTree.php,
   app/Services/CostCenterService.php, 2 controllers, routes/cost_centers.php, resources/views/payroll/cost-centers/*,
   resources/views/employees/tabs/_cost_center.blade.php, tests/Unit/Payroll/CostCenterTreeTest.php.
2. Manual edits (do them BEFORE running a MiHCM sync, because the migration renames the column):

### app/Services/Mihcm/EmployeeMapper.php
Replace   'cost_center'   => $this->str($e['costCenterName'] ?? null, 100),
with      'mihcm_costcenter' => $this->str($e['costCenterName'] ?? null, 100),

### app/Providers/AppServiceProvider.php   (in boot(), with the other route groups)
    Route::middleware(['web', 'auth', EnforceAccountPolicy::class])->group(base_path('routes/cost_centers.php'));

### config/permissions.php   (a new group, anywhere in the array)
    'Cost centers' => [
        'cost-centers.view'   => 'View the cost center tree and employee cost centers (of your schemes)',
        'cost-centers.manage' => 'Add, rename, activate and deactivate cost centers (also needs all-scheme access)',
        'cost-centers.assign' => 'Assign cost centers to employees (of your schemes)',
    ],

### config/navigation.php   (in the Payroll section items)
    ['label' => 'Cost centers', 'icon' => 'chart', 'children' => [
        ['label' => 'Cost center tree',     'url' => '/payroll/cost-centers', 'can' => 'cost-centers.view', 'live' => true],
        ['label' => 'Assign to employees',  'url' => '/payroll/cost-centers/assign', 'can' => 'cost-centers.assign', 'live' => true],
    ]],

### resources/views/employees/show.blade.php
a) in $tabs, after the 'statutory' line add
       'costcenter' => ['label' => 'Cost center', 'ready' => true, 'visible' => auth()->user()->can('cost-centers.view')],
b) after   @can('statutory.view')@include('employees.tabs._statutory')@endcan   add
       @can('cost-centers.view')@include('employees.tabs._cost_center')@endcan
c) in the $employment array replace   'Cost Center' => $employee->cost_center,
   with   'MiHCM cost center' => $employee->mihcm_costcenter,

3. `php artisan migrate`   (renames employees.cost_center to mihcm_costcenter; creates cost_centers and employee_cost_centers)
4. `php artisan db:seed --class=AccessControlSeeder`
5. `php artisan optimize:clear` and `php artisan route:clear`
6. Give roles the permissions. Payroll > Cost centers: build the tree, then assign.
7. Tests: `php artisan test tests/Unit/Payroll/CostCenterTreeTest.php`

## Rules
* **Tree**: level 1 to 3. Code is unique and cannot change; names are unique among siblings. Never deleted, only deactivated.
  A branch may stop at level 2 (Finance > Accounting). Changing the tree needs cost-centers.manage plus schemes.all (the tree is global).
* **Deactivate** is refused while the node has active sub-levels or employees whose latest assignment is on it. **Add a sub-level** is refused
  under a node that has employees (move them first), so employees are always on a leaf.
* **Assigning**: one cost center per employee, only a LEAF (no active sub-levels), active, employee active. Each change is a new immutable row with
  effective date, reason, who, and a snapshot of the full path. Several can share a date: the last saved is in force. An employee already on that
  cost center on that date is left alone (counted as unchanged).
* **Bulk screen** (Payroll > Cost centers > Assign): filters (name, scheme, department, MiHCM cost center, no cost center yet, payroll), tick employees or
  "apply to all matching the filters" (up to 5000), pick the cost center, date and reason. All or nothing.
* **Employee page > Cost center tab**: today's cost center with its path, the MiHCM value for reference, full history, and a form to change it.
* **MiHCM value** is stored in employees.mihcm_costcenter, read-only; the sync never touches assignments.
* Users see and assign only employees of their schemes (out-of-scope is not found). Employee counts on the tree are limited the same way.
* Audit: tree changes (model audit), single assignments (one entry per employee), bulk (one summary entry with the employee ids, up to 200).

## For the pay run (not built yet)
Use the PERIOD END date, like the salary lookup.
1. **Block**: call CostCenterService::assertAllAssigned($employeeIds, $period->period_end) when the run is created and again before approval.
   It throws a ValidationException naming the employees without a cost center. CostCenterService::missing() gives the list for a screen.
2. **Snapshot**: the same call returns employee id => snapshot, and CostCenterService::snapshots() gives it without blocking. Store on each employee
   line of the run: cost_center_id, path, and per level the id, code and name (cc1_*, cc2_*, cc3_*; level 3 is NULL for a 2-level branch).
   Later renames, moves or deactivations never change a finished run.
3. **Report per run**: group the run's lines by cc1, then cc2, then cc3 from the stored columns (NULL levels shown as "-"); totals roll up.
   Do not join to cost_centers for finished runs.
