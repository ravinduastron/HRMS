<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxTable extends Model
{
    protected $guarded = [];

    public function auditLabel(): string
    {
        return $this->name;
    }

    /** Newest first. */
    public function versions(): HasMany
    {
        return $this->hasMany(TaxTableVersion::class)->orderByDesc('effective_from');
    }
}
