<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\UsesAttendanceConnection;
use Illuminate\Database\Eloquent\Model;

/** Created automatically when a load sees a new leave type. is_no_pay stays null ("needs review") until an administrator decides. */
class LeaveType extends Model
{
    use Auditable, UsesAttendanceConnection;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_no_pay' => 'boolean', 'first_seen_at' => 'datetime'];
    }

    public function auditLabel(): string
    {
        return "{$this->code} {$this->name}";
    }

    public function needsReview(): bool
    {
        return $this->is_no_pay === null;
    }
}
