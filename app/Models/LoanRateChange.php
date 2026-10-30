<?php

namespace App\Models;

use App\Models\Scopes\LoanChildScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** The history of interest-rate changes of a loan: previous and new rate, and what it did to the installment and the interest still to come. Immutable. */
class LoanRateChange extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_month' => 'date', 'outstanding_principal' => 'encrypted', 'old_installment' => 'encrypted', 'new_installment' => 'encrypted',
            'old_remaining_interest' => 'encrypted', 'new_remaining_interest' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new LoanChildScope);
        static::updating(fn () => throw new LogicException('Rate changes are immutable.'));
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
