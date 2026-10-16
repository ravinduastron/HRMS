<?php

namespace App\Models\Scopes;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Users see attendance and leave only for employees they can see (their allocated schemes).
 * The rows may live in another database, so there is no sub-query: the visible employee ids are fetched first.
 * Console, queue and the loader run without a signed-in user and are unrestricted.
 */
class AttendanceVisibilityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (SchemeScope::restricts()) {
            $builder->whereIn($model->qualifyColumn('employee_id'), Employee::query()->pluck('id')->all());
        }
    }
}
