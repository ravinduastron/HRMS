<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TaxTableSlab extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Tax bands cannot be edited. Add a new version instead.'));
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TaxTableVersion::class, 'tax_table_version_id');
    }
}
