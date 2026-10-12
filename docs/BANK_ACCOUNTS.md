# Bank accounts + Banks & branches master

## Install
1. Copy over the project. (Overwrites config/permissions.php, config/navigation.php, routes/web.php, Employee model,
   EmployeeController and employees/show.blade.php: they already contain the earlier modules.)
2. `php artisan migrate`   (needs MySQL 5.7+ / MariaDB 10.2+: uses a generated column to enforce "one active account")
3. `php artisan db:seed --class=AccessControlSeeder`
4. `php artisan optimize:clear`
5. Add banks and branches (Administration > Banks & branches), then open an employee > Bank accounts.
No bank data is seeded: bank/branch codes should come from your own bank list.

## Rules
* Max 2 accounts per employee (config/bank.php). Exactly one can be active; the first account added becomes active.
* No editing (blocked in the model too). Remove + add instead. Removal is a soft delete, so history is kept.
* Account numbers are encrypted at rest (APP_KEY!) and shown masked; "Show" needs bank-accounts.reveal and is audited.
  Never change APP_KEY without re-encrypting, or existing numbers become unreadable.
* The same account number cannot belong to two employees (config/bank.php allow_shared_accounts).
* A bank/branch used by any account (even a removed one) cannot be removed.

## Approval workflow later
employee_bank_accounts already has approval_status / approved_by / approved_at. Today BankAccountService::add() sets
'approved'. To add approval: set 'pending' there, add approve/reject actions + a bank-accounts.approve permission, and
payroll uses BankAccount::payable() (active AND approved) so pending accounts are never paid.
