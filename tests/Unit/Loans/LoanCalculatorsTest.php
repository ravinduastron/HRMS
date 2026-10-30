<?php

namespace Tests\Unit\Loans;

use App\Payroll\FormulaException;
use App\Payroll\Loans\LoanFairValue;
use App\Payroll\Loans\LoanSchedule;
use App\Payroll\Loans\LoanSettlement;
use PHPUnit\Framework\TestCase;

class LoanCalculatorsTest extends TestCase
{
    public function testStraightLine(): void
    {
        $rows = LoanSchedule::build('straight_line', '120000', '12', 12);
        $this->assertSame(12, count($rows));
        $this->assertSame('10000.00', $rows[0]['principal']);
        $this->assertSame('1200.00', $rows[0]['interest']);
        $this->assertSame('11200.00', $rows[5]['total']);
        $this->assertSame('0.00', $rows[11]['balance']);
        $t = LoanSchedule::totals($rows);
        $this->assertSame('120000.00', $t['principal']);
        $this->assertSame('14400.00', $t['interest']);
    }

    public function testDecliningBalance(): void
    {
        $rows = LoanSchedule::build('declining', '120000', '12', 12);
        $this->assertSame('10000.00', $rows[0]['principal']);
        $this->assertSame('1200.00', $rows[0]['interest']);
        $this->assertSame('11200.00', $rows[0]['total']);
        $this->assertSame('1100.00', $rows[1]['interest']);
        $this->assertSame('11100.00', $rows[1]['total']);
        $this->assertSame('100.00', $rows[11]['interest']);
        $this->assertSame('10100.00', $rows[11]['total']);
        $this->assertSame('7800.00', LoanSchedule::totals($rows)['interest']);
    }

    public function testEmi(): void
    {
        $rows = LoanSchedule::build('emi', '100000', '12', 12);
        $this->assertSame('8884.88', $rows[0]['total']);
        $this->assertSame('1000.00', $rows[0]['interest']);
        $this->assertSame('7884.88', $rows[0]['principal']);
        $this->assertSame('8884.88', $rows[10]['total']);
        $t = LoanSchedule::totals($rows);
        $this->assertSame('100000.00', $t['principal']);
        $this->assertSame('0.00', $rows[11]['balance']);
        // the last installment absorbs rounding: within a few cents of the others
        $this->assertTrue(abs((float) $rows[11]['total'] - 8884.88) < 0.5);
    }

    public function testNoInterestWhateverTheMethod(): void
    {
        foreach (['straight_line', 'declining', 'emi'] as $m) {
            $rows = LoanSchedule::build($m, '100000', '0', 3);
            $this->assertSame('33333.33', $rows[0]['principal']);
            $this->assertSame('33333.34', $rows[2]['principal']); // last absorbs the cent
            $this->assertSame('0.00', LoanSchedule::totals($rows)['interest']);
            $this->assertSame('100000.00', LoanSchedule::totals($rows)['principal']);
        }
    }

    public function testStraightLineInterestBaseForAnImportedLoan(): void
    {
        // 60,000 still owed over 6 months, but the flat interest is charged on the original 120,000 at 12%: 1,200 a month
        $rows = LoanSchedule::build('straight_line', '60000', '12', 6, 2, 'half_up', '120000');
        $this->assertSame('1200.00', $rows[0]['interest']);
        $this->assertSame('11200.00', $rows[0]['total']);
        $this->assertSame('7200.00', LoanSchedule::totals($rows)['interest']);
    }

    public function testOneInstallmentAndBadInput(): void
    {
        $rows = LoanSchedule::build('declining', '5000', '12', 1);
        $this->assertSame('5000.00', $rows[0]['principal']);
        $this->assertSame('50.00', $rows[0]['interest']);
        $this->expectException(FormulaException::class);
        LoanSchedule::build('weird', '1', '1', 1);
    }

    public function testChangingTheRateOfARunningLoan(): void
    {
        // 70,000 outstanding with 7 installments left: re-rated from 12% to 6% on a declining loan
        $old = LoanSchedule::build('declining', '70000', '12', 7);
        $new = LoanSchedule::build('declining', '70000', '6', 7);
        $this->assertSame('10000.00', $new[0]['principal']);
        $this->assertSame('350.00', $new[0]['interest']);
        $this->assertTrue((float) LoanSchedule::totals($new)['interest'] < (float) LoanSchedule::totals($old)['interest']);
        $this->assertSame('70000.00', LoanSchedule::totals($new)['principal']);
    }

    private function pending(): array
    {
        return [
            ['due' => '2026-11-30', 'principal' => '1000', 'interest' => '30'],
            ['due' => '2026-12-31', 'principal' => '1000', 'interest' => '20'],
            ['due' => '2027-01-31', 'principal' => '1000', 'interest' => '10'],
        ];
    }

    public function testSettlementFullInterest(): void
    {
        $q = LoanSettlement::quote($this->pending(), 'full', '2026-11-15', '2026-10-31');
        $this->assertSame('3000.00', $q['principal']);
        $this->assertSame('60.00', $q['interest']);
        $this->assertSame('3060.00', $q['total']);
    }

    public function testSettlementAccruedInterest(): void
    {
        $q = LoanSettlement::quote($this->pending(), 'accrued', '2026-11-15', '2026-10-31');
        $this->assertSame('15.00', $q['interest']);   // 15 of 30 days of the first installment
        $this->assertSame('3015.00', $q['total']);

        $q = LoanSettlement::quote($this->pending(), 'accrued', '2026-12-15', '2026-10-31');
        $this->assertSame('39.68', $q['interest']);   // 30 already due + 15/31 of 20
    }

    public function testSettlementNoInterestRule(): void
    {
        $q = LoanSettlement::quote($this->pending(), 'none', '2026-11-15', '2026-10-31');
        $this->assertSame('0.00', $q['interest']);
        $this->assertSame('3000.00', $q['total']);
    }

    public function testIgnoringInterestAtSettlement(): void
    {
        $q = LoanSettlement::quote($this->pending(), 'full', '2026-11-15', '2026-10-31', true);
        $this->assertSame('0.00', $q['interest']);
        $this->assertSame('60.00', $q['waived']);
        $this->assertSame('3000.00', $q['total']);
        $this->assertTrue($q['ignored']);
    }

    public function testFairValue(): void
    {
        $this->assertSame('887.45', LoanFairValue::present([['due_month' => '2027-10-01', 'total' => '1000']], '2026-10-31', '12')['fair_value']);
        $this->assertSame('1000.00', LoanFairValue::present([['due_month' => '2026-10-01', 'total' => '1000']], '2026-10-31', '12')['fair_value']);
        $this->assertSame('1000.00', LoanFairValue::present([['due_month' => '2030-10-01', 'total' => '1000']], '2026-10-31', '0')['fair_value']);
        $r = LoanFairValue::present([['due_month' => '2026-11-01', 'total' => '500'], ['due_month' => '2026-12-01', 'total' => '500']], '2026-10-31', '12');
        $this->assertSame('1000.00', $r['nominal']);
        $this->assertSame('985.20', $r['fair_value']); // 500/1.01 + 500/1.01^2
    }
}
