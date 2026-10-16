<?php

namespace App\Services\Mihcm;

use DateTimeImmutable;
use Throwable;

/**
 * MiHCM attendance row => attendance_records columns. The ONLY place that knows the attendance field names.
 * No framework dependencies, so it is unit-testable on its own. Times are stored as whole minutes.
 *
 * Units, from the sample data: "workhours" / "*String" fields are HH:MM text ("10:31" = 10 h 31 min);
 * the decimal fields (overtime, overtimeApproved...) are real decimal hours (3.5 = 3 h 30 min).
 * `workHrs` is hours.minutes (10.31), NOT decimal hours, so it is used only if "workhours" is absent.
 */
final class AttendanceMapper
{
    /** Value columns written to attendance_records (ids and load bookkeeping are added by the loader). */
    public const COLUMNS = [
        'employee_no', 'work_date', 'expected', 'present', 'midnight', 'lieu', 'day_off', 'day_type', 'shift_code',
        'in_time', 'out_time', 'in_time_2', 'out_time_2', 'work_minutes', 'late_minutes',
        'ot_minutes', 'ot_approved_minutes', 'ot_approved_text_minutes',
        'double_ot_minutes', 'double_ot_approved_minutes', 'double_ot_approved_text_minutes',
        'triple_ot_minutes', 'triple_ot_approved_minutes', 'triple_ot_approved_text_minutes',
        'leave_name', 'leave_code', 'leave_amount', 'short_leave_amount', 'approval_done', 'approval_status',
    ];

    public function __construct(private string $numberField = 'number', private ?int $pad = null) {}

    /** @return array<string, scalar|null>|null null when the row has no usable employee number or date */
    public function map(array $r): ?array
    {
        $no = $this->employeeNo($r[$this->numberField] ?? null);
        $date = $this->date($r['date'] ?? null);
        if ($no === null || $date === null) {
            return null;
        }

        return [
            'employee_no' => $no,
            'work_date'   => $date,
            'expected'    => $this->bool($r['expected'] ?? false),
            'present'     => $this->bool($r['present'] ?? false),
            'midnight'    => $this->bool($r['midnight'] ?? false),
            'lieu'        => $this->bool($r['lieu'] ?? false),
            'day_off'     => $this->bool($r['dayOff'] ?? false),
            'day_type'    => $this->int($r['dayType'] ?? null),
            'shift_code'  => $this->int($r['shiftCode'] ?? null),

            'in_time'    => $this->dateTime($r['inTime'] ?? null),
            'out_time'   => $this->dateTime($r['outTime'] ?? null),
            'in_time_2'  => $this->dateTime($r['inTime2'] ?? null),
            'out_time_2' => $this->dateTime($r['outTime2'] ?? null),

            'work_minutes' => array_key_exists('workhours', $r) ? $this->hm($r['workhours']) : $this->hoursDotMinutes($r['workHrs'] ?? null),
            'late_minutes' => $this->hm($r['lateString'] ?? null),

            'ot_minutes'                      => $this->preferText($r, 'overtimeString', 'overtime'),
            'ot_approved_minutes'             => $this->decimalHours($r['overtimeApproved'] ?? null),
            'ot_approved_text_minutes'        => $this->hm($r['overtimeApprovedString'] ?? null),
            'double_ot_minutes'               => $this->preferText($r, 'doubleOTString', 'doubleOT'),
            'double_ot_approved_minutes'      => $this->decimalHours($r['doubleOTApproved'] ?? null),
            'double_ot_approved_text_minutes' => $this->hm($r['doubleOTApprovedString'] ?? null),
            'triple_ot_minutes'               => $this->preferText($r, 'tripleOTString', 'tripleOT'),
            'triple_ot_approved_minutes'      => $this->decimalHours($r['tripleOTApproved'] ?? null),
            'triple_ot_approved_text_minutes' => $this->hm($r['tripleOTApprovedString'] ?? null),

            'leave_name'         => $this->text($r['leaveType'] ?? null, 100),
            'leave_code'         => $this->int($r['leaveTypeCode'] ?? null),
            'leave_amount'       => $this->amount($r['leave_Amount'] ?? null),
            'short_leave_amount' => $this->amount($r['shortLeaveAmount'] ?? null),

            'approval_done'   => $this->bool($r['approvalDone'] ?? false),
            'approval_status' => $this->text($r['status_02'] ?? null, 30),
        ];
    }

    /** Stable fingerprint of a mapped row (used to detect changes between loads). */
    public function hash(array $mapped): string
    {
        $values = [];
        foreach (self::COLUMNS as $c) {
            $values[$c] = $mapped[$c] ?? null;
        }

        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE));
    }

    /* ------------------------------ helpers ------------------------------ */

    private function employeeNo(mixed $v): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if ($this->pad && ctype_digit($v)) {
            $v = str_pad($v, $this->pad, '0', STR_PAD_LEFT);
        }

        return mb_substr($v, 0, 30);
    }

    private function date(mixed $v): ?string
    {
        if (! is_string($v) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || (int) $m[1] <= 1901) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    private function dateTime(mixed $v): ?string
    {
        if (! is_string($v) || trim($v) === '') {
            return null;
        }
        try {
            $d = new DateTimeImmutable($v);
        } catch (Throwable) {
            return null;
        }

        return (int) $d->format('Y') <= 1901 ? null : $d->format('Y-m-d H:i:s'); // 1901-01-01 means "no value"
    }

    /** "HH:MM" => minutes (0 when empty or invalid) */
    private function hm(mixed $v): int
    {
        if (is_string($v) && preg_match('/^\s*(\d+):(\d{2})\s*$/', $v, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        return 0;
    }

    /** 3.5 (decimal hours) => 210 */
    private function decimalHours(mixed $v): int
    {
        return is_numeric($v) && $v > 0 ? (int) round((float) $v * 60) : 0;
    }

    /** 10.31 (hours.minutes) => 631 */
    private function hoursDotMinutes(mixed $v): int
    {
        if (! is_numeric($v) || $v <= 0) {
            return 0;
        }
        $h = (int) floor((float) $v);

        return $h * 60 + (int) round(((float) $v - $h) * 100);
    }

    /** The HH:MM text when the row has one, otherwise the decimal hours. */
    private function preferText(array $r, string $textKey, string $decimalKey): int
    {
        return isset($r[$textKey]) && is_string($r[$textKey]) && preg_match('/^\s*\d+:\d{2}\s*$/', $r[$textKey])
            ? $this->hm($r[$textKey])
            : $this->decimalHours($r[$decimalKey] ?? null);
    }

    private function amount(mixed $v): ?string
    {
        return is_numeric($v) ? number_format((float) $v, 2, '.', '') : null;
    }

    private function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    private function bool(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    private function text(mixed $v, int $max): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
