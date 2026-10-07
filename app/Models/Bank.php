<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    use Auditable;

    protected $guarded = [];

    public function auditLabel(): string
    {
        return "{$this->code} {$this->name}";
    }

    public function branches(): HasMany
    {
        return $this->hasMany(BankBranch::class)->orderBy('code');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }
}
