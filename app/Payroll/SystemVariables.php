<?php

namespace App\Payroll;

/** Values the payroll run supplies to formulas (from attendance, leave and the calendar). */
final class SystemVariables
{
    /** @return array<string, array{0: string, 1: string, 2: string}> name => [label, unit, default for the test panel] */
    public static function all(): array
    {
        return [
            'PERIOD_DAYS'     => ['Calendar days in this pay period', 'days', '30'],
            'DAYS_IN_MONTH'   => ['Calendar days in the month', 'days', '30'],
            'WORKING_DAYS'    => ['Expected working days in the period', 'days', '26'],
            'PRESENT_DAYS'    => ['Days present', 'days', '26'],
            'PAID_DAYS'       => ['Days paid (present, plus confirmed paid leave on expected days)', 'days', '26'],
            'LWP_DAYS'        => ['No-pay leave days', 'days', '0'],
            'ABSENT_DAYS'     => ['Absent days (expected but not present, no leave)', 'days', '0'],
            'LATE_HOURS'      => ['Late hours', 'hours', '0'],
            'OT_HOURS'        => ['Approved normal overtime hours', 'hours', '0'],
            'OT_DOUBLE_HOURS' => ['Approved double-rate overtime hours', 'hours', '0'],
            'OT_TRIPLE_HOURS' => ['Approved triple-rate overtime hours', 'hours', '0'],
            'WORKED_HOURS'    => ['Hours worked (all hours, overtime included)', 'hours', '0'],
            'PAID_HOURS'      => ['Hours paid: hours worked, limited to the employee\'s hours limit', 'hours', '0'],
            'MAX_HOURS'       => ['Employee\'s hours limit per month (0 = no limit)', 'hours', '0'],
        ];
    }

    public static function names(): array
    {
        return array_keys(self::all());
    }
}
