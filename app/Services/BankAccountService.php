<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** All bank-account business rules live here, so a future approval workflow has one place to hook into. */
class BankAccountService
{
    public static function normalise(string $number): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', $number));
    }

    public static function hash(int $bankId, string $normalised): string
    {
        return hash_hmac('sha256', $bankId.'|'.$normalised, config('app.key'));
    }

    public function add(Employee $employee, array $data, User $by): BankAccount
    {
        return DB::transaction(function () use ($employee, $data, $by) {
            // Lock the employee row so two simultaneous requests cannot exceed the limit
            Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $max = config('bank.max_accounts_per_employee');
            if (BankAccount::where('employee_id', $employee->id)->count() >= $max) {
                throw ValidationException::withMessages(['account_number' => "An employee can have at most {$max} bank accounts. Remove one first."]);
            }

            $number = self::normalise($data['account_number']);
            $hash = self::hash((int) $data['bank_id'], $number);

            if (BankAccount::where('account_number_hash', $hash)->where('employee_id', $employee->id)->exists()) {
                throw ValidationException::withMessages(['account_number' => 'This account is already added for this employee.']);
            }
            if (! config('bank.allow_shared_accounts') && BankAccount::where('account_number_hash', $hash)->where('employee_id', '!=', $employee->id)->exists()) {
                throw ValidationException::withMessages(['account_number' => 'This account number is already registered to another employee.']);
            }

            $hasActive = BankAccount::where('employee_id', $employee->id)->where('is_active', true)->exists();

            $account = new BankAccount;
            $account->forceFill([
                'employee_id' => $employee->id, 'bank_id' => $data['bank_id'], 'bank_branch_id' => $data['bank_branch_id'],
                'account_number' => $number, 'account_number_hash' => $hash, 'account_last4' => substr($number, -4),
                'account_holder_name' => trim($data['account_holder_name']),
                'is_active' => ! $hasActive, // the first account becomes active automatically
                'approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), // approval workflow: set 'pending' here later
                'added_by' => $by->id, 'added_by_name' => $by->name,
            ])->save();

            Audit::log('bank_account.added', $account, "Bank account ending {$account->account_last4} added", context: ['employee_id' => $employee->id, 'active' => $account->is_active]);

            return $account;
        });
    }

    public function activate(BankAccount $account): void
    {
        DB::transaction(function () use ($account) {
            Employee::whereKey($account->employee_id)->lockForUpdate()->firstOrFail();
            $account->refresh();

            if ($account->is_active) {
                return;
            }
            if ($account->approval_status !== 'approved') {
                throw new RuntimeException('Only approved accounts can be activated.');
            }

            // Deactivate the current one first (unique index allows a single active account per employee)
            BankAccount::where('employee_id', $account->employee_id)->where('is_active', true)->get()
                ->each(fn ($a) => $a->forceFill(['is_active' => false])->save());
            $account->forceFill(['is_active' => true])->save();

            Audit::log('bank_account.activated', $account, "Bank account ending {$account->account_last4} set as the active account", context: ['employee_id' => $account->employee_id]);
        });
    }

    /** @return bool whether the removed account was the active one */
    public function remove(BankAccount $account, User $by, ?string $reason): bool
    {
        return DB::transaction(function () use ($account, $by, $reason) {
            Employee::whereKey($account->employee_id)->lockForUpdate()->firstOrFail();
            $wasActive = $account->fresh()->is_active;

            $account->forceFill([
                'is_active' => false, 'removed_by' => $by->id, 'removed_by_name' => $by->name,
                'removal_reason' => $reason ? mb_substr(trim($reason), 0, 255) : null,
            ])->save();
            $account->delete(); // soft delete: history is kept for past payments

            Audit::log('bank_account.removed', $account, "Bank account ending {$account->account_last4} removed", context: ['employee_id' => $account->employee_id, 'was_active' => $wasActive, 'reason' => $reason]);

            return $wasActive;
        });
    }
}
