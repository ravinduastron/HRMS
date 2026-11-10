<?php

namespace App\Models;

use App\Models\Scopes\EmployeeSalaryScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** An employee added to a pay item for one pay period only. Never edited; removed only while the period is open and no amount was entered. */
class PayInputAddition extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new EmployeeSalaryScope);
        static::updating(fn () => throw new LogicException('A period-only addition cannot be edited. Remove it and add it again.'));
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
