<?php

namespace App\Payroll;

use DateTimeImmutable;

/**
 * Turns daily attendance rows and leave entries into the formula variables for ANY date range (a pay period, an overtime
 * window, an attendance-allowance window...). Plain arrays in, plain arrays out: no database, no framework.
 *
 * Rules (see docs/ATTENDANCE_VIEWS.md):
 *  - WORKING_DAYS  days MiHCM expects the employee (expected = true)
 *  - PRESENT_DAYS  expected days the employee was present
 *  - PAID_DAYS     expected days paid: present (less any no-pay leave part) or covered by CONFIRMED paid leave (half-day aware)
 *  - ABSENT_DAYS   expected days not present and not covered by CONFIRMED leave (half days count as 0.5)
 *  - LWP_DAYS      expected days covered by CONFIRMED leave of a type marked no-pay (half-day aware)
 *  - LATE_HOURS    late time, in decimal hours
 *  - OT_*_HOURS    APPROVED overtime only, from days whose approval is done; which approved figure is used is a setting
 *  - WORKED_HOURS  hours worked, in decimal hours (all hours, overtime included)
 *  - PAID_HOURS    WORKED_HOURS limited to the employee's monthly hours limit ($maxHours); no limit = WORKED_HOURS
 *  - MAX_HOURS     the limit itself (0 = none)
 * Pending leave (P) and leave types not yet reviewed are never deducted: they are reported in 'info' / 'warnings'.
 * Rows MiHCM stopped returning (missing_since) and cancelled leave are excluded and reported.
 */
final class AttendanceSummary
{
    public const VARIABLES = ['WORKING_DAYS', 'PRESENT_DAYS', 'PAID_DAYS', 'ABSENT_DAYS', 'LWP_DAYS', 'LATE_HOURS', 'OT_HOURS', 'OT_DOUBLE_HOURS', 'OT_TRIPLE_HOURS', 'WORKED_HOURS', 'PAID_HOURS', 'MAX_HOURS'];

