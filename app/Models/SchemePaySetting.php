<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One version of a scheme's pay settings. Immutable once created (only the approval fields may change later). Audited by SchemePayService. */
class SchemePaySetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'cycles' => 'array', 'windows' => 'array', 'pay_date' => 'array', 'approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            $allowed = ['approval_status', 'approved_by', 'approved_at', 'updated_at'];
            if (array_diff(array_keys($m->getDirty()), $allowed)) {
                throw new LogicException('Pay settings cannot be edited. Add a new version instead.');
            }
        });
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(Scheme::class);
    }

    /** The settings in the shape PayCalendar understands. */
    public function toCalendar(): array
    {
        return ['cycles' => $this->cycles, 'windows' => $this->windows, 'pay_date' => $this->pay_date];
    }
}
