<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeSyncRun extends Model
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('mihcm.log_connection') ?: parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'is_baseline' => 'boolean'];
    }

    public function changes(): HasMany
    {
        return $this->hasMany(EmployeeSyncChange::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function durationSeconds(): ?int
    {
        return $this->started_at && $this->finished_at ? (int) $this->started_at->diffInSeconds($this->finished_at, true) : null;
    }
}
