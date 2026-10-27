<?php

namespace Tests\Unit\Payroll\Statutory;

use App\Payroll\Statutory\Format;
use App\Payroll\Statutory\TaxBase;
use App\Payroll\Statutory\TaxYear;
use PHPUnit\Framework\TestCase;

class TaxBaseTest extends TestCase
{
    public function testPercentageOfAnAmountIsTaxable(): void
    {
        $this->assertSame('5000.00', TaxBase::taxablePortion('10000', '50'));
        $this->assertSame('10000.00', TaxBase::taxablePortion('10000', '100'));
        $this->assertSame('0.00', TaxBase::taxablePortion('10000', '0'));
        $this->assertSame('10000.00', TaxBase::taxablePortion('10000', '250')); // clamped
        $this->assertSame('3333.33', TaxBase::taxablePortion('10000', '33.3333'));
    }

    public function testSplitByTreatment(): void
    {
        $r = TaxBase::split([
            ['amount' => '100000', 'taxable' => true, 'treatment' => 'regular', 'percentage' => '100'],
            ['amount' => '20000', 'taxable' => true, 'treatment' => 'regular', 'percentage' => '50'],
            ['amount' => '5000', 'taxable' => false, 'treatment' => 'regular', 'percentage' => '100'],
            ['amount' => '5000', 'taxable' => true, 'treatment' => 'regular', 'percentage' => '100', 'sign' => -1], // no-pay deduction
            ['amount' => '60000', 'taxable' => true, 'treatment' => 'lump_sum', 'percentage' => '100'],
        ]);
        $this->assertSame('105000.00', $r['regular']);
        $this->assertSame('60000.00', $r['lump_sum']);
    }

    public function testBucketsNeverGoBelowZero(): void
    {
        $r = TaxBase::split([['amount' => '5000', 'taxable' => true, 'treatment' => 'regular', 'percentage' => '100', 'sign' => -1]]);
        $this->assertSame('0.00', $r['regular']);
    }

    public function testTaxYear(): void
    {
        $this->assertSame('Y/A 2026/27', TaxYear::for(new \DateTimeImmutable('2026-10-23'))['label']);
        $this->assertSame('Y/A 2026/27', TaxYear::for(new \DateTimeImmutable('2027-03-31'))['label']);
        $this->assertSame('Y/A 2027/28', TaxYear::for(new \DateTimeImmutable('2027-04-01'))['label']);
        $this->assertSame('Y/A 2025/26', TaxYear::for(new \DateTimeImmutable('2026-03-31'))['label']);
        $y = TaxYear::for(new \DateTimeImmutable('2026-10-23'));
        $this->assertSame('2026-04-01', $y['start']);
        $this->assertSame('2027-03-31', $y['end']);
        $this->assertSame('Y/A 2026', TaxYear::for(new \DateTimeImmutable('2026-10-23'), 1)['label']);
    }

    public function testMoneyFormat(): void
    {
        $this->assertSame('1,234,567.50', Format::money('1234567.5'));
        $this->assertSame('0.00', Format::money('0'));
        $this->assertSame('-1,200.00', Format::money('-1200'));
        $this->assertSame('1,200', Format::money('1200', 0));
    }
}
