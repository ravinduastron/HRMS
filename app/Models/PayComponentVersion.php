<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Payroll\ComponentCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable once created (only the approval fields may change later). */
class PayComponentVersion extends Model
{
    use Auditable;

    public const METHODS = [
        'fixed' => 'Fixed amount', 'percentage' => 'Percentage of other components', 'formula' => 'Formula',
        'input' => 'Entered each period (bonuses, incentives...)', 'system' => 'Supplied by another module (loans...)',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date', 'percentage_of' => 'array', 'rounding_dp' => 'integer', 'approved_at' => 'datetime',
            'epf_applicable' => 'boolean', 'msps_applicable' => 'boolean', 'etf_applicable' => 'boolean', 'tax_applicable' => 'boolean', 'ot_base' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            $allowed = ['approval_status', 'approved_by', 'approved_at', 'updated_at'];
            if (array_diff(array_keys($m->getDirty()), $allowed)) {
                throw new LogicException('A pay component version cannot be edited. Add a new version instead.');
            }
        });
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    /** Definition array understood by ComponentCalculator. */
    public function toDefinition(PayComponent $c): array
    {
        return [
            'name' => $c->name, 'type' => $c->type, 'active' => $c->is_active,
            'method' => $this->calc_method, 'formula' => $this->formula,
            'amount' => (string) ($this->default_amount ?? '0'),
            'percentage_rate' => $this->percentage_rate, 'percentage_of' => $this->percentage_of ?? [],
            'rounding_mode' => $this->rounding_mode, 'rounding_dp' => $this->rounding_dp,
        ];
    }

    /** What this version evaluates, as text (percentage components are shown as the formula they compile to). */
    public function displayFormula(): ?string
    {
        return ComponentCalculator::effectiveFormula([
            'method' => $this->calc_method, 'formula' => $this->formula,
            'percentage_rate' => $this->percentage_rate, 'percentage_of' => $this->percentage_of ?? [],
        ]);
    }
}
