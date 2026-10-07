<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankBranch extends Model
{
    use Auditable;

    protected $guarded = [];

    public function auditLabel(): string
    {
        return "{$this->code} {$this->name}";
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }
}
