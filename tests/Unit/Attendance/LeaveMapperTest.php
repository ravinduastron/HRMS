<?php

namespace Tests\Unit\Attendance;

use App\Services\Mihcm\LeaveMapper;
use PHPUnit\Framework\TestCase;

class LeaveMapperTest extends TestCase
{
    private function sample(array $over = []): array
    {
        return $over + [
            'leaveEntryCode' => 12780, 'employeeNumber' => '00427', 'requestedDate' => '2026-10-12T08:30:32.07',
            'leaveType' => 'Sick Leave', 'leaveReason' => 'private reason', 'startDate' => '2026-10-06T00:00:00', 'endDate' => '2026-10-09T00:00:00',
            'noOfDays' => 4.0, 'startDateSession' => 'FH', 'endDateSession' => 'SH', 'remark' => 'private remark',
            'sysLeaveTypeCode' => 'MED', 'requestStatus' => 'P',
        ];
    }

    public function test_maps_a_pending_entry(): void
    {
        $m = (new LeaveMapper)->map($this->sample());

        $this->assertSame(12780, $m['leave_entry_code']);
        $this->assertSame('00427', $m['employee_no']);          // kept exactly as sent, never re-padded
        $this->assertSame('MED', $m['leave_type_code']);
        $this->assertSame('P', $m['status']);
        $this->assertSame('4.00', $m['days']);
        $this->assertSame('2026-10-06', $m['start_date']);
        $this->assertSame('SH', $m['end_session']);
    }

    public function test_reason_and_remark_are_never_stored(): void
    {
        $m = (new LeaveMapper)->map($this->sample());

        $this->assertNotContains('private reason', $m);
        $this->assertNotContains('private remark', $m);
    }

    public function test_half_days_and_missing_data(): void
    {
        $mapper = new LeaveMapper;

        $this->assertSame('0.50', $mapper->map($this->sample(['noOfDays' => 0.5, 'requestStatus' => 'C']))['days']);
        $this->assertNull($mapper->map(['employeeNumber' => '1']));
        $this->assertSame('SICK_LEAVE', $mapper->map($this->sample(['sysLeaveTypeCode' => '']))['leave_type_code']);
    }
}
