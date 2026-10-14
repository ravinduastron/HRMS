<?php

namespace App\Models\Scopes;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** A salary version is visible only if its employee is (Employee's own scope applies inside the sub-query). */
class EmployeeSalaryScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (SchemeScope::restricts()) {
            $builder->whereIn($model->qualifyColumn('employee_id'), Employee::query()->select('id'));
        }
    }
}
