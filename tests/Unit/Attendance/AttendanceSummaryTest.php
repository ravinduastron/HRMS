<?php

namespace Tests\Unit\Attendance;

use App\Payroll\AttendanceSummary;
use PHPUnit\Framework\TestCase;

/** Run: php artisan test tests/Unit/Attendance (needs the bcmath extension) */
class AttendanceSummaryTest extends TestCase
{
    private function day(string $date, array $o = []): array
    {
        return $o + [
            'work_date' => $date, 'expected' => true, 'present' => true, 'approval_done' => true, 'missing_since' => null,
            'work_minutes' => 0, 'late_minutes' => 0, 'ot_minutes' => 0, 'ot_approved_minutes' => 0, 'ot_approved_text_minutes' => 0,
            'double_ot_minutes' => 0, 'double_ot_approved_minutes' => 0, 'double_ot_approved_text_minutes' => 0,
            'triple_ot_minutes' => 0, 'triple_ot_approved_minutes' => 0, 'triple_ot_approved_text_minutes' => 0,
        ];
    }

    private function leave(string $start, string $end, string $code, string $status = 'C', array $o = []): array
    {
        return $o + [
            'leave_entry_code' => 1, 'start_date' => $start, 'end_date' => $end, 'start_session' => 'FH', 'end_session' => 'SH',
            'days' => 1.0, 'status' => $status, 'leave_type_code' => $code, 'missing_since' => null,
        ];
    }

    public function test_counts_days_and_converts_minutes_to_decimal_hours(): void
    {
        $days = [
            $this->day('2026-09-01', ['work_minutes' => 631, 'late_minutes' => 15]),
            $this->day('2026-09-02', ['work_minutes' => 480]),
            $this->day('2026-09-03', ['expected' => false, 'present' => false]),
            $this->day('2026-09-04', ['present' => false]),
        ];

        $r = AttendanceSummary::calculate($days, [], [], '2026-09-01', '2026-09-04');
        $v = $r['variables'];

        $this->assertSame('3', $v['WORKING_DAYS']);
        $this->assertSame('2', $v['PRESENT_DAYS']);
        $this->assertSame('1', $v['ABSENT_DAYS']);      // expected, not present, no leave
        $this->assertSame('0.25', $v['LATE_HOURS']);
        $this->assertSame('18.5167', $v['WORKED_HOURS']); // 1111 minutes
        $this->assertSame(0, $r['info']['days_without_data']);
    }

    public function test_only_days_with_approval_done_count_for_overtime_and_the_source_is_a_setting(): void
    {
        $days = [
            $this->day('2026-09-01', ['ot_minutes' => 211, 'ot_approved_minutes' => 210, 'ot_approved_text_minutes' => 0, 'double_ot_approved_minutes' => 60, 'double_ot_approved_text_minutes' => 60]),
            $this->day('2026-09-02', ['approval_done' => false, 'ot_minutes' => 120, 'ot_approved_minutes' => 120]),
        ];

        $dec = AttendanceSummary::calculate($days, [], [], '2026-09-01', '2026-09-02', 'decimal');
        $txt = AttendanceSummary::calculate($days, [], [], '2026-09-01', '2026-09-02', 'text');

        $this->assertSame('3.5', $dec['variables']['OT_HOURS']);        // day 2 is not approved: not counted
        $this->assertSame('1', $dec['variables']['OT_DOUBLE_HOURS']);
        $this->assertSame('0', $txt['variables']['OT_HOURS']);
        $this->assertSame('2', $dec['info']['unapproved_ot_hours']);
        $this->assertSame(1, $dec['info']['unapproved_ot_days']);
        $this->assertNotEmpty($dec['warnings']);
    }

