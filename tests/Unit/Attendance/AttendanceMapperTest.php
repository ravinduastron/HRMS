<?php

namespace Tests\Unit\Attendance;

use App\Services\Mihcm\AttendanceMapper;
use PHPUnit\Framework\TestCase;

/** Built from the MiHCM sample rows. Run: php artisan test tests/Unit/Attendance */
class AttendanceMapperTest extends TestCase
{
    private function sample(array $over = []): array
    {
        return $over + [
            'date' => '2026-09-03T00:00:00', 'number' => '100', 'textCardNumber' => '100', 'workhours' => '10:31', 'workHrs' => 10.31,
            'leaveType' => 'Short Leave', 'leave_Amount' => 2, 'shiftCode' => 2, 'dayType' => 1, 'expected' => true, 'present' => true, 'midnight' => false,
            'inTime' => '2026-09-03T09:30:00', 'outTime' => '2026-09-03T20:01:00', 'inTime2' => '1901-01-01T00:00:00', 'outTime2' => '1901-01-01T00:00:00',
            'late' => 0, 'lateString' => '00:00', 'overtime' => 3.51666665, 'overtimeString' => '03:31',
            'overtimeApproved' => 3.5, 'overtimeApprovedString' => '00:00', 'approvalDone' => true, 'status_02' => 'Approved',
            'doubleOT' => 0, 'doubleOTString' => '00:00', 'doubleOTApproved' => 0, 'doubleOTApprovedString' => '00:00',
            'tripleOT' => 0, 'tripleOTString' => '00:00', 'tripleOTApproved' => 0, 'tripleOTApprovedString' => '00:00',
            'leaveTypeCode' => 0, 'shortLeaveAmount' => 2, 'lieu' => false, 'dayOff' => false,
        ];
    }

    public function test_times_are_whole_minutes_and_hh_mm_is_not_decimal(): void
    {
        $m = (new AttendanceMapper)->map($this->sample());

        $this->assertSame(631, $m['work_minutes']);   // "10:31" = 10 h 31 min (workHrs 10.31 is hours.minutes)
        $this->assertSame(211, $m['ot_minutes']);     // "03:31"
        $this->assertSame(0, $m['late_minutes']);
        $this->assertSame('2026-09-03 09:30:00', $m['in_time']);
        $this->assertNull($m['in_time_2']);           // 1901-01-01 means "no value"
    }

    public function test_both_approved_overtime_values_are_kept_because_the_sample_disagrees(): void
    {
        $m = (new AttendanceMapper)->map($this->sample());

        $this->assertSame(210, $m['ot_approved_minutes']);      // overtimeApproved 3.5 h
        $this->assertSame(0, $m['ot_approved_text_minutes']);   // overtimeApprovedString "00:00"
        $this->assertTrue($m['approval_done']);
    }

    public function test_employee_number_field_and_padding_are_configurable(): void
    {
        $row = $this->sample(['number' => '100', 'textCardNumber' => '236']);

        $this->assertSame('100', (new AttendanceMapper)->map($row)['employee_no']);
        $this->assertSame('236', (new AttendanceMapper('textCardNumber'))->map($row)['employee_no']);
        $this->assertSame('00100', (new AttendanceMapper('number', 5))->map($row)['employee_no']);
    }

    public function test_unusable_rows_are_rejected_and_blank_values_become_null(): void
    {
        $mapper = new AttendanceMapper;

        $this->assertNull($mapper->map(['date' => '2026-09-03T00:00:00']));
        $this->assertNull($mapper->map(['number' => '100']));
        $this->assertNull($mapper->map($this->sample(['leaveType' => ' ']))['leave_name']);
        $this->assertSame(761, $mapper->map(['number' => '1', 'date' => '2026-09-03T00:00:00', 'workHrs' => 12.41])['work_minutes']);
    }

    public function test_hash_is_stable_and_changes_with_the_data(): void
    {
        $mapper = new AttendanceMapper;
        $a = $mapper->map($this->sample());

        $this->assertSame($mapper->hash($a), $mapper->hash($mapper->map($this->sample())));
        $this->assertNotSame($mapper->hash($a), $mapper->hash($mapper->map($this->sample(['present' => false]))));
        $this->assertSame(AttendanceMapper::COLUMNS, array_keys($a));
    }
}
