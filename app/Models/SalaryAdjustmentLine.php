<?php

namespace App\Models;

use App\Models\Scopes\EmployeeSalaryScope;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** An amount an applied adjustment really changed (old and new, encrypted). Only the rollback link is ever set later. */
class SalaryAdjustmentLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['old_amount' => 'encrypted', 'new_amount' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EmployeeSalaryScope);

        static::updating(function (self $m) {
            if (array_diff(array_keys($m->getDirty()), ['rollback_version_id', 'updated_at'])) {
                throw new LogicException('An adjustment line cannot be edited.');
            }
        });
        static::deleting(fn () => throw new LogicException('An adjustment line cannot be deleted.'));
    }
}
