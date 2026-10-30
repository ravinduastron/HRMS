<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** A saved fair-value run. Immutable. */
class LoanValuation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['as_at' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A saved valuation cannot be edited.'));
    }

    public function auditLabel(): string
    {
        return 'Valuation as at '.$this->as_at->format('d M Y');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LoanValuationLine::class)->orderBy('employee_no')->orderBy('loan_reference');
    }
}
