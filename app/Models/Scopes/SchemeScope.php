<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits employees to the signed-in user's allocated schemes.
 *  - schemes.all permission: no restriction
 *  - no allocated schemes: sees nothing (secure by default); employees with no scheme are visible only with schemes.all
 *  - no signed-in user (console, queue, MiHCM sync): no restriction
 * Route-model binding goes through this scope, so out-of-scope records return 404.
 */
class SchemeScope implements Scope
{
    public static function restricts(): bool
    {
        $user = auth()->user();

        return $user !== null && ! $user->can('schemes.all');
    }

    public function apply(Builder $builder, Model $model): void
    {
        if (self::restricts()) {
            $builder->whereIn($model->qualifyColumn('scheme_id'), auth()->user()->schemeIds());
        }
    }
}
