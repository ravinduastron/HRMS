# Menu clean-up, real dashboard, rate basis on salary structures

## Install
1. Copy over the project. Overwrites: config/navigation.php, resources/views/partials/topbar.blade.php,
   resources/views/components/stat-card.blade.php, app/Http/Controllers/HomeController.php, resources/views/home.blade.php,
   app/Http/Controllers/SalaryStructureController.php, resources/views/salary/structures/{_form,show}.blade.php.
2. Two small manual edits (these files are large, so they are not in the zip):
   * app/Services/EmployeeSalaryService.php, in assign(), replace
         $lines[$c->id] = ['amount' => $amount, 'source' => 'structure', 'basis' => 'monthly']; // change the basis per employee afterwards
     with
         $lines[$c->id] = ['amount' => $amount, 'source' => 'structure', 'basis' => $this->isFixed($c) ? ($l->rate_basis ?: 'monthly') : 'monthly']; // the structure's rate basis is copied
   * resources/views/employees/tabs/_salary.blade.php, replace "and rate bases go back to monthly." with "and rate bases are copied from the structure."
3. `php artisan migrate`   (adds salary_structure_lines.rate_basis, existing lines = monthly)
4. `php artisan optimize:clear` (and `php artisan route:clear`)

## Notes
* Menu: only built screens are listed. Add an item to config/navigation.php when a feature is built (placeholder routes are still generated for items without 'live' => true).
* The top bar no longer has the notification bell or the settings gear.
* Dashboard shows real counts for the user's schemes; the all-scheme figures (no scheme, schemes without pay settings, last sync, last load) only for all-scheme users.
