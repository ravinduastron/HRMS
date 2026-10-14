<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Scopes\SalaryStructureScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A template of pay components for one scheme. Assigning it COPIES its lines onto the employee. */
class SalaryStructure extends Model
{
    use Auditable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new SalaryStructureScope);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(Scheme::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalaryStructureLine::class)->orderBy('sort')->orderBy('id');
    }

    /** Salary versions that started from this structure (used to lock the scheme once in use). */
    public function employeeVersions(): HasMany
    {
        return $this->hasMany(EmployeeSalaryVersion::class)->withoutGlobalScopes();
    }
}
