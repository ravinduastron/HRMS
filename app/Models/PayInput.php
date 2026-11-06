<?php

namespace App\Models;

use App\Models\Scopes\EmployeeSalaryScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** The value in force of one input (pay period, employee, component). Amount encrypted. Only changed through PayInputService. Visible only for employees the user can see. */
class PayInput extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'encrypted', 'approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EmployeeSalaryScope);

        static::updating(function (self $m) {
            if (array_diff(array_keys($m->getDirty()), ['amount', 'source', 'import_batch_id', 'entered_by', 'entered_by_name', 'updated_at'])) {
                throw new LogicException('Only the amount of a pay input can change.');
            }
        });
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayPeriod::class, 'pay_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
