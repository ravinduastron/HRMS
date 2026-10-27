<?php

namespace Tests\Unit\Payroll\Statutory;

use App\Payroll\Statutory\ApitLumpSum;
use PHPUnit\Framework\TestCase;

/** The four worked examples of IRD Tax Table No. 02 (2025/26). */
class ApitLumpSumTest extends TestCase
{
    public function testExample01(): void
    {
        $r = ApitLumpSum::tax(Fixtures::lump(), '170000', '1870000', '600000', '1200', '13200', '0');
        $this->assertSame('2640000', $r['d']);
        $this->assertSame('36000.00', $r['tax']);
    }

    public function testExample02(): void
    {
        $r = ApitLumpSum::tax(Fixtures::lump(), '1530000', '510000', '1400000', '10800', '3600', '36000');
        $this->assertSame('3440000', $r['d']);
        $this->assertSame('133200.00', $r['tax']);
    }

    public function testExample03(): void
    {
        $r = ApitLumpSum::tax(Fixtures::lump(), '600000', '3000000', '600000', '37000', '185000', '0');
        $this->assertSame('4200000', $r['d']);
        $this->assertSame('168000.00', $r['tax']);
    }

    public function testExample04(): void
    {
        $r = ApitLumpSum::tax(Fixtures::lump(), '3000000', '600000', '1300000', '185000', '37000', '168000');
        $this->assertSame('4900000', $r['d']);
        $this->assertSame('246000.00', $r['tax']);
    }

    public function testNothingWhenTheYearStaysUnderTheThreshold(): void
    {
        $r = ApitLumpSum::tax(Fixtures::lump(), '1000000', '700000', '50000', '0', '0', '0');
        $this->assertSame('0.00', $r['tax']);
    }

    public function testNeverNegative(): void
    {
        // D = 1,900,000 at 6% = 114,000 gross, but 158,000 of credits (108,000 + 50,000)
        $r = ApitLumpSum::tax(Fixtures::lump(), '1000000', '0', '900000', '50000', '0', '0');
        $this->assertSame('0.00', $r['tax']);
        $this->assertSame('-44000', $r['raw']);
    }
}
