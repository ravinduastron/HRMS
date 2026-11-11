<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * An account the company pays salaries from. Immutable once created (remove and add instead); only is_active, is_default and removal can change.
 * `account_number` is encrypted at rest (and hidden from the audit log automatically).
 */
class CompanyBankAccount extends Model
{
    use Auditable, SoftDeletes;

    protected $guarded = [];

    protected array $auditHidden = ['account_number', 'account_number_hash'];

    protected const LOCKED = ['name', 'bank_id', 'bank_branch_id', 'account_number', 'account_number_hash', 'account_last4', 'account_holder_name'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'is_active' => 'boolean', 'is_default' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            if ($m->isDirty(self::LOCKED)) {
                throw new LogicException('Paying accounts cannot be edited. Remove the account and add a new one.');
            }
        });
    }

    public function auditLabel(): string
    {
        return "{$this->name} (ending {$this->account_last4})";
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BankBranch::class, 'bank_branch_id');
    }

    public function masked(): string
    {
        return '•••• '.$this->account_last4;
    }

    /** Has a pay run paid from this account? (Works once pay_runs.company_bank_account_id exists; false until then.) */
    public function isUsed(): bool
    {
        return Schema::hasTable('pay_runs') && Schema::hasColumn('pay_runs', 'company_bank_account_id')
            && DB::table('pay_runs')->where('company_bank_account_id', $this->id)->exists();
    }
}
