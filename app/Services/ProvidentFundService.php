<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Scheme;

/**
 * Keeps employees.provident_fund in line with the default fund of their scheme (Scheme.default_fund).
 * An employee whose fund was set by hand (provident_fund_override) is never touched.
 * Each change is saved through the model, so it appears in the audit log.
 */
class ProvidentFundService
{
    /** Make one employee follow their scheme's default (if they have a scheme and no manual setting). Returns true if it changed. */
    public function apply(Employee $employee): bool
    {
        if ($employee->provident_fund_override || ! $employee->scheme_id) {
            return false;
        }
        $fund = Scheme::whereKey($employee->scheme_id)->value('default_fund');
        if (! $fund || $employee->provident_fund === $fund) {
            return false;
        }
        $employee->forceFill(['provident_fund' => $fund])->save();

        return true;
    }

    /** Bring every employee (of one scheme, or all) in line with their scheme's default. Returns how many changed. */
    public function reconcile(?int $schemeId = null): int
    {
        $changed = 0;

        foreach (['epf', 'msps'] as $fund) {
            $ids = Scheme::where('default_fund', $fund)->when($schemeId, fn ($q) => $q->whereKey($schemeId))->pluck('id')->all();
            if (! $ids) {
                continue;
            }
            Employee::withoutGlobalScopes()->where('provident_fund_override', false)->whereIn('scheme_id', $ids)->where('provident_fund', '!=', $fund)
                ->chunkById(200, function ($employees) use ($fund, &$changed) {
                    foreach ($employees as $e) {
                        $e->forceFill(['provident_fund' => $fund])->save();
                        $changed++;
                    }
                });
        }

        return $changed;
    }
}
