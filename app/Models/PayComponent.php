<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayComponent extends Model
{
    use Auditable;

    public const TYPES = [
        'earning' => 'Earning', 'deduction' => 'Deduction',
        'employer_contribution' => 'Employer contribution', 'info' => 'Information only',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'show_on_payslip' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return "{$this->code} {$this->name}";
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PayComponentVersion::class)->orderByDesc('effective_from');
    }

    /** The version in force on $date (default today). */
    public function current(?Carbon $date = null): ?PayComponentVersion
    {
        $date = ($date ?? today())->copy()->startOfDay();

        return $this->loadMissing('versions')->versions
            ->first(fn ($v) => $v->approval_status === 'approved' && $v->effective_from->lte($date));
    }

    /** The newest approved version, even if it starts in the future. */
    public function latestVersion(): ?PayComponentVersion
    {
        return $this->loadMissing('versions')->versions->first(fn ($v) => $v->approval_status === 'approved');
    }
}
