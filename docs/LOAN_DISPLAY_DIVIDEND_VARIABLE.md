# Loan payslip display, dividend tax table, "Variable amount" method

## Install
1. Copy over the project. Overwrites: app/Http/Controllers/{LoanTypeController,TaxTableController}.php, app/Models/PayComponentVersion.php,
   app/Payroll/Statutory/TaxBase.php, resources/views/loans/types/{form,index}.blade.php,
   resources/views/statutory/tax-tables/{index,version}.blade.php.
   New: 2 migrations, app/Payroll/Statutory/DividendTax.php, resources/views/statutory/tax-tables/_try_dividend.blade.php, tests/Unit/Payroll/Statutory/DividendTaxTest.php.
2. Make the manual edits below (these files are large).
3. `php artisan migrate`   (loan_types.payslip_display, default "separate"; adds the Dividend tax table, empty)
4. `php artisan optimize:clear` and `php artisan route:clear`. No new permissions.
5. Tests: `php artisan test tests/Unit/Payroll/Statutory`

## Manual edits

### app/Services/LoanService.php  (single-line payslip)
In dueForPeriod(), replace
    'capital_component_id' => $i->loan->type->capital_component_id, 'interest_component_id' => $i->loan->type->interest_component_id,
with
    'capital_component_id' => $i->loan->type->capital_component_id,
    'interest_component_id' => $i->loan->type->payslip_display === 'single' ? $i->loan->type->capital_component_id : $i->loan->type->interest_component_id, // single line: both go to the one pay item
(componentAmounts() then adds capital + interest onto that one component. Nothing else changes.)

### app/Services/StatutoryService.php  (dividend table has no "annual = 12 x monthly" cross-check)
At the top of warningsFor(), as its first statement, add:
    if ($table->kind === 'flat') {
        return [];
    }

### app/Payroll/ComponentCalculator.php  (variable is an amount supplied from outside, like fixed/input/system)
Replace
    public const LEAF_METHODS = ['fixed', 'input', 'system'];
with
    public const LEAF_METHODS = ['fixed', 'variable', 'input', 'system'];

### app/Http/Controllers/PayComponentController.php  (dividend tax treatment)
In versionRules() replace
    'tax_treatment' => ['nullable', Rule::in(['regular', 'lump_sum'])],
with
    'tax_treatment' => ['nullable', Rule::in(['regular', 'lump_sum', 'dividend'])],

### resources/views/payroll/components/_form.blade.php
1. In the tax_treatment <select>, after the lump_sum <option>, add:
       <option value="dividend" @selected(old('tax_treatment', $v['tax_treatment'] ?? 'regular' )==='dividend' )>Dividend (final withholding tax, flat dividend rate)</option>
2. After the line   <p x-show="method === 'system'" ...>...</p>   add:
       <p x-show="method === 'variable'" x-cloak class="text-sm text-slate-500 sm:col-span-2">No default amount here. The amount is entered on the employee's salary, or in a pay run. The rate basis (monthly, daily, hourly) can be set on the salary structure and the employee.</p>

### resources/views/payroll/components/show.blade.php
1. Replace the three lines
       {{ $cur->calc_method === 'input' 
                            ? 'Entered each pay period' 
                            : 'Supplied by another module' }}
   with
       {{ ['input' => 'Entered each pay period', 'variable' => 'Entered per employee or in a pay run'][$cur->calc_method] ?? 'Supplied by another module' }}
2. Replace   {{ $cur->tax_applicable ? ($cur->tax_treatment === 'lump_sum' ? 'Lump sum' : 'Regular') : 'Not taxable' }}
   with        {{ $cur->tax_applicable ? ['lump_sum' => 'Lump sum', 'dividend' => 'Dividend (final tax)'][$cur->tax_treatment] ?? 'Regular' : 'Not taxable' }}

### resources/views/payroll/components/test.blade.php
Replace   ['fixed' => 'fixed', 'input' => 'entered each period', 'system' => 'from another module']
with      ['fixed' => 'fixed', 'variable' => 'variable amount', 'input' => 'entered each period', 'system' => 'from another module']

### app/Services/EmployeeSalaryService.php  ("isFixed" now means: carries an amount and rate basis = fixed OR variable)
1. Replace isFixed() with
       public function isFixed(PayComponent $c): bool
       {
           return in_array($c->latestVersion()?->calc_method, ['fixed', 'variable'], true);
       }

       public function isVariable(PayComponent $c): bool
       {
           return $c->latestVersion()?->calc_method === 'variable';
       }
2. In amountFor(), replace
           if ($amount === null || trim($amount) === '') {
               throw ValidationException::withMessages(['amount' => "Enter an amount for {$component->code}."]);
           }
   with
           if ($amount === null || trim($amount) === '') {
               if ($this->isVariable($component)) {
                   return null; // a variable amount may stay empty: it is entered in the pay run
               }
               throw ValidationException::withMessages(['amount' => "Enter an amount for {$component->code}."]);
           }
