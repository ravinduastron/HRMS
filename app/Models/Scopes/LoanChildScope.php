<?php

namespace App\Models\Scopes;

use App\Models\Loan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Installments, ledger entries and rate changes are visible only if their loan is (Loan's own scope applies inside the sub-query). */
class LoanChildScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (SchemeScope::restricts()) {
            $builder->whereIn($model->qualifyColumn('loan_id'), Loan::query()->select('id'));
        }
    }
}
