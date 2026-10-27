<?php

namespace App\Models;

use App\Payroll\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** One version (usually one tax year) of a tax table. Immutable once created (only the approval fields may change later). */
class TaxTableVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            if (array_diff(array_keys($m->getDirty()), ['approval_status', 'approved_by', 'approved_at', 'updated_at'])) {
                throw new LogicException('A tax table version cannot be edited. Add a new version instead.');
            }
        });
    }

    public function taxTable(): BelongsTo
    {
        return $this->belongsTo(TaxTable::class);
    }

    public function slabs(): HasMany
    {
        return $this->hasMany(TaxTableSlab::class)->orderBy('sort');
    }

    /** The bands in the shape the calculators use (plain text numbers). */
    public function toSlabs(): array
    {
        return $this->loadMissing('slabs')->slabs->map(fn ($s) => [
            'over' => Decimal::plain((string) $s->over_amount),
            'up_to' => $s->up_to_amount === null ? null : Decimal::plain((string) $s->up_to_amount),
            'rate' => Decimal::plain((string) $s->rate),
            'fixed' => Decimal::plain((string) $s->fixed_amount),
        ])->values()->all();
    }
}
