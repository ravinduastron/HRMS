# Loan payslip layout from pay component groups

Capital and interest are ALWAYS two separate pay items. How the payslip prints them is decided by the pay component group of the two items;
the paysheet can show them component-wise or group-wise.

## Install
This package replaces the "display settings" idea of loan-dividend-variable (payslip_display) and loan-paysheet-payslip (never use that one).
Everything else in loan-dividend-variable (dividend tax table, Variable amount method) stays.

1. If you have NOT yet run `php artisan migrate` for loan-dividend-variable: delete its file
   database/migrations/2027_10_01_000000_add_payslip_display_to_loan_types.php (keep 2027_10_01_000100_add_dividend_tax_table.php).
   If you already migrated it, leave it: the new migration below drops the column again.
2. Copy this package over the project. Overwrites: app/Models/LoanType.php, app/Http/Controllers/{LoanTypeController,LoanDeductionController}.php,
   resources/views/loans/types/{form,index}.blade.php, resources/views/loans/deductions.blade.php, database/seeders/LoanComponentSeeder.php.
   New: database/migrations/2027_10_03_000000_drop_loan_type_display_columns.php
3. LoanService.php: do NOT make the dueForPeriod() edit from loan-dividend-variable (the "single" trick).
   If you already did, put the original back:
       'capital_component_id' => $i->loan->type->capital_component_id, 'interest_component_id' => $i->loan->type->interest_component_id,
4. `php artisan migrate`, then `php artisan optimize:clear`. No new permission.
5. Set up the group: Payroll > Component groups. Put LOAN_CAPITAL and LOAN_INTEREST in one group (the seeder's "Loan deductions", or a group you
   name "Loan Installment") and tick "Show as one line on the payslip". An EXISTING group is not changed by the seeder: tick it yourself.

## How it works
* Loan type: pick the capital item and the interest item (different deductions). A hint under the pickers says what the payslip will show:
  both items in the same group that is shown as one line = ONE line named after the group; otherwise two lines.
  Loan types > list shows the same in the "Payslip shows" column.
* Payslip (when it is built): it prints each pay item's group as the payslip already does for any component: a combined group is ONE summed
  line (group name, amount only), an uncombined group is one line per item. Nothing loan-specific.
* Paysheet: the pay run stores one amount per pay item, so component-wise is those amounts and group-wise is the same amounts summed by group.
  You can see exactly that today: Loans & advances > Monthly deductions has three views: By loan (capital and interest per loan, as before),
  By pay item (one column per item, e.g. LOAN_CAPITAL | LOAN_INTEREST) and By group (one column per group, e.g. Loan deductions). All three export to CSV.
* The layout belongs to the GROUP, not the loan type: two loan types that use the same two items share the same payslip line, and a group sums
  everything in it. For a separate payslip line per loan type, give that type its own pair of items in its own group.
* Changing a group changes payslips calculated from then on. Finished pay runs keep their own snapshot of the grouping.
