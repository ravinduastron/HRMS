<?php

namespace App\Models;

use App\Models\Scopes\LoanChildScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One installment. Amounts are encrypted and never edited; only the status fields change (deducted, settled, superseded, cancelled). */
class LoanInstallment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['principal_amount' => 'encrypted', 'interest_amount' => 'encrypted', 'due_month' => 'date', 'deducted_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new LoanChildScope);

        static::updating(function (self $m) {
            if (array_diff(array_keys($m->getDirty()), ['status', 'deducted_on', 'ledger_entry_id', 'updated_at'])) {
                throw new LogicException('Installment amounts cannot be edited. Change the rate to re-price the remaining installments.');
            }
        });
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function total(): string
    {
        return \App\Payroll\Decimal::add((string) $this->principal_amount, (string) $this->interest_amount);
    }
}
