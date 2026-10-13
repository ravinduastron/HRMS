<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Groups components for the payslip: a "combined" group prints one line with the sum of its members (amount only). */
class PayComponentGroup extends Model
{
    use Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['combine_on_payslip' => 'boolean', 'is_active' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function components(): HasMany
    {
        return $this->hasMany(PayComponent::class);
    }
}
