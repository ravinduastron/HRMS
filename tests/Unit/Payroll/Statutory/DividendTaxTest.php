<?php

namespace Tests\Unit\Payroll\Statutory;

use App\Payroll\Statutory\DividendTax;
use App\Payroll\Statutory\SlabTax;
use App\Payroll\Statutory\TaxBase;
use PHPUnit\Framework\TestCase;

class DividendTaxTest extends TestCase
{
    private function flat(string $rate): array
    {
        return [['over' => '0', 'up_to' => null, 'rate' => $rate, 'fixed' => '0']];
    }

    public function test_flat_rate_is_applied_to_the_whole_dividend(): void
    {
        $r = DividendTax::tax($this->flat('15'), '500000');

        $this->assertSame('75000.00', $r['tax']);
        $this->assertSame('425000.00', $r['net']);
        $this->assertSame('15', $r['rate']);
    }

    public function test_rounding_and_zero_rate(): void
    {
        $this->assertSame('33.33', DividendTax::tax($this->flat('10'), '333.33')['tax']);
        $this->assertSame('0.00', DividendTax::tax($this->flat('0'), '1000')['tax']);
    }

    public function test_a_flat_table_is_a_valid_one_band_table(): void
    {
        $this->assertSame([], SlabTax::validate($this->flat('15')));
    }

    public function test_dividends_stay_out_of_the_apit_buckets(): void
    {
        $lines = [
            ['amount' => '100000', 'taxable' => true, 'treatment' => 'regular'],
            ['amount' => '50000', 'taxable' => true, 'treatment' => 'lump_sum'],
            ['amount' => '200000', 'taxable' => true, 'treatment' => 'dividend'],
        ];

        $this->assertSame(['regular' => '100000.00', 'lump_sum' => '50000.00'], TaxBase::split($lines));
        $this->assertSame('200000.00', TaxBase::dividend($lines));
    }
}
