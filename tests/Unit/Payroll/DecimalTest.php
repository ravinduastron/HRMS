<?php

namespace Tests\Unit\Payroll;

use App\Payroll\Decimal;
use App\Payroll\FormulaException;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    protected function setUp(): void
    {
        if (! function_exists('bcadd')) {
            $this->markTestSkipped('bcmath is required.');
        }
    }

    public function test_make_normalises_numbers(): void
    {
        $this->assertSame('12.5', Decimal::make('12.50'));
        $this->assertSame('0.5', Decimal::make('.5'));
        $this->assertSame('7', Decimal::make('007'));
        $this->assertSame('0', Decimal::make('-0.00'));
        $this->assertSame('-3', Decimal::make('-3'));
    }

    public function test_make_rejects_garbage(): void
    {
        $this->expectException(FormulaException::class);
        Decimal::make('12abc');
    }

    public function test_half_up_rounding_including_negatives(): void
    {
        $this->assertSame('2.35', Decimal::round('2.345', 2));
        $this->assertSame('2.34', Decimal::round('2.344', 2));
        $this->assertSame('-2.35', Decimal::round('-2.345', 2));
        $this->assertSame('3', Decimal::round('2.5', 0));
        $this->assertSame('6666.67', Decimal::round('6666.666666', 2));
        $this->assertSame('0.00', Decimal::round('-0.001', 2));
    }

    public function test_up_and_down_modes(): void
    {
        $this->assertSame('2.99', Decimal::round('2.999', 2, 'down'));
        $this->assertSame('-2.99', Decimal::round('-2.999', 2, 'down'));
        $this->assertSame('2.01', Decimal::round('2.001', 2, 'up'));
        $this->assertSame('2.00', Decimal::round('2.000', 2, 'up'));
        $this->assertSame('3', Decimal::round('2.1', 0, 'up'));
    }

    public function test_floor_and_ceil(): void
    {
        $this->assertSame('-3', Decimal::floor('-2.5'));
        $this->assertSame('2', Decimal::floor('2.5'));
        $this->assertSame('-2', Decimal::ceil('-2.5'));
        $this->assertSame('3', Decimal::ceil('2.1'));
        $this->assertSame('2', Decimal::ceil('2'));
    }

    public function test_division_by_zero_is_an_error(): void
    {
        $this->expectException(FormulaException::class);
        Decimal::div('1', '0');
    }
}
