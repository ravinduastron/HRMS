<?php

namespace App\Services;

use App\Models\CompanyBankAccount;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** All paying-account rules live here. A pay run uses payable() to offer the accounts and default() to preselect one. */
class CompanyBankAccountService
{
    public static function hash(int $bankId, string $normalised): string
    {
        return hash_hmac('sha256', 'company|'.$bankId.'|'.$normalised, config('app.key'));
    }

    /** Recompute every blind-index hash with the CURRENT app key (used after APP_KEY rotation). */
    public static function rehashAll(): int
    {
        $n = 0;
        CompanyBankAccount::withTrashed()->chunkById(200, function ($accounts) use (&$n) {
            foreach ($accounts as $a) {
                // query builder on purpose: the model forbids changing locked columns
                DB::table('company_bank_accounts')->where('id', $a->id)
                    ->update(['account_number_hash' => self::hash($a->bank_id, BankAccountService::normalise($a->account_number))]);
                $n++;
            }
        });

        return $n;
    }

    /** Active accounts, default first: what the pay run offers when choosing where to pay from. */
    public function payable(): Collection
    {
        return CompanyBankAccount::with(['bank', 'branch'])->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function default(): ?CompanyBankAccount
    {
        return CompanyBankAccount::where('is_default', true)->where('is_active', true)->first();
    }

    public function add(array $data, User $by): CompanyBankAccount
    {
        return DB::transaction(function () use ($data, $by) {
            $number = BankAccountService::normalise($data['account_number']);
            $hash = self::hash((int) $data['bank_id'], $number);

            if (CompanyBankAccount::where('account_number_hash', $hash)->exists()) {
                throw ValidationException::withMessages(['account_number' => 'This account is already added.']);
            }

            $first = ! CompanyBankAccount::exists(); // the first account becomes the default automatically

            $account = new CompanyBankAccount;
            $account->forceFill([
                'name' => trim($data['name']), 'bank_id' => $data['bank_id'], 'bank_branch_id' => $data['bank_branch_id'],
                'account_number' => $number, 'account_number_hash' => $hash, 'account_last4' => substr($number, -4),
                'account_holder_name' => trim($data['account_holder_name']),
                'is_active' => true, 'is_default' => $first,
                'added_by' => $by->id, 'added_by_name' => $by->name,
            ])->save();

            Audit::log('company_bank_account.added', $account, "Paying account {$account->name} (ending {$account->account_last4}) added", context: ['default' => $first]);

            return $account;
        });
    }

    public function makeDefault(CompanyBankAccount $account): void
    {
        DB::transaction(function () use ($account) {
            CompanyBankAccount::query()->lockForUpdate()->pluck('id');
            $account->refresh();

            if ($account->is_default) {
                return;
            }
            if (! $account->is_active) {
                throw new RuntimeException('Only an active account can be the default. Activate it first.');
            }

            CompanyBankAccount::where('is_default', true)->get()->each(fn ($a) => $a->forceFill(['is_default' => false])->save());
            $account->forceFill(['is_default' => true])->save();

            Audit::log('company_bank_account.default_changed', $account, "{$account->name} is now the default paying account");
        });
    }

    public function activate(CompanyBankAccount $account): void
    {
        if ($account->is_active) {
            return;
        }
        $account->forceFill(['is_active' => true])->save();
        Audit::log('company_bank_account.activated', $account, "Paying account {$account->name} activated");
    }

    public function deactivate(CompanyBankAccount $account): void
    {
        if ($account->is_default) {
            throw new RuntimeException('This is the default account. Make another account the default first.');
        }
        if (! $account->is_active) {
            return;
        }
        $account->forceFill(['is_active' => false])->save();
        Audit::log('company_bank_account.deactivated', $account, "Paying account {$account->name} deactivated");
    }

    public function remove(CompanyBankAccount $account, User $by, ?string $reason): void
    {
        DB::transaction(function () use ($account, $by, $reason) {
            CompanyBankAccount::query()->lockForUpdate()->pluck('id');
            $account->refresh();

            if ($account->isUsed()) {
                throw new RuntimeException('A pay run has paid from this account, so it cannot be removed. Deactivate it instead.');
            }
            if ($account->is_default && CompanyBankAccount::where('id', '!=', $account->id)->exists()) {
                throw new RuntimeException('This is the default account. Make another account the default first.');
            }

            $account->forceFill([
                'is_active' => false, 'is_default' => false, 'removed_by' => $by->id, 'removed_by_name' => $by->name,
                'removal_reason' => $reason ? mb_substr(trim($reason), 0, 255) : null,
            ])->save();
            $account->delete(); // soft delete: the record is kept

            Audit::log('company_bank_account.removed', $account, "Paying account {$account->name} (ending {$account->account_last4}) removed", context: ['reason' => $reason]);
        });
    }
}
