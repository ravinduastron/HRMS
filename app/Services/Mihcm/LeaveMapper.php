<?php

namespace App\Services\Mihcm;

use DateTimeImmutable;
use Throwable;

/**
 * MiHCM leave row => leave_entries columns. The ONLY place that knows the leave field names.
 * leaveReason and remark are deliberately ignored (they can hold medical or personal details).
 */
final class LeaveMapper
{
    public const COLUMNS = [
        'employee_no', 'leave_type_code', 'leave_type_name', 'start_date', 'end_date', 'start_session', 'end_session',
        'days', 'status', 'requested_at',
    ];

    /** @return array<string, scalar|null>|null null when the row lacks its entry code, employee number or dates */
    public function map(array $r): ?array
    {
        $code = $r['leaveEntryCode'] ?? null;
        $no = isset($r['employeeNumber']) && ! is_array($r['employeeNumber']) ? trim((string) $r['employeeNumber']) : '';
        $start = $this->date($r['startDate'] ?? null);
        $end = $this->date($r['endDate'] ?? null) ?? $start;

        if (! is_numeric($code) || $no === '' || $start === null || $end === null) {
            return null;
        }

        $name = trim((string) ($r['leaveType'] ?? ''));
        $typeCode = strtoupper(trim((string) ($r['sysLeaveTypeCode'] ?? '')));
        if ($typeCode === '') {
            $typeCode = strtoupper(substr(preg_replace('/\W+/', '_', $name) ?: 'UNKNOWN', 0, 20)); // fall back to the name
        }

        return [
            'leave_entry_code' => (int) $code,
            'employee_no'      => mb_substr($no, 0, 30),
            'leave_type_code'  => substr($typeCode, 0, 20),
            'leave_type_name'  => mb_substr($name !== '' ? $name : $typeCode, 0, 100),
            'start_date'       => $start,
            'end_date'         => $end,
            'start_session'    => $this->session($r['startDateSession'] ?? null),
            'end_session'      => $this->session($r['endDateSession'] ?? null),
            'days'             => number_format(is_numeric($r['noOfDays'] ?? null) ? (float) $r['noOfDays'] : 0, 2, '.', ''),
            'status'           => strtoupper(substr(trim((string) ($r['requestStatus'] ?? '')), 0, 2)),
            'requested_at'     => $this->dateTime($r['requestedDate'] ?? null),
        ];
    }

    public function hash(array $mapped): string
    {
        $values = [];
        foreach (self::COLUMNS as $c) {
            $values[$c] = $mapped[$c] ?? null;
        }

        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE));
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

        return (int) $d->format('Y') <= 1901 ? null : $d->format('Y-m-d H:i:s');
    }

    private function session(mixed $v): ?string
    {
        $s = strtoupper(substr(trim((string) $v), 0, 2));

        return $s === '' ? null : $s;
    }
}
