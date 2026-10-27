<?php

namespace App\Payroll\Statutory;

use App\Payroll\Decimal;

/**
 * Tax on a lump-sum payment (bonus, incentive...) by the IRD's "Tax Table No. 02" method:
 *   D = A + B + C   (estimated gross pay for the whole tax year, lump sums included)
 *   tax = D x rate% - (fixed amount + tax deducted on A + tax deductible on B + lump-sum tax deducted earlier)
 * A = regular pay already paid this year (this month included), B = regular pay still to come, C = lump sums paid and being paid.
 * A result below zero is nil (payroll never refunds tax).
 */
final class ApitLumpSum
{
    /** @return array{tax: string, d: string, band: int, rate: string, fixed: string, gross: string, credits: string, raw: string} */
    public static function tax(array $slabs, string $a, string $b, string $c, string $taxOnA, string $taxOnB, string $priorLumpTax = '0', int $dp = 2, string $mode = 'half_up'): array
    {
        $d = Decimal::add(Decimal::add(Decimal::make($a), Decimal::make($b)), Decimal::make($c));
        $i = SlabTax::bandIndex($slabs, $d);
        $s = $slabs[$i];

        $gross = Decimal::div(Decimal::mul($d, (string) $s['rate']), '100');
        $credits = Decimal::add(Decimal::add(Decimal::add((string) $s['fixed'], Decimal::make($taxOnA)), Decimal::make($taxOnB)), Decimal::make($priorLumpTax));
        $raw = Decimal::sub($gross, $credits);

        return [
            'tax' => Decimal::round(Decimal::cmp($raw, '0') > 0 ? $raw : '0', $dp, $mode),
            'd' => $d, 'band' => $i, 'rate' => (string) $s['rate'], 'fixed' => (string) $s['fixed'],
            'gross' => $gross, 'credits' => $credits, 'raw' => $raw,
        ];
    }
}
