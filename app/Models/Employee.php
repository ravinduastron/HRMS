<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master record synced from MiHCM. Future modules (bank accounts, salary...) should hold `employee_id`
 * (FK to employees.id) and be exposed here as relationships, e.g. bankAccounts(), salaries().
 */
class Employee extends Model
{
    use Auditable;

    protected $guarded = [];

    protected array $auditHidden  = ['nic_no'];
    protected array $auditExclude = ['last_synced_at'];

    protected function casts(): array
    {
        return [
            'birthday' => 'date', 'date_joined' => 'date', 'contract_expiry_date' => 'date',
            'resigned_date' => 'date', 'resignation_effect_date' => 'date', 'last_working_date' => 'date',
            'mihcm_active' => 'boolean', 'mihcm_deleted' => 'boolean',
            'is_active' => 'boolean', 'is_payroll_active' => 'boolean',
            'mihcm_org_structure' => 'array',
            'first_synced_at' => 'datetime', 'last_synced_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return trim(($this->employee_no ?? '').' '.($this->full_name ?? $this->display_name ?? ''));
    }

    public function syncChanges(): HasMany
    {
        return $this->hasMany(EmployeeSyncChange::class);
    }

    /** Employees that should be included when payroll runs. */
    public function scopePayrollEligible(Builder $q): Builder
    {
        return $q->where('is_active', true)->where('is_payroll_active', true);
    }

    public function scopeFilter(Builder $q, array $f): Builder
    {
        return $q
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('employee_no', 'like', "%{$s}%")->orWhere('full_name', 'like', "%{$s}%")
                ->orWhere('display_name', 'like', "%{$s}%")->orWhere('nic_no', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->when(($f['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($f['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when(($f['payroll'] ?? null) === 'active', fn ($q) => $q->where('is_payroll_active', true))
            ->when(($f['payroll'] ?? null) === 'inactive', fn ($q) => $q->where('is_payroll_active', false))
            ->when($f['department'] ?? null, fn ($q, $d) => $q->where('department', $d));
    }

    public function getFullAddressAttribute(): string
    {
        return collect([$this->permanent_address_1, $this->permanent_address_2, $this->permanent_address_3,
            $this->permanent_district, $this->permanent_postal_code, $this->permanent_country])->filter()->implode(', ');
    }

    public function getMihcmStatusAttribute(): string
    {
        return $this->mihcm_deleted ? 'Removed' : ($this->mihcm_active ? 'Active' : 'Inactive');
    }
}
