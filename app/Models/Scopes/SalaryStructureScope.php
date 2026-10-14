<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Users see and manage only the salary structures of their allocated schemes (schemes.all sees everything). */
class SalaryStructureScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (SchemeScope::restricts()) {
            $builder->whereIn($model->qualifyColumn('scheme_id'), auth()->user()->schemeIds());
        }
    }
}
