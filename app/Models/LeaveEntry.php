<?php

namespace App\Models;

use App\Models\Concerns\UsesAttendanceConnection;
use App\Models\Scopes\AttendanceVisibilityScope;
use Illuminate\Database\Eloquent\Model;

/** One leave request from MiHCM (P = pending, C = confirmed). Only confirmed leave may be deducted. */
class LeaveEntry extends Model
{
    use UsesAttendanceConnection;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'end_date' => 'date', 'requested_at' => 'datetime',
            'first_loaded_at' => 'datetime', 'loaded_at' => 'datetime', 'missing_since' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new AttendanceVisibilityScope);
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'C';
    }
}
