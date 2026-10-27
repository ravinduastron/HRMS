<?php

namespace Tests\Unit\Payroll\Statutory;

use App\Payroll\Statutory\SlabTax;
use PHPUnit\Framework\TestCase;

class SlabTaxTest extends TestCase
{
    private function tax(string $amount): string
    {
        return SlabTax::tax(Fixtures::monthly(), $amount)['tax'];
    }

    public function testExamplesFromTheIrdDocument(): void
    {
        $this->assertSame('1200.00', $this->tax('170000'));   // Table 2 PDF, example 01
        $this->assertSame('18500.00', $this->tax('300000'));  // Table 2 PDF, example 03
    }

    public function testNothingIsDeductedUpToTheRelief(): void
    {
        $this->assertSame('0.00', $this->tax('0'));
        $this->assertSame('0.00', $this->tax('100000'));
        $this->assertSame('0.00', $this->tax('150000'));
        $this->assertSame('0.00', $this->tax('-5000'));
    }

    public function testBandBoundariesBelongToTheLowerBand(): void
    {
        $this->assertSame(0, SlabTax::tax(Fixtures::monthly(), '150000')['band']);
        $this->assertSame(1, SlabTax::tax(Fixtures::monthly(), '150000.01')['band']);
        $this->assertSame('4999.98', $this->tax('233333'));
        $this->assertSame('5000.12', $this->tax('233334'));
        $this->assertSame('34999.90', $this->tax('358333'));
    }

    public function testTopBand(): void
    {
        $this->assertSame('50000.00', $this->tax('400000'));
        $this->assertSame(5, SlabTax::tax(Fixtures::monthly(), '9999999')['band']);
    }

    public function testValidationAcceptsTheIrdTables(): void
    {
        $this->assertSame([], SlabTax::validate(Fixtures::monthly()));
        $this->assertSame([], SlabTax::validate(Fixtures::lump()));
    }

    public function testValidationCatchesBrokenBands(): void
    {
        $bad = Fixtures::monthly();
        $bad[2]['over'] = '240000'; // gap after band 2
        $this->assertTrue(count(SlabTax::validate($bad)) >= 1);

        $bad = Fixtures::monthly();
        $bad[5]['up_to'] = '999999'; // last band must be open
        $this->assertTrue(count(SlabTax::validate($bad)) >= 1);

        $bad = Fixtures::monthly();
        $bad[0]['over'] = '10';
        $this->assertTrue(count(SlabTax::validate($bad)) >= 1);

        $bad = Fixtures::monthly();
        $bad[1]['rate'] = '120';
        $this->assertTrue(count(SlabTax::validate($bad)) >= 1);

        $this->assertTrue(count(SlabTax::validate([])) === 1);
    }

    public function testBoundaryGapsAreSmallForTheIrdTables(): void
    {
        $this->assertSame('0.04', SlabTax::maxGap(Fixtures::monthly()));
        $this->assertSame(5, count(SlabTax::boundaryGaps(Fixtures::monthly())));
    }

    public function testAnnualCrossCheck(): void
    {
        $this->assertSame([], SlabTax::annualisationProblems(Fixtures::monthly(), Fixtures::lump()));

        $lump = Fixtures::lump();
        $lump[2]['fixed'] = '450000';
        $this->assertSame(1, count(SlabTax::annualisationProblems(Fixtures::monthly(), $lump)));
    }
}
