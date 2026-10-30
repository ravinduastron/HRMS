<?php

namespace App\Models;

use App\Models\Scopes\EmployeeSalaryScope;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One loan in a saved valuation. Amounts are encrypted. Visible only for employees the user can see. */
class LoanValuationLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['outstanding_principal' => 'encrypted', 'nominal_remaining' => 'encrypted', 'fair_value' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EmployeeSalaryScope);
        static::updating(fn () => throw new LogicException('A saved valuation cannot be edited.'));
    }
}
