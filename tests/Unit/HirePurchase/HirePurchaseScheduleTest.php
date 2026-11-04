<?php

namespace Tests\Unit\HirePurchase;

use App\Payroll\FormulaException;
use App\Payroll\HirePurchase\HirePurchaseSchedule;
use PHPUnit\Framework\TestCase;

class HirePurchaseScheduleTest extends TestCase
{
    public function test_builds_equal_installments_one_per_month(): void
    {
        $rows = HirePurchaseSchedule::build('2500', 4, '2026-11-17');

        $this->assertCount(4, $rows);
        $this->assertSame(['2026-11-01', '2026-12-01', '2027-01-01', '2027-02-01'], array_column($rows, 'due_month'));
        $this->assertSame([1, 2, 3, 4], array_column($rows, 'seq'));
        $this->assertSame('2500.00', $rows[3]['amount']);
        $this->assertSame('10000.00', HirePurchaseSchedule::total($rows));
    }

    public function test_month_end_start_does_not_skip_a_month(): void
    {
        $rows = HirePurchaseSchedule::build('100.50', 3, '2027-01-31');

        $this->assertSame(['2027-01-01', '2027-02-01', '2027-03-01'], array_column($rows, 'due_month'));
        $this->assertSame('301.50', HirePurchaseSchedule::total($rows));
    }

    public function test_start_sequence_continues_from_given_number(): void
    {
        $rows = HirePurchaseSchedule::build('1000', 2, '2026-12-01', 7);

        $this->assertSame([7, 8], array_column($rows, 'seq'));
    }

    public function test_rejects_zero_amount_and_bad_counts(): void
    {
        foreach ([['0', 3], ['-5', 3], ['100', 0], ['100', HirePurchaseSchedule::MAX_TERM + 1]] as [$amount, $count]) {
            try {
                HirePurchaseSchedule::build($amount, $count, '2026-11-01');
                $this->fail("Expected an exception for {$amount} x {$count}");
            } catch (FormulaException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
