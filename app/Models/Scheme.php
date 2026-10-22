<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of employees (MiHCM "scheme"). Users are allocated schemes and can only see/manage those employees.
 * A scheme is also the pay group: pay cycles, windows and pay dates live in its versioned pay settings,
 * and `default_fund` (epf | msps) is the provident fund its employees follow unless set by hand.
 */
class Scheme extends Model
{
    use Auditable;

    protected $guarded = [];

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function paySettings(): HasMany
    {
        return $this->hasMany(SchemePaySetting::class)->orderByDesc('effective_from');
    }

    /** The approved pay settings in force on $date (default today). */
    public function paySettingsAt(?Carbon $date = null): ?SchemePaySetting
    {
        return $this->paySettings()->where('approval_status', 'approved')
            ->whereDate('effective_from', '<=', ($date ?? today())->toDateString())->first();
    }

    /** Schemes the given user may see: all of them with schemes.all, otherwise only the allocated ones. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $user->can('schemes.all') ? $q : $q->whereIn('schemes.id', $user->schemeIds());
    }
}
