<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\LeaveEntry;
use App\Models\LeaveType;
use App\Payroll\AttendanceSummary;
use Carbon\Carbon;

/**
 * Reads attendance and leave (attendance database) and runs AttendanceSummary for any date range.
 * Callers MUST pass only employee ids the signed-in user may see (the scheme restriction is not re-applied here,
 * because a pay run runs for whole schemes). The pay run will call this once per window.
 */
class AttendanceSummaryService
{
    /** @return array<int, array{variables: array, info: array, warnings: array}> employee id => summary */
    public function forEmployees(array $employeeIds, Carbon $from, Carbon $to): array
    {
        if (! $employeeIds) {
            return [];
        }
        $f = $from->toDateString();
        $t = $to->toDateString();

        $days = AttendanceRecord::withoutGlobalScopes()->whereIn('employee_id', $employeeIds)->whereBetween('work_date', [$f, $t])
            ->get()->groupBy('employee_id');
        $leaves = LeaveEntry::withoutGlobalScopes()->whereIn('employee_id', $employeeIds)->where('start_date', '<=', $t)->where('end_date', '>=', $f)
            ->get()->groupBy('employee_id');
        $types = LeaveType::get()->mapWithKeys(fn ($x) => [$x->code => $x->is_no_pay === null ? null : (bool) $x->is_no_pay])->all();
        $source = (string) config('attendance.ot_approved_source', 'decimal');

        $out = [];
        foreach ($employeeIds as $id) {
            $out[$id] = AttendanceSummary::calculate(
                ($days[$id] ?? collect())->map(fn ($r) => $r->getAttributes())->values()->all(),
                ($leaves[$id] ?? collect())->map(fn ($r) => $r->getAttributes())->values()->all(),
                $types, $f, $t, $source,
            );
        }

        return $out;
    }
}
