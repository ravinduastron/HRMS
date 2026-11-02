<?php

namespace App\Payroll\Statutory;

use App\Payroll\Decimal;

/**
 * Tax on a dividend: final withholding tax at the flat rate of the dividend tax table (stored as one band from 0, no limit, nothing deducted).
 * It is NOT part of the APIT regular or lump-sum calculation. Plain arrays in, plain arrays out.
 */
final class DividendTax
{
    /** @return array{tax: string, net: string, rate: string} */
    public static function tax(array $slabs, string $amount, int $dp = 2, string $mode = 'half_up'): array
    {
        $amount = Decimal::make($amount);
        $r = SlabTax::tax($slabs, $amount, $dp, $mode);

        return ['tax' => $r['tax'], 'net' => Decimal::round(Decimal::sub($amount, $r['tax']), $dp, $mode), 'rate' => $r['rate']];
    }
}
