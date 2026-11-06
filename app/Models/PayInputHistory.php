<?php

namespace App\Models;

use App\Models\Scopes\EmployeeSalaryScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One change to a pay input, with the old and the new amount (encrypted). Immutable. */
class PayInputHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'pay_input_history';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['old_amount' => 'encrypted', 'new_amount' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EmployeeSalaryScope);
        static::updating(fn () => throw new LogicException('Pay input history is immutable.'));
        static::deleting(fn () => throw new LogicException('Pay input history cannot be deleted.'));
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