3. In assign(), replace
           $amount = null;
           if ($this->isFixed($c)) {
   with
           $amount = null;
           if ($this->isFixed($c) && ! $this->isVariable($c)) { // a structure never carries an amount for a variable component
(Everything else, for example the rate basis of a variable line, works as for fixed.)

### app/Http/Controllers/EmployeeSalaryController.php  (a variable amount can be cleared)
In updateAmount() replace
           $data = $request->validate($this->common() + ['amount' => ['required', ...self::AMOUNT], 'rate_basis' => self::BASIS]);
   with
           $data = $request->validate($this->common() + ['amount' => [$this->salary->isVariable($component) ? 'nullable' : 'required', ...self::AMOUNT], 'rate_basis' => self::BASIS]);
   and replace   $data['amount'], $data['rate_basis'] ?? null,   (in the setAmount call)   with   $data['amount'] ?? null, $data['rate_basis'] ?? null,

### app/Http/Controllers/SalaryStructureController.php  (no default amount on a structure for variable components)
1. In saveLines() replace
           $amount = $fixed && ($l['default_amount'] ?? '') !== '' ?
   with
           $amount = $fixed && ! $this->salary->isVariable($c) && ($l['default_amount'] ?? '') !== '' ?
2. In formData(), after   'fixed' => $this->salary->isFixed($c), 'inactive' => ! $c->is_active,   add   'variable' => $this->salary->isVariable($c),
   and make 'default' null for variable: replace   'default' => $c->latestVersion()->default_amount !== null ?
   with   'default' => ! $this->salary->isVariable($c) && $c->latestVersion()->default_amount !== null ?

### resources/views/salary/structures/_form.blade.php
Replace the amount input
       <input type="number" step="0.01" min="0" :name="'lines[' + i + '][default_amount]'" x-model="l.amount"
           placeholder="Set per employee" class="{{ $field }} max-w-50 font-mono">
with
       <input type="number" step="0.01" min="0" :name="'lines[' + i + '][default_amount]'" x-model="l.amount" x-show="!info(l.id).variable" :disabled="info(l.id).variable"
           placeholder="Set per employee" class="{{ $field }} max-w-50 font-mono">
       <span x-show="info(l.id).variable" x-cloak class="text-xs text-slate-500">Amount entered per employee or in a pay run</span>

### resources/views/employees/tabs/_salary.blade.php
1. Replace the line
       $isFixed = fn ($l) => $l->component?->latestVersion()?->calc_method === 'fixed';
   with
       $isFixed = fn ($l) => in_array($l->component?->latestVersion()?->calc_method, ['fixed', 'variable'], true);
       $isVariable = fn ($l) => $l->component?->latestVersion()?->calc_method === 'variable';
2. Replace   <x-badge color="amber">Amount needed</x-badge>   (in the amount column; the one right after   @if ($l->amount === null)   in the table)
   with        @if ($isVariable($l))<span class="text-xs text-slate-500">Entered in the pay run</span>@else<x-badge color="amber">Amount needed</x-badge>@endif
3. In the "Set amounts" popup replace   @if ($l->amount === null) <span class="text-xs text-amber-700">· amount needed</span>@endif
   with                                  @if ($l->amount === null && ! $isVariable($l)) <span class="text-xs text-amber-700">· amount needed</span>@endif
4. In the "Change amount" popup replace the input   <input type="text" name="amount" inputmode="decimal" required autocomplete="off" class="{{ $input }} font-mono">
   with   <input type="text" name="amount" inputmode="decimal" @required(! $isVariable($l)) autocomplete="off" placeholder="{{ $isVariable($l) ? 'Empty = entered in the pay run' : '' }}" class="{{ $input }} font-mono">

## How it behaves

### Loan type: payslip display
* "The payslip and paysheet show the loan as": Separate lines (two different pay items, as before) or One line (one pay item).
  One line: only one picker, "Installment is deducted as"; the type stores it as its capital item and the interest item stays empty.
  Existing types stay on Separate. A change applies to payslips from now on (loans and past deductions are not touched).
* In single mode the pay run asks LoanService::componentAmounts() and gets capital + interest on that one item. The loan page, schedule,
  Monthly deductions and CSV still show capital and interest apart (those are loan reports).

### Dividend tax table (Payroll > Statutory > Tax tables)
* A third table, "Dividend tax (final withholding)": versions by effective date, label, source, reason and audit like the others,
  but a version is just one flat rate (stored as one band from 0, no limit, nothing deducted). NO rate is preloaded: add the first version
  and confirm the current rate with the IRD. A "Try a dividend" box shows tax withheld and the amount paid out.
* Component tax treatment now has a third choice, "Dividend (final withholding tax)". The component must still be ticked "Taxable income" to count.
  TaxBase::split() leaves dividend lines OUT of the regular and lump-sum bases (so APIT Table 1 / Table 2 and the lump-sum A, B, C figures never
  include them); TaxBase::dividend() totals them and DividendTax::tax() applies the flat rate. The pay run will use these.
* The taxable percentage of a component still applies before the rate.

### Variable amount method
* New method on a pay component: no default amount on the component; a structure may list it but carries no amount (only the rate basis);
  the amount is entered on the employee's Salary tab (monthly / daily / hourly, encrypted like fixed amounts) or in a pay run.
* The employee amount is optional. Empty shows "Entered in the pay run"; a set amount is the starting value, and a pay run can override it for that run only.
  "Change amount" with an empty box clears it. The pay run is not built yet: it must read the employee line and let the run override it.
* In formulas a variable component is a normal input: with no value it counts as 0, and the test panel lists it as an amount to enter.
* This is different from "Entered each period", which stores nothing on the employee.
