# Company settings, paying bank accounts, employee rate tab

## Install
1. Copy over the project. Overwrites: app/Providers/AppServiceProvider.php, config/permissions.php, config/navigation.php, config/reencrypt.php,
   app/Http/Controllers/EmployeeContributionRateController.php (they already contain the earlier modules).
   New: 1 migration, 2 models, app/Services/CompanyBankAccountService.php, 2 controllers, routes/company.php, resources/views/company/index.blade.php,
   resources/views/employees/tabs/_statutory.blade.php.
2. Two manual edits (the files are large):

### resources/views/employees/show.blade.php
a) in the $tabs array, after the 'hirepurchases' line, add
       'statutory'  => ['label' => 'Statutory', 'ready' => true, 'visible' => auth()->user()->can('statutory.view')],
b) after the line   @can('hire-purchases.view')@include('employees.tabs._hire_purchases')@endcan   add
       @can('statutory.view')@include('employees.tabs._statutory')@endcan

### app/Http/Controllers/BankMasterController.php  (a bank or branch used by a company paying account cannot be removed)
In destroyBank(), as the first statement, add
       if (\App\Models\CompanyBankAccount::withTrashed()->where('bank_id', $bank->id)->exists()) {
           return back()->with('error', "{$bank->name} is used by company paying accounts, so it cannot be removed.");
       }
In destroyBranch(), after the abort_unless(...) line, add
       if (\App\Models\CompanyBankAccount::withTrashed()->where('bank_branch_id', $branch->id)->exists()) {
           return back()->with('error', "{$branch->name} is used by company paying accounts, so it cannot be removed.");
       }
(The database also refuses these deletes; the edit just gives a readable message.)

3. `php artisan migrate`
4. `php artisan db:seed --class=AccessControlSeeder`   (adds the company.* and company-bank-accounts.* permissions to Super Admin)
5. `php artisan optimize:clear` and `php artisan route:clear`
6. Give roles the permissions. Administration > Company: fill in the details and add the paying accounts.

## Company details
One legal entity, edited in place (not versioned). Every change is audited with old and new values. Fields: legal name, trading name, registration no,
TIN, address, phone, email, EPF employer no, ETF employer no. Until the first save, the legal name shows the PAYROLL_COMPANY_NAME placeholder.
Viewing needs company.view; changing needs company.manage plus schemes.all (the company is global).

## Paying bank accounts
* Several accounts, any number active at once, exactly ONE default (the database enforces it). The first account added becomes the default.
* Each: nickname, bank and branch from Banks & branches, account number (encrypted, masked, "Show" needs company-bank-accounts.reveal and is audited),
  holder name. No editing: remove and add. Duplicates are refused (blind-index hash).
* Make default (active accounts only). The default cannot be deactivated or removed while others exist: make another the default first.
* Deactivate: the account can no longer be chosen for payment. Remove: soft delete. An account a pay run has paid from cannot be removed
  (CompanyBankAccount::isUsed() checks pay_runs.company_bank_account_id once that column exists), only deactivated.
* Add, default, activate, deactivate and remove need company-bank-accounts.manage plus schemes.all.
* Account numbers are in config/reencrypt.php (with a hash rebuild) for APP_KEY rotation.

## For the pay run (not built yet)
* CompanyBankAccountService::payable() lists the active accounts, default first; default() gives the preselected one.
* When the run reaches "paid", the user picks one. The run must store company_bank_account_id plus a snapshot of the bank name, branch and last 4 digits,
  so later changes never rewrite history. The same choice feeds the bank transfer file.
* Bank-specific file settings (originator / company code per bank) belong to the bank file package and are not here.

## Employee page > Statutory tab (needs statutory.view)
Shows the employee contribution rate in use and its source (own rate or the EPF/MSPS default), the fund default, the employer rate and ETF (read-only),
the full history, and (with statutory.employee-rates) a form to set the rate: rate, effective date, reason; empty rate = back to the default.
It uses StatutoryService::setEmployeeRate(), so rules, history and audit are the same as on Payroll > Statutory > Employee rates, which stays.
