<?php

namespace App\Models;

use App\Models\Concerns\UsesAttendanceConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceLoadRun extends Model
{
    use UsesAttendanceConnection;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'from_date' => 'date', 'to_date' => 'date', 'started_at' => 'datetime', 'finished_at' => 'datetime',
            'counts' => 'array', 'quality' => 'array',
        ];
    }

    public function changes(): HasMany
    {
        return $this->hasMany(AttendanceLoadChange::class, 'load_run_id');
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
