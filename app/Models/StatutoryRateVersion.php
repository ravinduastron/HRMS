<?php

namespace App\Models;

use App\Payroll\Decimal;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One version of the EPF / MSPS / ETF rates. Immutable once created (only the approval fields may change later). Audited by StatutoryService. */
class StatutoryRateVersion extends Model
{
    public const KEYS = ['epf_employee', 'epf_employer', 'msps_employee', 'msps_employer', 'etf_employer'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            if (array_diff(array_keys($m->getDirty()), ['approval_status', 'approved_by', 'approved_at', 'updated_at'])) {
                throw new LogicException('Contribution rates cannot be edited. Add a new version instead.');
            }
        });
    }

    public function auditLabel(): string
    {
        return 'Rates from '.$this->effective_from->format('d M Y');
    }

    /** @return array<string, string> rate name => percentage as plain text */
    public function rates(): array
    {
        return collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => Decimal::plain((string) $this->{$k.'_rate'})])->all();
    }
}
