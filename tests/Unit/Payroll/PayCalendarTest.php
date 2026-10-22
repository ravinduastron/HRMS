<?php

namespace Tests\Unit\Payroll;

use App\Payroll\PayCalendar;
use PHPUnit\Framework\TestCase;

class PayCalendarTest extends TestCase
{
    private function casuals(): array
    {
        return [
            'cycles' => [['start' => 1, 'end' => 15], ['start' => 16, 'end' => null]],
            'windows' => array_fill_keys(array_keys(PayCalendar::WINDOWS), ['mode' => 'cycle']),
            'pay_date' => ['mode' => 'days_after', 'min' => 1, 'max' => 7],
        ];
    }

    private function nonExecutive(): array
    {
        return [
            'cycles' => [['start' => 1, 'end' => null]],
            'windows' => [
                'overtime' => ['mode' => 'custom', 'start_day' => 11, 'start_offset' => -1, 'end_day' => 10, 'end_offset' => 0],
                'no_pay' => ['mode' => 'cycle'], 'late' => ['mode' => 'cycle'],
                'attendance_allowance' => ['mode' => 'custom', 'start_day' => 1, 'start_offset' => -1, 'end_day' => null, 'end_offset' => -1],
            ],
            'pay_date' => ['mode' => 'range', 'month_offset' => 0, 'from_day' => 20, 'to_day' => 30],
        ];
    }

    public function testDefaultsAreValid(): void
    {
        $this->assertSame([], PayCalendar::validate(PayCalendar::defaults()));
    }

