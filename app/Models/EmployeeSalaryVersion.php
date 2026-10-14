<?php

namespace App\Models;

use App\Models\Scopes\EmployeeSalaryScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Immutable once created (only the approval fields may change later). Audited through EmployeeSalaryService (no amounts are logged). */
class EmployeeSalaryVersion extends Model
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
            $allowed = ['approval_status', 'approved_by', 'approved_at', 'updated_at'];
            if (array_diff(array_keys($m->getDirty()), $allowed)) {
                throw new LogicException('A salary version cannot be edited. Add a new version instead.');
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(EmployeeSalaryLine::class)->orderBy('id');
    }
}
