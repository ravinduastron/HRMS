<?php

namespace App\Models;

use App\Models\Scopes\EmployeeSalaryScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One hire purchase of an employee. `installment_amount` (the original installment) is encrypted.
 * Only status, stopped_on and closed_reason ever change. Audited through HirePurchaseService (no amounts are logged).
 * Visible only for employees the user can see.
 */
class HirePurchase extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['installment_amount' => 'encrypted', 'first_due_month' => 'date', 'stopped_on' => 'date', 'approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new EmployeeSalaryScope);

        static::updating(function (self $m) {
            // The reference may be replaced once, while it still holds the temporary "pending-..." value given at creation (blank reference becomes HP-000123).
            $allowed = ['status', 'stopped_on', 'closed_reason', 'updated_at'];
            if (str_starts_with((string) $m->getOriginal('reference'), 'pending-')) {
                $allowed[] = 'reference';
            }
            if (array_diff(array_keys($m->getDirty()), $allowed)) {
                throw new LogicException('A hire purchase cannot be edited. Change its future deductions instead.');
            }
        });
    }

    public function auditLabel(): string
    {
        return "Hire purchase {$this->reference}";
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(HirePurchaseVendor::class, 'hire_purchase_vendor_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(HirePurchaseInstallment::class)->orderBy('due_month')->orderBy('seq');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(HirePurchaseChange::class)->orderByDesc('id');
    }
}
