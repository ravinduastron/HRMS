<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One staged amount (employee + component) of an upload. `data` holds amounts, so it is encrypted. */
class PayInputRow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'errors' => 'array', 'warnings' => 'array'];
    }
}