    public function test_confirmed_no_pay_leave_is_deducted_but_pending_and_unreviewed_leave_are_not(): void
    {
        $days = [$this->day('2026-09-01', ['present' => false]), $this->day('2026-09-02', ['present' => false]), $this->day('2026-09-03', ['present' => false]), $this->day('2026-09-04', ['present' => false])];
        $leaves = [
            $this->leave('2026-09-01', '2026-09-01', 'LWP'),                                     // confirmed no-pay
            $this->leave('2026-09-02', '2026-09-02', 'LWP', 'P'),                                // pending no-pay: not deducted
            $this->leave('2026-09-03', '2026-09-03', 'NEW'),                                     // confirmed, type not reviewed: not deducted
        ];

        $r = AttendanceSummary::calculate($days, $leaves, ['LWP' => true, 'ANN' => false, 'NEW' => null], '2026-09-01', '2026-09-04');

        $this->assertSame('1', $r['variables']['LWP_DAYS']);
        $this->assertSame('1', $r['info']['pending_leave_days']);
        $this->assertSame('1', $r['info']['unreviewed_leave_days']);
        $this->assertSame('2', $r['variables']['ABSENT_DAYS']);   // 09-02 (pending) and 09-04 (no leave); 09-03 is covered by confirmed leave
    }

    public function test_paid_leave_is_not_absence_and_half_days_count_half(): void
    {
        $days = [$this->day('2026-09-01', ['present' => false]), $this->day('2026-09-02', ['present' => false]), $this->day('2026-09-03', ['present' => true])];
        $leaves = [
            $this->leave('2026-09-01', '2026-09-01', 'ANN'),
            $this->leave('2026-09-02', '2026-09-02', 'LWP', 'C', ['start_session' => 'FH', 'end_session' => 'FH', 'days' => 0.5, 'leave_entry_code' => 2]),
        ];

        $r = AttendanceSummary::calculate($days, $leaves, ['LWP' => true, 'ANN' => false], '2026-09-01', '2026-09-03');

        $this->assertSame('0.5', $r['variables']['LWP_DAYS']);
        $this->assertSame('0.5', $r['variables']['ABSENT_DAYS']);  // 09-02: half day on no-pay leave, the other half absent
    }

    public function test_leave_spanning_two_months_is_clipped_to_the_range_and_skips_rest_days(): void
    {
        // Leave 2026-03-26 to 2026-04-09; the range is April only. 04-04 and 04-05 are rest days.
        $days = [];
        foreach (['01', '02', '03', '04', '05', '06', '07', '08', '09', '10'] as $d) {
            $days[] = $this->day("2026-04-{$d}", ['present' => false, 'expected' => ! in_array($d, ['04', '05'], true)]);
        }
        $leaves = [$this->leave('2026-03-26', '2026-04-09', 'MED', 'C', ['days' => 10.0])];

        $r = AttendanceSummary::calculate($days, $leaves, ['MED' => true], '2026-04-01', '2026-04-10');

        $this->assertSame('7', $r['variables']['LWP_DAYS']);      // 04-01,02,03,06,07,08,09
        $this->assertSame('1', $r['variables']['ABSENT_DAYS']);   // 04-10: no leave
    }

    public function test_rows_and_leave_that_mihcm_stopped_returning_are_excluded_and_reported(): void
    {
        $days = [$this->day('2026-09-01'), $this->day('2026-09-02', ['missing_since' => '2026-09-10 08:00:00'])];
        $leaves = [$this->leave('2026-09-01', '2026-09-01', 'LWP', 'C', ['missing_since' => '2026-09-10 08:00:00'])];

        $r = AttendanceSummary::calculate($days, $leaves, ['LWP' => true], '2026-09-01', '2026-09-02');

        $this->assertSame('1', $r['variables']['WORKING_DAYS']);
        $this->assertSame('0', $r['variables']['LWP_DAYS']);
        $this->assertSame(1, $r['info']['missing_rows']);
        $this->assertSame(1, $r['info']['cancelled_leave_entries']);
        $this->assertSame(1, $r['info']['days_without_data']);
    }

    public function test_days_without_data_are_reported_not_guessed(): void
    {
        $r = AttendanceSummary::calculate([$this->day('2026-09-01')], [], [], '2026-09-01', '2026-09-03');

        $this->assertSame(2, $r['info']['days_without_data']);
        $this->assertSame('1', $r['variables']['WORKING_DAYS']);
    }
}
