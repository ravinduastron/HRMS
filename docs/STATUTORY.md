# Statutory module: APIT tax tables, EPF / MSPS / ETF rates, employee contribution rates

## Install
1. Copy over the project. Overwrites: config/permissions.php, config/navigation.php (it contains the menu clean-up from the
   previous package plus the new Statutory group) and app/Providers/AppServiceProvider.php (loads routes/statutory.php).
   New: config/statutory.php, routes/statutory.php, 5 models, app/Services/StatutoryService.php, app/Payroll/Statutory/*, 3 controllers,
   resources/views/statutory/*, 2 migrations, database/seeders/StatutoryTaxTableSeeder.php, tests/Unit/Payroll/Statutory/*.
2. Make three small edits (see "Manual edits" below) so components get the new tax settings.
3. `php artisan migrate`
4. `php artisan db:seed --class=AccessControlSeeder`        (adds the statutory.* permissions to Super Admin)
5. `php artisan db:seed --class=StatutoryTaxTableSeeder`    (loads the IRD 2025/26 Table 1 and Table 2; safe to re-run)
6. `php artisan optimize:clear` and `php artisan route:clear`
7. Give roles the permissions. Open Payroll > Statutory > Contribution rates and enter the EPF, MSPS and ETF rates (nothing is preset).
8. Tests: `php artisan test tests/Unit/Payroll/Statutory`

## What is in it
* **Tax tables** (Payroll > Statutory > Tax tables): the monthly table (IRD Table 1) and the lump-sum table (IRD Table 2). Each has versions
  with an effective date; each version has bands "over / up to / rate / less (or fixed amount)", entered as the IRD publishes them.
  A version is never edited: the next tax year, or any correction, is a new version with a reason. The version used is the one in force in the pay month.
  Saving checks that the bands are contiguous and the last one is open. It WARNS (but still saves) when neighbouring bands jump by more than
  config('statutory.continuity_tolerance'), and when the lump-sum table is not the "12 x monthly" version of the monthly one (same effective date).
  "Try it" panels calculate any income or lump sum with the version in force in a chosen month. Nothing is saved.
* **Contribution rates**: EPF employee/employer, MSPS employee/employer, ETF employer, versioned. The employee rates are DEFAULTS for the fund.
  ETF is paid for everyone, MSPS members included. An employee marked "No EPF / MSPS / ETF" on their salary pays none.
* **Employee rates**: an individual employee can have their own employee contribution rate (any date, reason kept, history kept,
  empty = back to the default of their fund). Only the employee's contribution can differ; the employer rate and ETF do not.
* **Rounding**: every statutory amount is rounded to config('statutory.rounding_dp') decimals (2) using rounding_mode (half_up). Change it in config/statutory.php.
* **Tax treatment per component** (new, on each component version): Regular (monthly table) or Lump sum (lump-sum table), plus a
  "Taxable percentage" (100 = the whole amount is taxable; 50 = half of it). Components already "paid separately" and taxable were set to Lump sum
  by the migration: check them. A component counts only if "Taxable income" is ticked.
* Permissions: statutory.view; statutory.manage (add versions; also needs schemes.all, because tax tables and default rates are global);
  statutory.employee-rates (set an employee's own rate; limited to employees of the user's schemes).

## Rules the calculators follow (app/Payroll/Statutory, covered by tests)
* Band: above the previous limit, up to and including its own. Tax = amount x rate% - fixed amount, never below zero.
* Lump sum: D = A + B + C; tax = D x rate% - (fixed amount + monthly tax deducted on A + monthly tax deductible on B + lump-sum tax deducted earlier);
  a result below zero is nil. All four worked examples of the IRD document are unit tests (36,000 / 133,200 / 168,000 / 246,000), plus the monthly examples (1,200 / 18,500).
* Tax base: taxable portion = amount x taxable percentage; deductions flagged taxable (for example no-pay) reduce their bucket; each bucket never goes below zero.
* Tax year = April to March (Y/A 2026/27 now). The 2025/26 tables loaded by the seeder stay in force until a later version is added:
  CHECK whether the IRD issued different tables for 2026/27 and add them as a new version.

## NOT in this package (belongs to the pay run)
* Using these in a pay run: building the EPF / MSPS / ETF / tax bases from components, working out A and B for the lump-sum formula from earlier
  runs, and opening balances for a mid-year go-live.
* IRD Table 5 (joiners and leavers during the year) and Table 3 (once-and-for-all payments): not provided yet, so the pay run will flag those
  employees for a manual amount.
* The employee's own rate is on its own screen (Payroll > Statutory > Employee rates). It is not on the employee page yet.

## Manual edits (components)
### app/Http/Controllers/PayComponentController.php
1. In versionRules(), after the line
       'pay_cycle_rule' => ['required', Rule::in(['every_period', 'first_period', 'last_period'])],
   add
       'tax_treatment' => ['nullable', Rule::in(['regular', 'lump_sum'])],
       'taxable_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
2. In versionData(), after the line
       'proration' => $d['proration'], 'payment_timing' => $d['payment_timing'], 'pay_cycle_rule' => $d['pay_cycle_rule'],
   add
       'tax_treatment' => $d['tax_treatment'] ?? 'regular', 'taxable_percentage' => $d['taxable_percentage'] ?? 100,
3. In defaults(), after
       'proration' => 'none', 'payment_timing' => 'regular', 'pay_cycle_rule' => 'every_period', 'change_note' => '',
   add
       'tax_treatment' => 'regular', 'taxable_percentage' => 100,
4. In createVersion(), in the list inside $latest->only([...]), after 'pay_cycle_rule', add 'tax_treatment', 'taxable_percentage',

### resources/views/payroll/components/_form.blade.php
Just BEFORE the line   <div class="mt-5 grid gap-5 sm:grid-cols-3">   (the Proration / Paid / Applies in row) add:

    <div class="mt-5 grid gap-5 sm:grid-cols-2">
        <div class="space-y-1.5">
            <label for="tax_treatment" class="{{ $label }}">Tax treatment (when taxable)</label>
            <select id="tax_treatment" name="tax_treatment" class="{{ $field }}">
                <option value="regular" @selected(old('tax_treatment', $v['tax_treatment'] ?? 'regular') === 'regular')>Regular pay (monthly tax table)</option>
                <option value="lump_sum" @selected(old('tax_treatment', $v['tax_treatment'] ?? 'regular') === 'lump_sum')>Lump sum, such as a bonus or incentive (lump-sum tax table)</option>
            </select>
        </div>
        <x-input name="taxable_percentage" type="number" step="0.01" min="0" max="100" label="Taxable percentage" :value="old('taxable_percentage', $v['taxable_percentage'] ?? 100)"
            hint="The share of the amount that is taxable. 100 = all of it, 50 = half." />
    </div>

### resources/views/payroll/components/show.blade.php
Right AFTER the Proration block (the <div> that contains   <dt class="text-slate-500">Proration</dt>   ...  </div>) add:

    <div>
        <dt class="text-slate-500">Tax treatment</dt>
        <dd class="mt-0.5 font-medium text-slate-900">{{ $cur->tax_applicable ? ($cur->tax_treatment === 'lump_sum' ? 'Lump sum' : 'Regular') : 'Not taxable' }}
            @if ($cur->tax_applicable && (float) $cur->taxable_percentage < 100) · {{ rtrim(rtrim((string) $cur->taxable_percentage, '0'), '.') }}% taxable @endif</dd>
    </div>

Until these edits are made the new settings keep their defaults (Regular, 100%) and nothing else is affected.
