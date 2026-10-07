<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSyncChange extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('mihcm.log_connection') ?: parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmployeeSyncRun::class, 'employee_sync_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
