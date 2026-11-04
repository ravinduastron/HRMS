<?php

namespace Tests\Unit\Payroll;

use App\Payroll\PayCalendar;
use App\Payroll\PayPeriodPlan;
use PHPUnit\Framework\TestCase;

class PayPeriodPlanTest extends TestCase
{
    public function test_tax_year_has_twelve_months_april_to_march(): void
    {
        $m = PayPeriodPlan::taxYearMonths(2026, 4);

        $this->assertCount(12, $m);
        $this->assertSame([2026, 4], $m[0]);
        $this->assertSame([2026, 12], $m[8]);
        $this->assertSame([2027, 1], $m[9]);
        $this->assertSame([2027, 3], $m[11]);
    }

    public function test_monthly_scheme_gives_one_period_for_the_whole_month(): void
    {
        $rows = PayPeriodPlan::forMonth(PayCalendar::defaults(), 'every_cycle', 2026, 11);

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['cycle_no']);
        $this->assertSame('2026-11-01', $rows[0]['period_start']);
        $this->assertSame('2026-11-30', $rows[0]['period_end']);
        $this->assertSame(['2026-11-01', '2026-11-30'], $rows[0]['windows']['overtime']);
        $this->assertSame(['2026-11-20', '2026-11-30'], [$rows[0]['pay_date_from'], $rows[0]['pay_date_to']]);
        $this->assertTrue($rows[0]['statutory_applies']);
    }

    public function test_two_cycles_cover_february_and_statutory_only_in_the_first(): void
    {
        $cal = PayCalendar::defaults();
        $cal['cycles'] = [['start' => 1, 'end' => 15], ['start' => 16, 'end' => null]];

        $rows = PayPeriodPlan::forMonth($cal, 'first_cycle', 2027, 2);

        $this->assertCount(2, $rows);
        $this->assertSame(['2027-02-01', '2027-02-15'], [$rows[0]['period_start'], $rows[0]['period_end']]);
        $this->assertSame(['2027-02-16', '2027-02-28'], [$rows[1]['period_start'], $rows[1]['period_end']]);
        $this->assertSame([true, false], array_column($rows, 'statutory_applies'));
    }

    public function test_custom_window_counts_from_the_previous_month(): void
    {
        $cal = PayCalendar::defaults();
        $cal['windows']['overtime'] = ['mode' => 'custom', 'start_day' => 11, 'start_offset' => -1, 'end_day' => 10, 'end_offset' => 0];

        $rows = PayPeriodPlan::forMonth($cal, 'every_cycle', 2026, 11);

        $this->assertSame(['2026-10-11', '2026-11-10'], $rows[0]['windows']['overtime']);
        $this->assertSame(['2026-11-01', '2026-11-30'], $rows[0]['windows']['late']);
    }
}