    /**
     * @param  array<int, array>  $days  attendance rows (attendance_records columns)
     * @param  array<int, array>  $leaves  leave rows (leave_entries columns)
     * @param  array<string, ?bool>  $leaveTypes  leave type code => true (no-pay) | false (paid) | null (not reviewed yet)
     * @param  string  $otSource  'decimal' (overtimeApproved) or 'text' (overtimeApprovedString)
     * @param  ?string  $maxHours  the employee's hours limit for the range, or null for no limit
     * @return array{variables: array<string, string>, info: array<string, mixed>, warnings: string[]}
     */
    public static function calculate(array $days, array $leaves, array $leaveTypes, string $from, string $to, string $otSource = 'decimal', ?string $maxHours = null): array
    {
        Decimal::ensureAvailable();
        $dates = self::dates($from, $to);
        $inRange = array_flip($dates);

        // Usable rows by date. Rows flagged "missing" (MiHCM stopped returning them) are excluded and counted.
        $byDate = [];
        $missingRows = 0;
        foreach ($days as $d) {
            $date = (string) ($d['work_date'] ?? '');
            if (! isset($inRange[$date])) {
                continue;
            }
            if (! empty($d['missing_since'])) {
                $missingRows++;
                continue;
            }
            $byDate[$date] = $d;
        }

        // Leave coverage per date, in days (1, 0.5 ...). Only days MiHCM expects the employee are counted.
        $noPay = $paid = $unreviewed = $pending = [];
        $pendingEntries = $cancelled = $leaveDaysNoData = 0;
        foreach ($leaves as $l) {
            if (! empty($l['missing_since'])) {
                $cancelled++;
                continue;
            }
            $status = (string) ($l['status'] ?? '');
            if ($status !== 'C' && $status !== 'P') {
                continue;
            }
            $start = max((string) $l['start_date'], $from);
            $end = min((string) $l['end_date'], $to);
            if ($start > $end) {
                continue;
            }
            if ($status === 'P') {
                $pendingEntries++;
            }
            $kind = $leaveTypes[$l['leave_type_code'] ?? ''] ?? null;

            foreach (self::dates($start, $end) as $date) {
                $row = $byDate[$date] ?? null;
                if ($row === null) {
                    $leaveDaysNoData++;
                    continue;
                }
                if (! (bool) ($row['expected'] ?? false)) {
                    continue; // rest day / holiday inside a longer leave
                }
                $f = self::fraction($date, $l);
                if ($status === 'P') {
                    $pending[$date] = ($pending[$date] ?? 0) + $f;
                } elseif ($kind === true) {
                    $noPay[$date] = ($noPay[$date] ?? 0) + $f;
                } elseif ($kind === false) {
                    $paid[$date] = ($paid[$date] ?? 0) + $f;
                } else {
                    $unreviewed[$date] = ($unreviewed[$date] ?? 0) + $f;
                }
            }
        }

        $working = $present = $absent = $lwp = $unrevDays = $paidDays = 0.0;
        $worked = $late = $ot = $dot = $tot = $unapprovedMin = 0;
        $unapprovedDays = $conflicts = 0;
        $approvedKey = $otSource === 'text' ? 'approved_text_minutes' : 'approved_minutes';

        foreach ($byDate as $date => $row) {
            $worked += (int) ($row['work_minutes'] ?? 0);
            $late += (int) ($row['late_minutes'] ?? 0);

            if ((bool) ($row['approval_done'] ?? false)) {
                $ot += (int) ($row['ot_'.$approvedKey] ?? 0);
                $dot += (int) ($row['double_ot_'.$approvedKey] ?? 0);
                $tot += (int) ($row['triple_ot_'.$approvedKey] ?? 0);
            } else {
                $raw = (int) ($row['ot_minutes'] ?? 0) + (int) ($row['double_ot_minutes'] ?? 0) + (int) ($row['triple_ot_minutes'] ?? 0);
                if ($raw > 0) {
                    $unapprovedMin += $raw;
                    $unapprovedDays++;
                }
            }

            if (! (bool) ($row['expected'] ?? false)) {
                continue;
            }
            $working++;
            $np = min(1.0, $noPay[$date] ?? 0);
            $other = min(1.0 - $np, ($paid[$date] ?? 0) + ($unreviewed[$date] ?? 0));
            $lwp += $np;
            $unrevDays += min(1.0, $unreviewed[$date] ?? 0);

            if ((bool) ($row['present'] ?? false)) {
                $present++;
                $paidDays += max(0.0, 1.0 - $np); // a present day is paid, less any no-pay leave part of it
                if ($np + $other >= 1.0) {
                    $conflicts++; // present all day AND on leave all day: MiHCM data disagrees with itself
                }
            } else {
                $absent += max(0.0, 1.0 - $np - $other);
                $paidDays += min(1.0 - $np, $paid[$date] ?? 0); // confirmed PAID leave only (unreviewed types are not counted as paid)
            }
        }

        $pendingDays = 0.0;
        foreach ($pending as $f) {
            $pendingDays += min(1.0, $f);
        }

        // Hours limit: ALL hours worked count towards it (overtime included); hours above it are not paid.
        $maxMinutes = $maxHours === null ? null : (int) round((float) Decimal::make($maxHours) * 60);
        $paidMinutes = $maxMinutes === null ? $worked : min($worked, $maxMinutes);

        $info = [
            'days_in_range' => count($dates), 'days_with_data' => count($byDate), 'days_without_data' => count($dates) - count($byDate),
            'pending_leave_days' => self::days($pendingDays), 'pending_leave_entries' => $pendingEntries,
            'unreviewed_leave_days' => self::days($unrevDays),
            'unapproved_ot_hours' => self::hours($unapprovedMin), 'unapproved_ot_days' => $unapprovedDays,
            'hours_over_limit' => self::hours($worked - $paidMinutes),
            'missing_rows' => $missingRows, 'cancelled_leave_entries' => $cancelled,
            'leave_days_without_data' => $leaveDaysNoData, 'leave_present_conflicts' => $conflicts,
        ];

        $warnings = [];
        if ($info['days_without_data'] > 0) {
            $warnings[] = "{$info['days_without_data']} day(s) in this range have no attendance data (not loaded yet, or the employee had not joined).";
        }
        if ($pendingEntries > 0) {
            $warnings[] = "Pending leave of {$info['pending_leave_days']} day(s) is not deducted until it is confirmed in MiHCM.";
        }
        if ($unrevDays > 0) {
            $warnings[] = "{$info['unreviewed_leave_days']} day(s) of confirmed leave are of a type not yet marked paid or no-pay, so nothing was deducted for them.";
        }
        if ($unapprovedDays > 0) {
            $warnings[] = "{$info['unapproved_ot_hours']} hour(s) of overtime on {$unapprovedDays} day(s) are not approved and are not counted.";
        }
        if ($worked > $paidMinutes) {
            $warnings[] = "{$info['hours_over_limit']} hour(s) worked are above the employee's limit of ".Decimal::plain(Decimal::make((string) $maxHours)).' hour(s) and are not paid.';
        }
        if ($missingRows > 0) {
            $warnings[] = "{$missingRows} attendance row(s) are no longer returned by MiHCM and are not counted.";
        }
        if ($cancelled > 0) {
            $warnings[] = "{$cancelled} leave entr".($cancelled === 1 ? 'y' : 'ies').' no longer returned by MiHCM (treated as cancelled) '.($cancelled === 1 ? 'is' : 'are').' not counted.';
        }
        if ($leaveDaysNoData > 0) {
            $warnings[] = "{$leaveDaysNoData} leave day(s) fall on dates with no attendance data and could not be counted.";
        }
        if ($conflicts > 0) {
            $warnings[] = "{$conflicts} day(s) show the employee present and on full-day leave at the same time.";
        }

        return [
            'variables' => [
                'WORKING_DAYS' => self::days($working), 'PRESENT_DAYS' => self::days($present), 'PAID_DAYS' => self::days($paidDays),
                'ABSENT_DAYS' => self::days($absent),
                'LWP_DAYS' => self::days($lwp), 'LATE_HOURS' => self::hours($late),
                'OT_HOURS' => self::hours($ot), 'OT_DOUBLE_HOURS' => self::hours($dot), 'OT_TRIPLE_HOURS' => self::hours($tot),
                'WORKED_HOURS' => self::hours($worked), 'PAID_HOURS' => self::hours($paidMinutes),
                'MAX_HOURS' => $maxHours === null ? '0' : Decimal::plain(Decimal::make($maxHours)),
            ],
            'info' => $info,
            'warnings' => $warnings,
        ];
    }

    /** Day fraction of a leave entry on one date: whole day, or half when it starts in the second half / ends in the first half. */
    private static function fraction(string $date, array $l): float
    {
        $f = 1.0;
        if ($date === $l['start_date'] && ($l['start_session'] ?? '') === 'SH') {
            $f -= 0.5;
        }
        if ($date === $l['end_date'] && ($l['end_session'] ?? '') === 'FH') {
            $f -= 0.5;
        }
        if ($l['start_date'] === $l['end_date'] && (float) ($l['days'] ?? 0) > 0) {
            $f = min($f, (float) $l['days']); // a single day: never more than the days MiHCM gives
        }

        return max(0.0, $f);
    }

    /** @return string[] every date from $from to $to, inclusive (Y-m-d) */
    private static function dates(string $from, string $to): array
    {
        $out = [];
        $d = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        while ($d <= $end) {
            $out[] = $d->format('Y-m-d');
            $d = $d->modify('+1 day');
        }

        return $out;
    }

    private static function days(float $v): string
    {
        return Decimal::plain(number_format($v, 2, '.', ''));
    }

    private static function hours(int $minutes): string
    {
        return Decimal::plain(Decimal::round(Decimal::div((string) $minutes, '60'), 4));
    }
}
