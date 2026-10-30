<?php

namespace App\Models;

use App\Models\Scopes\EmployeeSalaryScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One loan or advance. `principal` (the amount originally lent) is encrypted. Balances are NOT stored here: they come from the ledger.
 * Audited through LoanService (amounts of settlements and waivers are logged; schedules are not).
 * Visible only for employees the user can see.
 */
class Loan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'principal' => 'encrypted', 'disbursed_on' => 'date', 'first_due_month' => 'date', 'settled_on' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EmployeeSalaryScope);
    }

    public function auditLabel(): string
    {
        return "Loan {$this->reference}";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LoanType::class, 'loan_type_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('due_month')->orderBy('seq');
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(LoanLedgerEntry::class)->orderBy('id');
    }

    public function rateChanges(): HasMany
    {
        return $this->hasMany(LoanRateChange::class)->orderByDesc('id');
    }
}
