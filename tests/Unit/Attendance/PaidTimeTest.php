<?php

namespace Tests\Unit\Attendance;

use App\Payroll\AttendanceSummary;
use PHPUnit\Framework\TestCase;

class PaidTimeTest extends TestCase
{
    private function day(string $date, bool $present, int $minutes = 0, bool $expected = true): array
    {
        return ['work_date' => $date, 'expected' => $expected, 'present' => $present, 'work_minutes' => $minutes, 'late_minutes' => 0,
            'ot_minutes' => 0, 'double_ot_minutes' => 0, 'triple_ot_minutes' => 0, 'approval_done' => true];
    }

    private function leave(string $date, string $type, string $status = 'C'): array
    {
        return ['start_date' => $date, 'end_date' => $date, 'status' => $status, 'leave_type_code' => $type, 'start_session' => null, 'end_session' => null, 'days' => '1.00'];
    }

    public function testHoursAboveTheLimitAreNotPaid(): void
    {
        // 120 hours worked (3 x 40), limit 100
        $days = [$this->day('2026-09-01', true, 2400), $this->day('2026-09-02', true, 2400), $this->day('2026-09-03', true, 2400)];
        $r = AttendanceSummary::calculate($days, [], [], '2026-09-01', '2026-09-03', 'decimal', '100');

        $this->assertSame('120', $r['variables']['WORKED_HOURS']);
        $this->assertSame('100', $r['variables']['PAID_HOURS']);
        $this->assertSame('100', $r['variables']['MAX_HOURS']);
        $this->assertSame('20', $r['info']['hours_over_limit']);
        $this->assertNotEmpty(array_filter($r['warnings'], fn ($w) => str_contains($w, 'not paid')));
    }

    public function testLimitIsNotReachedSoEverythingIsPaid(): void
    {
        $days = [$this->day('2026-09-01', true, 480), $this->day('2026-09-02', true, 450)]; // 15.5 h
        $r = AttendanceSummary::calculate($days, [], [], '2026-09-01', '2026-09-02', 'decimal', '100.00');
        $this->assertSame('15.5', $r['variables']['PAID_HOURS']);
        $this->assertSame('0', $r['info']['hours_over_limit']);
        $this->assertSame('100', $r['variables']['MAX_HOURS']);
    }

    public function testNoLimitMeansPaidHoursEqualWorkedHours(): void
    {
        $days = [$this->day('2026-09-01', true, 600)];
        $r = AttendanceSummary::calculate($days, [], [], '2026-09-01', '2026-09-01');
        $this->assertSame('10', $r['variables']['PAID_HOURS']);
        $this->assertSame('0', $r['variables']['MAX_HOURS']);
        $this->assertEmpty(array_filter($r['warnings'], fn ($w) => str_contains($w, 'not paid')));
    }

    public function testPaidDaysAreDaysPresentPlusConfirmedPaidLeave(): void
    {
        $days = [$this->day('2026-09-01', true, 480), $this->day('2026-09-02', false), $this->day('2026-09-03', false)];
        $leaves = [$this->leave('2026-09-02', 'AL')];
        $r = AttendanceSummary::calculate($days, $leaves, ['AL' => false], '2026-09-01', '2026-09-03');

        $this->assertSame('2', $r['variables']['PAID_DAYS']);
        $this->assertSame('1', $r['variables']['PRESENT_DAYS']);
        $this->assertSame('1', $r['variables']['ABSENT_DAYS']);
    }

    public function testNoPayPendingAndUnreviewedLeaveAreNotPaidDays(): void
    {
        $days = [$this->day('2026-09-01', false), $this->day('2026-09-02', false), $this->day('2026-09-03', false)];
        $leaves = [$this->leave('2026-09-01', 'NP'), $this->leave('2026-09-02', 'AL', 'P'), $this->leave('2026-09-03', 'XX')];
        $r = AttendanceSummary::calculate($days, $leaves, ['NP' => true, 'AL' => false, 'XX' => null], '2026-09-01', '2026-09-03');

        $this->assertSame('0', $r['variables']['PAID_DAYS']);
        $this->assertSame('1', $r['variables']['LWP_DAYS']);
    }

    public function testHalfDayPaidLeaveCountsHalf(): void
    {
        $days = [$this->day('2026-09-01', false)];
        $leaves = [['start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'status' => 'C', 'leave_type_code' => 'AL', 'start_session' => 'SH', 'end_session' => 'SH', 'days' => '0.50']];
        $r = AttendanceSummary::calculate($days, $leaves, ['AL' => false], '2026-09-01', '2026-09-01');
        $this->assertSame('0.5', $r['variables']['PAID_DAYS']);
    }

    public function testDaysMiHcmDoesNotExpectAreNotPaidDays(): void
    {
        $days = [$this->day('2026-09-05', true, 300, false)];
        $r = AttendanceSummary::calculate($days, [], [], '2026-09-05', '2026-09-05');
        $this->assertSame('0', $r['variables']['PAID_DAYS']);
        $this->assertSame('5', $r['variables']['PAID_HOURS']); // but the hours were worked
    }
}