    public function testMonthlySchemeHasOneCycleCoveringTheMonth(): void
    {
        $p = PayCalendar::periods(PayCalendar::defaults(), 2026, 9);
        $this->assertCount(1, $p);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$p[0]['from'], $p[0]['to']]);
    }

    public function testCasualsHaveTwoCyclesAndTheSecondEndsOnTheLastDay(): void
    {
        $this->assertSame([], PayCalendar::validate($this->casuals()));
        $p = PayCalendar::periods($this->casuals(), 2026, 10);
        $this->assertSame(['2026-10-01', '2026-10-15'], [$p[0]['from'], $p[0]['to']]);
        $this->assertSame(['2026-10-16', '2026-10-31'], [$p[1]['from'], $p[1]['to']]);
        // February, leap year
        $leap = PayCalendar::periods($this->casuals(), 2028, 2);
        $this->assertSame(['2028-02-16', '2028-02-29'], [$leap[1]['from'], $leap[1]['to']]);
        $feb = PayCalendar::periods($this->casuals(), 2027, 2);
        $this->assertSame('2027-02-28', $feb[1]['to']);
    }

    public function testCasualWindowsFollowEachCycle(): void
    {
        $p = PayCalendar::periods($this->casuals(), 2026, 10);
        $this->assertSame(['2026-10-01', '2026-10-15'], $p[0]['windows']['overtime']);
        $this->assertSame(['2026-10-16', '2026-10-31'], $p[1]['windows']['attendance_allowance']);
    }

    public function testNonExecutiveOvertimeAndAttendanceAllowanceWindows(): void
    {
        $this->assertSame([], PayCalendar::validate($this->nonExecutive()));
        $p = PayCalendar::periods($this->nonExecutive(), 2026, 10)[0];
        $this->assertSame(['2026-09-11', '2026-10-10'], $p['windows']['overtime']);
        $this->assertSame(['2026-09-01', '2026-09-30'], $p['windows']['attendance_allowance']);
        $this->assertSame(['2026-10-01', '2026-10-31'], $p['windows']['no_pay']);
    }

    public function testPreviousMonthCrossesTheYearBoundary(): void
    {
        $p = PayCalendar::periods($this->nonExecutive(), 2027, 1)[0];
        $this->assertSame(['2026-12-11', '2027-01-10'], $p['windows']['overtime']);
        $this->assertSame(['2026-12-01', '2026-12-31'], $p['windows']['attendance_allowance']);
    }

    public function testPayDateRangeIsClampedInShortMonths(): void
    {
        $p = PayCalendar::periods($this->nonExecutive(), 2027, 2)[0];
        $this->assertSame(['2027-02-20', '2027-02-28'], $p['pay']);
        $this->assertTrue(PayCalendar::isPayDateAllowed($p, '2027-02-25'));
        $this->assertFalse(PayCalendar::isPayDateAllowed($p, '2027-02-19'));
    }

    public function testCasualPayDateIsWithinAWeekAfterTheCycle(): void
    {
        $p = PayCalendar::periods($this->casuals(), 2026, 10);
        $this->assertSame(['2026-10-16', '2026-10-22'], $p[0]['pay']);
        $this->assertSame(['2026-11-01', '2026-11-07'], $p[1]['pay']);
        $this->assertTrue(PayCalendar::isPayDateAllowed($p[1], '2026-11-01'));
    }

    public function testNextMonthPayDateForInternsAndContract(): void
    {
        $s = PayCalendar::defaults();
        $s['pay_date'] = ['mode' => 'range', 'month_offset' => 1, 'from_day' => 1, 'to_day' => 7];
        $this->assertSame([], PayCalendar::validate($s));
        $this->assertSame(['2027-01-01', '2027-01-07'], PayCalendar::periods($s, 2026, 12)[0]['pay']);
    }

    public function testCyclesMustCoverTheMonthWithoutGapsOrOverlap(): void
    {
        $s = $this->casuals();
        $s['cycles'][1]['start'] = 17;
        $this->assertNotEmpty(PayCalendar::validate($s));

        $s = $this->casuals();
        $s['cycles'][1]['end'] = 28; // last cycle must reach the month end
        $this->assertNotEmpty(PayCalendar::validate($s));

        $s = $this->casuals();
        $s['cycles'][0]['start'] = 2; // must start on day 1
        $this->assertNotEmpty(PayCalendar::validate($s));
    }

    public function testWindowThatEndsBeforeItStartsIsRejected(): void
    {
        $s = PayCalendar::defaults();
        $s['windows']['overtime'] = ['mode' => 'custom', 'start_day' => 20, 'start_offset' => 0, 'end_day' => 10, 'end_offset' => 0];
        $this->assertNotEmpty(PayCalendar::validate($s));
        $s['windows']['overtime'] = ['mode' => 'custom', 'start_day' => 20, 'start_offset' => -1, 'end_day' => 10, 'end_offset' => 0];
        $this->assertSame([], PayCalendar::validate($s));
    }

    public function testBadPayDateIsRejected(): void
    {
        $s = PayCalendar::defaults();
        $s['pay_date'] = ['mode' => 'range', 'month_offset' => 0, 'from_day' => 25, 'to_day' => 20];
        $this->assertNotEmpty(PayCalendar::validate($s));
        $s['pay_date'] = ['mode' => 'days_after', 'min' => 5, 'max' => 2];
        $this->assertNotEmpty(PayCalendar::validate($s));
    }

    public function testStatutoryTiming(): void
    {
        $this->assertTrue(PayCalendar::statutoryApplies('every_cycle', 0, 2));
        $this->assertTrue(PayCalendar::statutoryApplies('every_cycle', 1, 2));
        $this->assertTrue(PayCalendar::statutoryApplies('first_cycle', 0, 2));
        $this->assertFalse(PayCalendar::statutoryApplies('first_cycle', 1, 2));
        $this->assertFalse(PayCalendar::statutoryApplies('last_cycle', 0, 2));
        $this->assertTrue(PayCalendar::statutoryApplies('last_cycle', 1, 2));
    }

    public function testNormalizeTurnsFormStringsIntoSettings(): void
    {
        $s = PayCalendar::normalize([
            'cycles' => [['start' => '1', 'end' => '15'], ['start' => '16', 'end' => '']],
            'windows' => ['overtime' => ['mode' => 'custom', 'start_day' => '11', 'start_offset' => '-1', 'end_day' => '10', 'end_offset' => '0']],
            'pay_date' => ['mode' => 'days_after', 'min' => '1', 'max' => '7'],
        ]);
        $this->assertSame([['start' => 1, 'end' => 15], ['start' => 16, 'end' => null]], $s['cycles']);
        $this->assertSame(['mode' => 'custom', 'start_day' => 11, 'start_offset' => -1, 'end_day' => 10, 'end_offset' => 0], $s['windows']['overtime']);
        $this->assertSame(['mode' => 'cycle'], $s['windows']['no_pay']);
        $this->assertSame(['mode' => 'days_after', 'min' => 1, 'max' => 7], $s['pay_date']);
        $this->assertSame([], PayCalendar::validate($s));
    }

    public function testPlainLanguageDescriptions(): void
    {
        $this->assertSame('1st to 15th', PayCalendar::describeCycle(['start' => 1, 'end' => 15]));
        $this->assertSame('16th to month end', PayCalendar::describeCycle(['start' => 16, 'end' => null]));
        $this->assertSame('11th of the previous month to 10th of the same month', PayCalendar::describeWindow($this->nonExecutive()['windows']['overtime']));
        $this->assertSame('1st to month end of the previous month', PayCalendar::describeWindow($this->nonExecutive()['windows']['attendance_allowance']));
        $this->assertSame('Between the 20th and 30th of the cycle month', PayCalendar::describePayDate($this->nonExecutive()['pay_date']));
        $this->assertSame('22nd', PayCalendar::ordinal(22));
        $this->assertSame('11th', PayCalendar::ordinal(11));
    }
}
