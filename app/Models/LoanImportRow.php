<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One staged row of an upload. `data` holds amounts, so it is encrypted. */
class LoanImportRow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'errors' => 'array', 'warnings' => 'array'];
    }
}
