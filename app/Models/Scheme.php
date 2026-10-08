<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A group of employees (MiHCM "scheme"). Users are allocated schemes and can only see/manage those employees. */
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

    /** Schemes the given user may see: all of them with schemes.all, otherwise only the allocated ones. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $user->can('schemes.all') ? $q : $q->whereIn('schemes.id', $user->schemeIds());
    }
}
