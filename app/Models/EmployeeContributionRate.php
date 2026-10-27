<?php

namespace App\Models;

use App\Models\Scopes\EmployeeSalaryScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An employee's own EPF/MSPS contribution rate from a date (employee_rate null = back to the default of their fund).
 * Immutable. Several can share a date: the last saved is in force. Visible only for employees the user can see.
 */
class EmployeeContributionRate extends Model
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
                throw new LogicException('An employee contribution rate cannot be edited. Add a new one instead.');
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
