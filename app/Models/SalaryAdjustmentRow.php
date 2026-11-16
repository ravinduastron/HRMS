<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One staged employee + component of an adjustment. `data` holds amounts, so it is encrypted. */
class SalaryAdjustmentRow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'errors' => 'array', 'warnings' => 'array'];
    }
}
