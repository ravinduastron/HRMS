<?php

namespace Tests\Unit\Payroll\Statutory;

use App\Payroll\FormulaException;
use App\Payroll\Statutory\ContributionCalculator as C;
use PHPUnit\Framework\TestCase;

/** The rates here are TEST figures only. Real rates are entered in the system. */
class ContributionCalculatorTest extends TestCase
{
    private array $rates = ['epf_employee' => '8', 'epf_employer' => '12', 'msps_employee' => '6', 'msps_employer' => '9', 'etf_employer' => '3'];

    public function testEpfMember(): void
    {
        $r = C::calculate('epf', '100000', '100000', $this->rates);
        $this->assertSame('8000.00', $r['employee']);
        $this->assertSame('12000.00', $r['employer']);
        $this->assertSame('3000.00', $r['etf_employer']);
        $this->assertSame('default', $r['employee_rate_source']);
    }

    public function testMspsMemberAlsoPaysEtf(): void
    {
        $r = C::calculate('msps', '100000', '90000', $this->rates);
        $this->assertSame('6000.00', $r['employee']);
        $this->assertSame('9000.00', $r['employer']);
        $this->assertSame('2700.00', $r['etf_employer']);
    }

    public function testEmployeeSpecificRateReplacesTheDefault(): void
    {
        $r = C::calculate('epf', '100000', '100000', $this->rates, '10');
        $this->assertSame('10000.00', $r['employee']);
        $this->assertSame('employee', $r['employee_rate_source']);
        $this->assertSame('12000.00', $r['employer']); // employer rate is not affected

        $zero = C::calculate('epf', '100000', '100000', $this->rates, '0');
        $this->assertSame('0.00', $zero['employee']);
        $this->assertSame('employee', $zero['employee_rate_source']);

        $blank = C::calculate('epf', '100000', '100000', $this->rates, '');
        $this->assertSame('default', $blank['employee_rate_source']);
    }

    public function testExemptEmployeePaysNothing(): void
    {
        $r = C::calculate('epf', '100000', '100000', $this->rates, null, true);
        $this->assertSame('0.00', $r['employee']);
        $this->assertSame('0.00', $r['employer']);
        $this->assertSame('0.00', $r['etf_employer']);
    }

    public function testRoundingToTwoDecimals(): void
    {
        $r = C::calculate('epf', '12345.67', '12345.67', $this->rates);
        $this->assertSame('987.65', $r['employee']); // 987.6536
        $this->assertSame('1481.48', $r['employer']); // 1481.4804
        $this->assertSame('370.37', $r['etf_employer']); // 370.3701
    }

    public function testRoundingModeAndPlaces(): void
    {
        $rates = ['epf_employee' => '0.1', 'epf_employer' => '0.1', 'msps_employee' => '0', 'msps_employer' => '0', 'etf_employer' => '0'];
        $this->assertSame('0.01', C::calculate('epf', '5', '0', $rates, null, false, 2, 'half_up')['employee']); // 0.005
        $this->assertSame('0.00', C::calculate('epf', '5', '0', $rates, null, false, 2, 'down')['employee']);
        $this->assertSame('1', C::calculate('epf', '1000', '0', $rates, null, false, 0, 'half_up')['employee']); // 0 decimal places
    }

    public function testNegativeBaseIsNil(): void
    {
        $this->assertSame('0.00', C::calculate('epf', '-500', '-500', $this->rates)['employee']);
    }

    public function testUnknownFundIsRefused(): void
    {
        $this->expectException(FormulaException::class);
        C::calculate('xyz', '1', '1', $this->rates);
    }
}
