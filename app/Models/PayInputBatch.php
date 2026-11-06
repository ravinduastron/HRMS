<?php

namespace App\Models;

use App\Models\Scopes\SalaryStructureScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An Excel upload of pay inputs for one pay period. Visible for the schemes the user may see. */
class PayInputBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new SalaryStructureScope); // filters on scheme_id
    }

    public function auditLabel(): string
    {
        return "Pay input upload #{$this->id} ({$this->file_name})";
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayPeriod::class, 'pay_period_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PayInputRow::class)->orderBy('row_no')->orderBy('id');
    }
}
