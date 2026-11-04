<?php

namespace App\Models;

use App\Models\Scopes\HirePurchaseChildScope;
use App\Payroll\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One monthly installment. The amount is encrypted and never edited; only the status fields change (deducted, stopped, replaced). */
class HirePurchaseInstallment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'encrypted', 'due_month' => 'date', 'deducted_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new HirePurchaseChildScope);

        static::updating(function (self $m) {
            if (array_diff(array_keys($m->getDirty()), ['status', 'deducted_on', 'updated_at'])) {
                throw new LogicException('Installment amounts cannot be edited. Change the future deductions to replace them.');
            }
        });
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(HirePurchase::class, 'hire_purchase_id');
    }

    public function amountText(): string
    {
        return Decimal::round((string) $this->amount, 2);
    }
}
