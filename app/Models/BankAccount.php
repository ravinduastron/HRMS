<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Scopes\BankAccountScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * Immutable once created: no editing. Only is_active, approval fields and removal can change.
 * `account_number` is encrypted at rest (and automatically hidden from the audit log).
 */
class BankAccount extends Model
{
    use Auditable, SoftDeletes;

    protected $table = 'employee_bank_accounts';
    protected $guarded = [];

    protected array $auditHidden = ['account_number', 'account_number_hash'];

    protected const LOCKED = ['employee_id', 'bank_id', 'bank_branch_id', 'account_number', 'account_number_hash', 'account_last4', 'account_holder_name'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'is_active' => 'boolean', 'approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BankAccountScope);

        static::updating(function (self $m) {
            if ($m->isDirty(self::LOCKED)) {
                throw new LogicException('Bank accounts cannot be edited. Remove the account and add a new one.');
            }
        });
    }

    public function auditLabel(): string
    {
        return 'Account ending '.$this->account_last4;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BankBranch::class, 'bank_branch_id');
    }

    /** Usable for payment: active AND approved (approval workflow will set the status later). */
    public function scopePayable(Builder $q): Builder
    {
        return $q->where('is_active', true)->where('approval_status', 'approved');
    }

    public function masked(): string
    {
        return '•••• '.$this->account_last4;
    }
}
