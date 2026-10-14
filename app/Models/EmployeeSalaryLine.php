<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One component on an employee's salary version. `amount` is encrypted at rest. Lines are never edited. */
class EmployeeSalaryLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Salary lines cannot be edited. Add a new salary version instead.'));
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalaryVersion::class, 'employee_salary_version_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
