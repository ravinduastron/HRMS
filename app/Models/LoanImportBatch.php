<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanImportBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function auditLabel(): string
    {
        return "Loan import #{$this->id} ({$this->file_name})";
    }

    public function rows(): HasMany
    {
        return $this->hasMany(LoanImportRow::class)->orderBy('row_no');
    }
}
