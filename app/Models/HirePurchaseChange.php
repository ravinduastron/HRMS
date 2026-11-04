<?php

namespace App\Models;

use App\Models\Scopes\HirePurchaseChildScope;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** The history of changes to the future deductions of a hire purchase. Amounts are encrypted. Immutable. */
class HirePurchaseChange extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_month' => 'date', 'restart_month' => 'date',
            'old_amount' => 'encrypted', 'new_amount' => 'encrypted', 'old_total' => 'encrypted', 'new_total' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new HirePurchaseChildScope);
        static::updating(fn () => throw new LogicException('Hire purchase changes are immutable.'));
    }
}
