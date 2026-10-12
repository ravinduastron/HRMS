<?php

namespace Tests\Unit\Payroll;

use App\Payroll\ComponentCalculator;
use App\Payroll\FormulaException;
use PHPUnit\Framework\TestCase;

class ComponentCalculatorTest extends TestCase
{
    protected function setUp(): void
    {
        if (! function_exists('bcadd')) {
            $this->markTestSkipped('bcmath is required.');
        }
    }

    private function def(string $method, array $extra = []): array
    {
        return $extra + ['name' => 'X', 'type' => 'earning', 'method' => $method, 'formula' => null, 'amount' => '0',
            'rounding_mode' => 'half_up', 'rounding_dp' => 2];
    }

    private function defs(): array
    {
        return [
            'BASIC' => $this->def('fixed', ['amount' => '100000']),
            'ALLOW' => $this->def('percentage', ['percentage_of' => ['BASIC'], 'percentage_rate' => '10']),
            'NOPAY' => $this->def('formula', ['type' => 'deduction', 'formula' => 'IF(LWP_DAYS > 0, BASIC / DAYS_IN_MONTH * LWP_DAYS, 0)']),
            'TOTAL' => $this->def('formula', ['type' => 'info', 'formula' => 'BASIC + ALLOW - NOPAY']),
            'BONUS' => $this->def('input'),
        ];
    }

    public function test_calculates_in_dependency_order_with_rounding(): void
    {
        $r = (new ComponentCalculator)->calculate($this->defs(), ['DAYS_IN_MONTH' => 30, 'LWP_DAYS' => 2], ['TOTAL']);

        $this->assertSame('100000.00', $r['results']['BASIC']['value']);
        $this->assertSame('10000.00', $r['results']['ALLOW']['value']);
        $this->assertSame('6666.67', $r['results']['NOPAY']['value']);   // 100000 / 30 * 2 = 6666.666...
        $this->assertSame('103333.33', $r['results']['TOTAL']['value']);
        $this->assertArrayNotHasKey('BONUS', $r['results']);              // not needed by TOTAL

        $pos = array_flip($r['order']);
        $this->assertLessThan($pos['TOTAL'], $pos['BASIC']);
        $this->assertLessThan($pos['TOTAL'], $pos['ALLOW']);
        $this->assertLessThan($pos['TOTAL'], $pos['NOPAY']);
    }

    public function test_percentage_compiles_to_a_formula(): void
    {
        $this->assertSame('(BASIC) * 10 / 100', ComponentCalculator::effectiveFormula($this->defs()['ALLOW']));
    }

    public function test_fixed_amount_can_be_overridden_and_inputs_default_to_zero(): void
    {
        $calc = new ComponentCalculator;
        $this->assertSame('150000.00', $calc->calculate($this->defs(), ['BASIC' => '150000'], ['BASIC'])['results']['BASIC']['value']);
        $this->assertSame('0.00', $calc->calculate($this->defs(), [], ['BONUS'])['results']['BONUS']['value']);
        $this->assertSame('2500.50', $calc->calculate($this->defs(), ['BONUS' => '2500.5'], ['BONUS'])['results']['BONUS']['value']);
    }

    public function test_rounding_rule_per_component(): void
    {
        $defs = ['A' => $this->def('formula', ['formula' => '10 / 3', 'rounding_dp' => 0, 'rounding_mode' => 'down'])];
        $this->assertSame('3', (new ComponentCalculator)->calculate($defs, [])['results']['A']['value']);
    }

    public function test_needs_lists_inputs_and_variables(): void
    {
        $needs = (new ComponentCalculator)->needs($this->defs(), ['TOTAL']);
        $this->assertSame(['BASIC'], $needs['leaves']);
        $vars = $needs['variables'];
        sort($vars);
        $this->assertSame(['DAYS_IN_MONTH', 'LWP_DAYS'], $vars);
    }

    public function test_circular_references_name_the_loop(): void
    {
        $defs = [
            'A' => $this->def('formula', ['formula' => 'B + 1']),
            'B' => $this->def('formula', ['formula' => 'C + 1']),
            'C' => $this->def('formula', ['formula' => 'A + 1']),
        ];
        try {
            (new ComponentCalculator)->order($defs);
            $this->fail('Expected a circular reference error.');
        } catch (FormulaException $e) {
            $this->assertStringContainsString('Circular reference', $e->getMessage());
            $this->assertStringContainsString('A', $e->getMessage());
            $this->assertStringContainsString('→', $e->getMessage());
        }
    }

    public function test_self_reference_is_circular(): void
    {
        $this->expectException(FormulaException::class);
        (new ComponentCalculator)->order(['A' => $this->def('formula', ['formula' => 'A + 1'])]);
    }

    public function test_a_formula_must_produce_a_number(): void
    {
        $this->expectExceptionMessage('must produce a number');
        (new ComponentCalculator)->calculate(['A' => $this->def('formula', ['formula' => '1 > 2'])], []);
    }

    public function test_errors_mention_the_component(): void
    {
        $this->expectExceptionMessage('NOPAY');
        (new ComponentCalculator)->calculate($this->defs(), ['LWP_DAYS' => 1], ['NOPAY']); // DAYS_IN_MONTH not supplied
    }
}
