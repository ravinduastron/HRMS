<?php

namespace Tests\Unit\Payroll;

use App\Payroll\PayInputSheet;
use PHPUnit\Framework\TestCase;

class PayInputSheetTest extends TestCase
{
    private function rows(): array
    {
        return [
            1 => [1 => 'Employee No', 2 => 'Name', 3 => 'INCENTIVE', 4 => 'allowance'],
            2 => [1 => '00123', 2 => 'A B', 3 => '1,500.5', 4 => ''],
            3 => [1 => '00124', 2 => 'C D'],
            4 => [1 => '00125', 3 => '0', 4 => '250'],
        ];
    }

    public function test_reads_one_entry_per_filled_cell_and_ignores_empty_ones(): void
    {
        $p = PayInputSheet::parse($this->rows(), ['INCENTIVE', 'ALLOWANCE']);

        $this->assertSame(1, $p['employee_col']);
        $this->assertSame([3 => 'INCENTIVE', 4 => 'ALLOWANCE'], $p['columns']);
        $this->assertSame([], $p['unknown']);
        $this->assertSame([
            ['row' => 2, 'employee_no' => '00123', 'code' => 'INCENTIVE', 'raw' => '1,500.5'],
            ['row' => 4, 'employee_no' => '00125', 'code' => 'INCENTIVE', 'raw' => '0'],
            ['row' => 4, 'employee_no' => '00125', 'code' => 'ALLOWANCE', 'raw' => '250'],
        ], $p['entries']);
    }

    public function test_reports_unknown_columns_and_a_missing_employee_column(): void
    {
        $p = PayInputSheet::parse($this->rows(), ['INCENTIVE']);
        $this->assertSame(['allowance'], $p['unknown']);

        $p = PayInputSheet::parse([1 => [1 => 'Staff', 2 => 'INCENTIVE'], 2 => [1 => '1', 2 => '5']], ['INCENTIVE']);
        $this->assertNull($p['employee_col']);
        $this->assertSame([], $p['entries']);
    }

    public function test_amount_accepts_up_to_two_decimals_and_zero(): void
    {
        $this->assertSame('1500.50', PayInputSheet::amount('1,500.5'));
        $this->assertSame('0.00', PayInputSheet::amount('0'));
        $this->assertSame('12.34', PayInputSheet::amount(' 12.34 '));
    }

    public function test_amount_rejects_negative_text_and_too_many_decimals(): void
    {
        foreach (['-5', 'abc', '1.234', '', '1e3', '12,34,5x'] as $raw) {
            $this->assertNull(PayInputSheet::amount($raw), "'{$raw}' should be refused");
        }
    }
}
