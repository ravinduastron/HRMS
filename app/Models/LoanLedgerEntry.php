<?php

namespace App\Models;

use App\Models\Scopes\LoanChildScope;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A money movement on a loan. Immutable. Outstanding principal is the sum of `principal` over all entries of the loan. */
class LoanLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [
        'disbursement' => 'Loan paid out', 'opening' => 'Opening balance (imported)', 'installment' => 'Installment deducted',
        'settlement' => 'Early settlement received', 'interest_waived' => 'Interest ignored at settlement', 'rate_change' => 'Interest rate changed',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['principal' => 'encrypted', 'interest' => 'encrypted', 'effective_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new LoanChildScope);
        static::updating(fn () => throw new LogicException('Loan ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Loan ledger entries cannot be deleted.'));
    }
}
