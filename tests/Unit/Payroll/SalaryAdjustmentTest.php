<?php

namespace Tests\Unit\Payroll;

use App\Payroll\FormulaException;
use App\Payroll\SalaryAdjustment;
use App\Payroll\SalaryAdjustmentSheet;
use PHPUnit\Framework\TestCase;

class SalaryAdjustmentTest extends TestCase
{
    public function test_modes_are_recognised(): void
    {
        $this->assertSame('amount', SalaryAdjustment::mode('Increment amount'));
        $this->assertSame('amount', SalaryAdjustment::mode('amount'));
        $this->assertSame('percent', SalaryAdjustment::mode('Increment portion'));
        $this->assertSame('percent', SalaryAdjustment::mode('Increment %'));
        $this->assertSame('percent', SalaryAdjustment::mode('percent'));
        $this->assertSame('new', SalaryAdjustment::mode('New salary'));
        $this->assertSame('new', SalaryAdjustment::mode('new'));
        $this->assertNull(SalaryAdjustment::mode('Bonus'));
        $this->assertNull(SalaryAdjustment::mode(''));
    }

    public function test_values_are_cleaned_and_checked(): void
    {
        $this->assertSame('1000.5', SalaryAdjustment::value('amount', '+1,000.50'));
        $this->assertSame('-1000', SalaryAdjustment::value('amount', '-1000'));
        $this->assertNull(SalaryAdjustment::value('amount', '10.123'));
        $this->assertNull(SalaryAdjustment::value('amount', 'abc'));
        $this->assertSame('10', SalaryAdjustment::value('percent', '10%'));
        $this->assertSame('-5.25', SalaryAdjustment::value('percent', '-5.25'));
        $this->assertNull(SalaryAdjustment::value('percent', '-101'));
        $this->assertNull(SalaryAdjustment::value('percent', '1001'));
        $this->assertSame('75000', SalaryAdjustment::value('new', '75,000'));
        $this->assertSame('0', SalaryAdjustment::value('new', '0'));
        $this->assertNull(SalaryAdjustment::value('new', '-1'));
    }

    public function test_increment_amount(): void
    {
        $this->assertSame('52500.00', SalaryAdjustment::calculate('amount', '50000.00', '2500'));
        $this->assertSame('49000.00', SalaryAdjustment::calculate('amount', '50000.00', '-1000'));
    }

    public function test_increment_portion_rounds_half_up_to_2_decimals(): void
    {
        $this->assertSame('55000.00', SalaryAdjustment::calculate('percent', '50000.00', '10'));
        $this->assertSame('47500.00', SalaryAdjustment::calculate('percent', '50000.00', '-5'));
        $this->assertSame('1000.05', SalaryAdjustment::calculate('percent', '1000.00', '0.005')); // 1000.0500 exact
        $this->assertSame('100.01', SalaryAdjustment::calculate('percent', '100.00', '0.005')); // 100.005 -> half up
        $this->assertSame('33.34', SalaryAdjustment::calculate('percent', '33.33', '0.03')); // 33.339999 -> 33.34
    }

    public function test_new_salary(): void
    {
        $this->assertSame('75000.00', SalaryAdjustment::calculate('new', '50000.00', '75000'));
        $this->assertSame('0.00', SalaryAdjustment::calculate('new', '50000.00', '0'));
    }

    public function test_result_below_zero_is_refused(): void
    {
        $this->expectException(FormulaException::class);
        SalaryAdjustment::calculate('amount', '500.00', '-500.01');
    }

    public function test_reducing_to_exactly_zero_is_allowed(): void
    {
        $this->assertSame('0.00', SalaryAdjustment::calculate('percent', '500.00', '-100'));
    }

    public function test_sheet_is_parsed(): void
    {
        $rows = [
            1 => [1 => 'Employee No', 2 => 'Name', 3 => 'Component', 4 => 'Current amount', 5 => 'Mode', 6 => 'Value'],
            2 => [1 => '00123', 2 => 'A', 3 => 'basic', 4 => '50000', 5 => 'Increment amount', 6 => '2500'],
            3 => [1 => '00124', 2 => 'B', 3 => 'BASIC', 4 => '60000'],
            4 => [1 => '00125', 3 => 'BASIC', 5 => 'New salary', 6 => '70000'],
            5 => [1 => '00126', 3 => 'BASIC', 5 => 'Increment portion'],
        ];
        $p = SalaryAdjustmentSheet::parse($rows);

        $this->assertSame([], $p['missing']);
        $this->assertCount(3, $p['entries']); // the untouched row is ignored
        $this->assertSame(['row' => 2, 'employee_no' => '00123', 'component' => 'BASIC', 'mode_raw' => 'Increment amount', 'value_raw' => '2500'], $p['entries'][0]);
        $this->assertSame('', $p['entries'][2]['value_raw']); // a half-filled row is kept, so it is reported as an error
    }

    public function test_sheet_with_missing_columns(): void
    {
        $p = SalaryAdjustmentSheet::parse([1 => [1 => 'Employee No', 2 => 'Component']]);
        $this->assertSame(['mode', 'value'], $p['missing']);
    }
}
