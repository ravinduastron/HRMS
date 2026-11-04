<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier employees buy from on hire purchase. Contact details only. Never deleted, only deactivated. */
class HirePurchaseVendor extends Model
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

    public function purchases(): HasMany
    {
        return $this->hasMany(HirePurchase::class);
    }
}
