<?php

namespace App\Models;

use App\Models\Concerns\UsesAttendanceConnection;
use Illuminate\Database\Eloquent\Model;

class AttendanceLoadChange extends Model
{
    use UsesAttendanceConnection;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
