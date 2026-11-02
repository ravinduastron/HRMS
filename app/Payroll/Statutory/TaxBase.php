<?php

namespace App\Payroll\Statutory;

use App\Payroll\Decimal;

/**
 * The taxable income of a pay run, split by the tax table that applies to it.
 * A component counts for tax only if it is flagged taxable; only a percentage of it may be taxable (100 = all of it).
 * Each line: amount, taxable (bool), treatment ('regular' | 'lump_sum' | 'dividend'), percentage (0 to 100), sign (1 earning, -1 deduction that reduces the base).
 * Dividend lines are final-withholding income: split() leaves them out (they never enter the APIT regular or lump-sum bases); dividend() totals them.
 */
final class TaxBase
{
    public static function taxablePortion(string $amount, string $percentage = '100', int $dp = 2, string $mode = 'half_up'): string
    {
        $pct = Decimal::make($percentage);
        if (Decimal::cmp($pct, '0') < 0) {
            $pct = '0';
        }
        if (Decimal::cmp($pct, '100') > 0) {
            $pct = '100';
        }

        return Decimal::round(Decimal::div(Decimal::mul(Decimal::make($amount), $pct), '100'), $dp, $mode);
    }

    /** @return array{regular: string, lump_sum: string} dividends are NOT included */
    public static function split(array $lines, int $dp = 2, string $mode = 'half_up'): array
    {
        $sum = ['regular' => '0', 'lump_sum' => '0'];

        foreach ($lines as $l) {
            if (empty($l['taxable']) || ($l['treatment'] ?? 'regular') === 'dividend') {
                continue;
            }
            $bucket = ($l['treatment'] ?? 'regular') === 'lump_sum' ? 'lump_sum' : 'regular';
            $portion = self::taxablePortion((string) $l['amount'], (string) ($l['percentage'] ?? '100'), $dp, $mode);
            $sum[$bucket] = ($l['sign'] ?? 1) < 0 ? Decimal::sub($sum[$bucket], $portion) : Decimal::add($sum[$bucket], $portion);
        }

        foreach ($sum as $k => $v) {
            $sum[$k] = Decimal::round(Decimal::cmp($v, '0') > 0 ? $v : '0', $dp, $mode);
        }

        return $sum;
    }

    /** Total of the dividend lines (the amount the dividend tax rate is applied to). */
    public static function dividend(array $lines, int $dp = 2, string $mode = 'half_up'): string
    {
        $sum = '0';

        foreach ($lines as $l) {
            if (empty($l['taxable']) || ($l['treatment'] ?? 'regular') !== 'dividend') {
                continue;
            }
            $portion = self::taxablePortion((string) $l['amount'], (string) ($l['percentage'] ?? '100'), $dp, $mode);
            $sum = ($l['sign'] ?? 1) < 0 ? Decimal::sub($sum, $portion) : Decimal::add($sum, $portion);
        }

        return Decimal::round(Decimal::cmp($sum, '0') > 0 ? $sum : '0', $dp, $mode);
    }
}
