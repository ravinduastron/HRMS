<?php

namespace App\Models;

use App\Models\Scopes\SalaryStructureScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A salary adjustment for one scheme: staged, reviewed, applied, and possibly rolled back. Visible for the schemes the user may see. */
class SalaryAdjustmentBatch extends Model
{
    public const STATUSES = ['staged' => 'Awaiting confirmation', 'applied' => 'Applied', 'rolled_back' => 'Rolled back', 'discarded' => 'Discarded'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'applied_at' => 'datetime', 'rolled_back_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new SalaryStructureScope); // filters on scheme_id
    }

    public function auditLabel(): string
    {
        return "Salary adjustment #{$this->id}";
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(Scheme::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(SalaryAdjustmentRow::class, 'salary_adjustment_batch_id')->orderBy('row_no')->orderBy('id');
    }
}
