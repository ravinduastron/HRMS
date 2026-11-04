<?php

namespace App\Models\Scopes;

use App\Models\HirePurchase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Installments and change history are visible only if their hire purchase is (HirePurchase's own scope applies inside the sub-query). */
class HirePurchaseChildScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (SchemeScope::restricts()) {
            $builder->whereIn($model->qualifyColumn('hire_purchase_id'), HirePurchase::query()->select('id'));
        }
    }
}
