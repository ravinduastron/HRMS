<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of loan or advance. A loan copies the method and settlement rule when it is created, so changing the type never changes existing loans. */
class LoanType extends Model
{
    use Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return "{$this->code} {$this->name}";
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function capitalComponent(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'capital_component_id');
    }

    public function interestComponent(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'interest_component_id');
    }
}
