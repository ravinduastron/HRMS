<?php

namespace Tests\Unit\Payroll;

use App\Payroll\Formula\Formula;
use App\Payroll\FormulaException;
use PHPUnit\Framework\TestCase;

class FormulaTest extends TestCase
{
    protected function setUp(): void
    {
        if (! function_exists('bcadd')) {
            $this->markTestSkipped('bcmath is required.');
        }
    }

    public function test_arithmetic_is_exact_not_floating_point(): void
    {
        $this->assertSame('0.3', Formula::run('0.1 + 0.2'));
        $this->assertSame('12.5', Formula::run('100 / 8'));
    }

    public function test_precedence_and_unary_minus(): void
    {
        $this->assertSame('14', Formula::run('2 + 3 * 4'));
        $this->assertSame('20', Formula::run('(2 + 3) * 4'));
        $this->assertSame('-6', Formula::run('-2 * 3'));
        $this->assertSame('5', Formula::run('10 - 2 - 3'));
    }

    public function test_percent_postfix(): void
    {
        $this->assertSame('0.5', Formula::run('50%'));
        $this->assertSame('20', Formula::run('200 * 10%'));
        $this->assertSame('10000', Formula::run('BASIC * 12.5%', ['BASIC' => '80000']));
    }

    public function test_comparisons_and_logic(): void
    {
        $this->assertTrue(Formula::run('5 > 3'));
        $this->assertTrue(Formula::run('5 = 5'));
        $this->assertFalse(Formula::run('5 <> 5'));
        $this->assertFalse(Formula::run('2 >= 3'));
        $this->assertFalse(Formula::run('TRUE AND FALSE'));
        $this->assertTrue(Formula::run('NOT FALSE'));
        $this->assertTrue(Formula::run('1 > 2 OR 3 > 2'));
        $this->assertFalse(Formula::run('5 && 0'));
    }

    public function test_if_is_lazy(): void
    {
        $this->assertSame('100', Formula::run('IF(LWP > 0, 100, 0)', ['LWP' => '2']));
        $this->assertSame('1', Formula::run('IF(TRUE, 1, 1 / 0)'));
    }

    public function test_min_max_round_functions(): void
    {
        $this->assertSame('2', Formula::run('MIN(5, 2, 9)'));
        $this->assertSame('5', Formula::run('MAX(5, 2.5)'));
        $this->assertSame('5000', Formula::run('MIN(BASIC * 0.05, 5000)', ['BASIC' => '200000']));
        $this->assertSame('2.35', Formula::run('ROUND(2.345, 2)'));
        $this->assertSame('-2.35', Formula::run('ROUND(-2.345, 2)'));
        $this->assertSame('3', Formula::run('ROUND(2.5)'));
        $this->assertSame('2.99', Formula::run('ROUNDDOWN(2.999, 2)'));
        $this->assertSame('2.01', Formula::run('ROUNDUP(2.001, 2)'));
        $this->assertSame('-3', Formula::run('FLOOR(-2.5)'));
        $this->assertSame('-2', Formula::run('CEIL(-2.5)'));
        $this->assertSame('4.5', Formula::run('ABS(-4.5)'));
        $this->assertSame('1', Formula::run('MOD(10, 3)'));
    }

    public function test_variable_names_are_case_insensitive(): void
    {
        $this->assertSame('10', Formula::run('basic * 2', ['Basic' => '5']));
    }

    public function test_references_are_listed_once_in_order(): void
    {
        $ast = Formula::parse('IF(LWP_DAYS > 0, BASIC / DAYS_IN_MONTH * LWP_DAYS, OT)');
        $this->assertSame(['LWP_DAYS', 'BASIC', 'DAYS_IN_MONTH', 'OT'], Formula::references($ast));
    }

    /** @dataProvider badFormulas */
    public function test_bad_formulas_give_clear_errors(string $formula, string $contains): void
    {
        try {
            Formula::run($formula, ['A' => '1']);
            $this->fail("Expected an error for: {$formula}");
        } catch (FormulaException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    public static function badFormulas(): array
    {
        return [
            'empty'             => ['', 'empty'],
            'dangling operator' => ['2 +', 'ends unexpectedly'],
            'missing bracket'   => ['(2 + 3', 'ends unexpectedly'],
            'bad character'     => ['2 $ 3', 'Unexpected character'],
            'unknown function'  => ['FOO(1)', 'Unknown function FOO'],
            'wrong arg count'   => ['IF(1, 2)', 'IF needs 3'],
            'unknown variable'  => ['MISSING + 1', 'Unknown variable MISSING'],
            'divide by zero'    => ['A / 0', 'Division by zero'],
            'boolean as number' => ['(1 > 2) + 1', 'Expected a number'],
            'extra token'       => ['1 2', 'Unexpected'],
            'bad decimals'      => ['ROUND(1.5, 99)', 'decimal places'],
        ];
    }
}
