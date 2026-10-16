<?php

namespace App\Models\Concerns;

/** Models stored in the attendance database (config/attendance.php). Never join or use whereHas across databases. */
trait UsesAttendanceConnection
{
    public function getConnectionName(): ?string
    {
        return config('attendance.connection') ?: parent::getConnectionName();
    }
}
