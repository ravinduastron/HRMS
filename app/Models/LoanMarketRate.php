<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** The market interest rate in force from a date, used to value loans at fair value. Immutable. */
class LoanMarketRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            if (array_diff(array_keys($m->getDirty()), ['approval_status', 'approved_by', 'approved_at', 'updated_at'])) {
                throw new LogicException('A market rate cannot be edited. Add a new one instead.');
            }
        });
    }

    public function auditLabel(): string
    {
        return 'Market rate from '.$this->effective_from->format('d M Y');
    }
}
