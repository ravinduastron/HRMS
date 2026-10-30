# Loan management

Needs the statutory package installed first (this package's AppServiceProvider loads routes/statutory.php too).

## Install
1. Enable the PHP **zip** and **xml** extensions (Excel files): in php.ini `extension=zip` (and `extension=xml` if it is off), then restart PHP and the queue worker.
   No new Composer package is needed: the Excel reader and writer are in app/Support/Xlsx.
2. Copy over the project. Overwrites: config/permissions.php, config/navigation.php, config/reencrypt.php, app/Providers/AppServiceProvider.php.
3. `php artisan migrate`
4. `php artisan db:seed --class=AccessControlSeeder`        (adds the loans.* and loan-types.manage permissions to Super Admin)
5. `php artisan db:seed --class=LoanComponentSeeder`        (creates the payslip lines LOAN_CAPITAL and LOAN_INTEREST in a group "Loan deductions")
6. `php artisan optimize:clear` and `php artisan route:clear`
7. Give roles the permissions. Create the loan types (Payroll > Loans & advances > Loan types) and pick LOAN_CAPITAL / LOAN_INTEREST on each.
8. Fair value needs a market rate: Loans & advances > Fair value > Market rates.
9. Tests: `php artisan test tests/Unit/Loans`

## How it works
* **Loan types**: code, interest method (Straight line / Declining balance / EMI), default rate (0 = interest free), longest term, largest amount,
  the early-settlement interest rule (full scheduled interest / accrued to the settlement date / none) and the two payslip lines.
  A loan copies the method and rule when created, so editing a type never changes existing loans.
* **Methods**: Straight line = flat interest on the amount lent, equal installments. Declining balance = equal capital, interest on the opening
  balance, falling installments. EMI = equal installments, interest on the balance. The last installment absorbs rounding, so capital adds up exactly.
  Amounts are rounded to config('loans.rounding_dp') places (2).
* **Balances** come from an immutable ledger (disbursement or opening balance, deductions, settlement, ignored interest, rate changes).
  Amounts (loan, installments, ledger, rate history, valuations, staged import rows) are encrypted at rest and listed in config/reencrypt.php.
* **Changing the rate of a running loan** (loan page): choose the month it applies from and the new rate; a preview shows the next installment,
  the interest still to come and the total still to repay, before and after. The unpaid installments from that month are re-priced on the
  outstanding balance with the same number of installments left. Earlier installments and deductions are untouched. Nothing is deleted: the
  replaced installments are kept as an earlier schedule version, and the rate history lists previous and new rate, installment before and after, who and why.
  A straight-line loan is re-priced as flat interest on the balance at that point. It cannot apply from a month that was already deducted.
* **Early settlement** (loan page > Settle early): a quote for a chosen date = outstanding principal + interest by the type's rule. "Ignore the interest"
  (needs loans.waive-interest and a reason) collects the principal only; the interest that would have been charged is recorded in the ledger and the audit log.
  The money is received in cash: record the date and receipt number. The loan closes and its remaining installments are marked settled.
  "Accrued" = interest of installments already due + the part of the next one earned up to the date (by days).
* **Imports**: Import from Excel > download the template > fill > upload. The file is checked row by row (employee in your schemes, loan type, duplicate
  reference, dates, amounts, outstanding <= original, installment cross-check as a warning). Nothing is created until you confirm; only valid rows are
  created, together. An upload can be reversed while none of its loans has a deduction, rate change or settlement. The same employee + reference
  is refused (also inside one file). An imported loan starts from the outstanding principal and the remaining installments.
  A straight-line import keeps the flat interest on the original amount. Excel may drop the leading zeros of an employee number: it is still matched
  when only one employee fits (with a warning). Keep the template's text-formatted columns.
* **Capital and interest apart**: the schedule, the loan page and Monthly deductions show them separately, and each loan type pays them through two
  different payslip lines (components). LoanService::dueForPeriod() and componentAmounts() give the pay run exactly that split per employee.
* **Monthly deductions** (Loans & advances > Monthly deductions): per month and scheme, capital + interest per loan, CSV export. Until the pay run does it,
  "Record as deducted" rolls the month's installments forward (typed confirmation). Installments of earlier months that were never deducted show as Overdue.
* **Fair value**: per loan, the present value of what is still to be collected (principal and interest), discounted at the market rate by the whole months to
  each due month; difference = fair value - outstanding principal (negative for loans cheaper than the market, as interest-free staff loans are).
  Run for any date and rate, export to CSV, or save as a snapshot (all schemes, needs schemes.all) that stays exactly as calculated.
  A loan created here also shows its fair value on the day it was paid out (its original schedule at that day's market rate).
  Market rates are versioned (effective date, reason). This is not an accounting amortisation schedule.

## Decisions built in
No extra (part) payments, no deduction cap, no tax on concessionary loans, settlement received in cash, recovery from leavers is for the pay run / final settlement.

## NOT in this package
* The payslip and paysheet themselves, and the pay run link. The pay run must: read dueForPeriod(), BLOCK the run when net pay cannot cover the
  installments (your rule), call recordDeduction() for each installment when the run locks, and flag employees who leave with an outstanding loan.
* Deferring an installment, part payments, an approval workflow, a Loans tab on the employee page (use Loans > filter by employee).
