<?php

namespace App\Models;

use App\Models\Concerns\UsesAttendanceConnection;
use App\Models\Scopes\AttendanceVisibilityScope;
use Illuminate\Database\Eloquent\Model;

/** One employee, one calendar day, as loaded from MiHCM. Written only by AttendanceLoadService. Not final until a pay run snapshots it. */
class AttendanceRecord extends Model
{
    use UsesAttendanceConnection;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_date' => 'date', 'in_time' => 'datetime', 'out_time' => 'datetime', 'in_time_2' => 'datetime', 'out_time_2' => 'datetime',
            'expected' => 'boolean', 'present' => 'boolean', 'midnight' => 'boolean', 'lieu' => 'boolean', 'day_off' => 'boolean', 'approval_done' => 'boolean',
            'first_loaded_at' => 'datetime', 'loaded_at' => 'datetime', 'missing_since' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new AttendanceVisibilityScope);
    }
}
