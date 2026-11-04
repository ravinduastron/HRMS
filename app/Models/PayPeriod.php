<?php

namespace App\Models;

use App\Models\Scopes\SalaryStructureScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One pay cycle of a scheme in a month. Visible only for the schemes the user may see (same scope as salary structures).
 * Dates change only while the period is Open (a refresh from the scheme's current settings). Closed and Locked periods never change their dates.
 */
class PayPeriod extends Model
{
    public const STATUSES = ['open' => 'Open', 'closed' => 'Closed', 'locked' => 'Locked'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'pay_date_from' => 'date', 'pay_date_to' => 'date',
            'windows' => 'array', 'statutory_applies' => 'boolean', 'closed_at' => 'datetime', 'locked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new SalaryStructureScope);

        static::updating(function (self $m) {
            $allowed = ['status', 'closed_at', 'closed_by', 'closed_by_name', 'locked_at', 'updated_at'];
            if ($m->getOriginal('status') === 'open') {
                $allowed = array_merge($allowed, ['scheme_pay_setting_id', 'tax_year_start', 'tax_year_label', 'period_start', 'period_end', 'windows', 'pay_date_from', 'pay_date_to', 'statutory_applies']);
            }
            if (array_diff(array_keys($m->getDirty()), $allowed)) {
                throw new LogicException('The dates of a closed or locked pay period cannot be changed.');
            }
        });

        static::deleting(function (self $m) {
            if ($m->status !== 'open') {
                throw new LogicException('Only an open pay period can be removed.');
            }
        });
    }

    public function auditLabel(): string
    {
        return $this->label();
    }

    /** "Nov 2026 · cycle 1 (1 Nov to 30 Nov)" style short name. */
    public function label(): string
    {
        return $this->period_start->format('M Y').' · cycle '.$this->cycle_no;
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(Scheme::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** Does this stored period hold exactly the dates and settings of a freshly worked-out row (PayPeriodPlan::forMonth)? */
    public function matches(array $row, int $settingId): bool
    {
        $norm = fn ($w) => json_encode(collect($w)->sortKeys()->map(fn ($x) => array_values($x))->all()); // MySQL JSON reorders keys

        return (int) $this->scheme_pay_setting_id === $settingId
            && $this->period_start->toDateString() === $row['period_start'] && $this->period_end->toDateString() === $row['period_end']
            && $this->pay_date_from->toDateString() === $row['pay_date_from'] && $this->pay_date_to->toDateString() === $row['pay_date_to']
            && (bool) $this->statutory_applies === (bool) $row['statutory_applies']
            && $norm($this->windows) === $norm($row['windows']);
    }
}
