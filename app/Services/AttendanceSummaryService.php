<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\EmployeeSalaryVersion;
use App\Models\LeaveEntry;
use App\Models\LeaveType;
use App\Payroll\AttendanceSummary;
use Carbon\Carbon;

/**
 * Reads attendance and leave (attendance database) and runs AttendanceSummary for any date range.
 * Callers MUST pass only employee ids the signed-in user may see (the scheme restriction is not re-applied here,
 * because a pay run runs for whole schemes). The pay run will call this once per window.
 * Each employee's hours limit is the one in force on the last day of the range (from their salary record).
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

        // Hours limit: the salary version in force on the last day of the range (newest first, so unique() keeps the right one)
        $limits = EmployeeSalaryVersion::withoutGlobalScopes()->whereIn('employee_id', $employeeIds)->where('approval_status', 'approved')
            ->whereDate('effective_from', '<=', $t)->orderByDesc('effective_from')->orderByDesc('id')
            ->get(['employee_id', 'max_hours'])->unique('employee_id')
            ->mapWithKeys(fn ($v) => [$v->employee_id => $v->max_hours !== null ? (string) $v->max_hours : null]);

        $out = [];
        foreach ($employeeIds as $id) {
            $out[$id] = AttendanceSummary::calculate(
                ($days[$id] ?? collect())->map(fn ($r) => $r->getAttributes())->values()->all(),
                ($leaves[$id] ?? collect())->map(fn ($r) => $r->getAttributes())->values()->all(),
                $types, $f, $t, $source, $limits[$id] ?? null,
            );
        }

        return $out;
    }
}
