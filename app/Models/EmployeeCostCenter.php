<?php

namespace App\Models;

use App\Models\Scopes\EmployeeSalaryScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** An employee's cost center from a date. Immutable; several can share a date (the last saved is in force). Visible only for employees the user can see. */
class EmployeeCostCenter extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EmployeeSalaryScope);

        static::updating(function (self $m) {
            if (array_diff(array_keys($m->getDirty()), ['approval_status', 'approved_by', 'approved_at', 'updated_at'])) {
                throw new LogicException('A cost center assignment cannot be edited. Add a new one instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('A cost center assignment cannot be deleted.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
