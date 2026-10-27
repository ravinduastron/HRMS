<?php

namespace App\Payroll\Statutory;

use App\Payroll\Decimal;

/**
 * Slab tax as the IRD publishes it: in each band, tax = amount x rate% - fixed amount (never below zero).
 * A band covers amounts ABOVE the previous band's limit, up to and including its own limit (the last band has no limit).
 * Plain arrays in, plain arrays out: no database, no framework.
 *
 * Slab shape: ['over' => '150000', 'up_to' => '233333' | null, 'rate' => '6', 'fixed' => '9000']
 */
final class SlabTax
{
    public static function bandIndex(array $slabs, string $amount): int
    {
        foreach ($slabs as $i => $s) {
            if ($s['up_to'] === null || Decimal::cmp($amount, (string) $s['up_to']) <= 0) {
                return $i;
            }
        }

        return count($slabs) - 1;
    }

    /** amount x rate% - fixed, NOT floored and NOT rounded */
    public static function rawTax(array $slab, string $amount): string
    {
        return Decimal::sub(Decimal::div(Decimal::mul($amount, (string) $slab['rate']), '100'), (string) $slab['fixed']);
    }

    /** @return array{tax: string, band: int, rate: string, fixed: string, over: string, up_to: ?string, gross: string} */
    public static function tax(array $slabs, string $amount, int $dp = 2, string $mode = 'half_up'): array
    {
        $amount = Decimal::make($amount);
        $i = self::bandIndex($slabs, $amount);
        $s = $slabs[$i];
        $raw = self::rawTax($s, $amount);

        return [
            'tax' => Decimal::round(Decimal::cmp($raw, '0') > 0 ? $raw : '0', $dp, $mode),
            'band' => $i, 'rate' => (string) $s['rate'], 'fixed' => (string) $s['fixed'],
            'over' => (string) $s['over'], 'up_to' => $s['up_to'] === null ? null : (string) $s['up_to'],
            'gross' => Decimal::div(Decimal::mul($amount, (string) $s['rate']), '100'),
        ];
    }

    /** @return string[] problems in plain language (empty = the bands are usable) */
    public static function validate(array $slabs): array
    {
        $slabs = array_values($slabs);
        $n = count($slabs);
        if ($n < 1 || $n > 12) {
            return ['Add between 1 and 12 bands.'];
        }

        $errors = [];
        foreach ($slabs as $i => $s) {
            $label = 'Band '.($i + 1);
            $over = (string) $s['over'];
            $up = $s['up_to'] === null ? null : (string) $s['up_to'];
            $last = $i === $n - 1;

            if ($i === 0 && Decimal::cmp($over, '0') !== 0) {
                $errors[] = 'The first band must start at 0.';
            }
            if ($i > 0) {
                $prevUp = $slabs[$i - 1]['up_to'] ?? null;
                if ($prevUp === null || Decimal::cmp($over, (string) $prevUp) !== 0) {
                    $errors[] = "{$label} must start where the previous band ends.";
                }
            }
            if ($last) {
                if ($up !== null) {
                    $errors[] = "{$label} is the last band, so it must have no upper limit.";
                }
            } elseif ($up === null) {
                $errors[] = "{$label} needs an upper limit because another band follows.";
            } elseif (Decimal::cmp($up, $over) <= 0) {
                $errors[] = "{$label}: the upper limit must be more than where the band starts (".Format::money($over).').';
            }
            if (Decimal::cmp((string) $s['rate'], '0') < 0 || Decimal::cmp((string) $s['rate'], '100') > 0) {
                $errors[] = "{$label}: the rate must be between 0 and 100.";
            }
            if (Decimal::cmp((string) $s['fixed'], '0') < 0) {
                $errors[] = "{$label}: the fixed amount cannot be negative.";
            }
        }

        return $errors;
    }

    /** At each band boundary, how far apart the two neighbouring formulas are (a small gap is normal: the IRD rounds its constants). */
    public static function boundaryGaps(array $slabs): array
    {
        $slabs = array_values($slabs);
        $out = [];
        for ($i = 1; $i < count($slabs); $i++) {
            $b = (string) $slabs[$i]['over'];
            $out[] = ['at' => $b, 'gap' => Decimal::abs(Decimal::sub(self::rawTax($slabs[$i - 1], $b), self::rawTax($slabs[$i], $b)))];
        }

        return $out;
    }

    public static function maxGap(array $slabs): string
    {
        $max = '0';
        foreach (self::boundaryGaps($slabs) as $g) {
            if (Decimal::cmp($g['gap'], $max) > 0) {
                $max = $g['gap'];
            }
        }

        return $max;
    }

    /**
     * The lump-sum table is the annual version of the monthly one: same rates, fixed amounts and limits about 12 times larger.
     * Returns the differences found (empty = consistent). A check only: the user's figures are never changed.
     *
     * @return string[]
     */
    public static function annualisationProblems(array $monthly, array $lump, string $tolerance = '12.00'): array
    {
        $monthly = array_values($monthly);
        $lump = array_values($lump);
        if (count($monthly) !== count($lump)) {
            return ['The two tables have a different number of bands, so the "annual = 12 x monthly" cross-check was skipped.'];
        }

        $differs = fn (string $a, string $b) => Decimal::cmp(Decimal::abs(Decimal::sub($a, $b)), $tolerance) > 0;
        $out = [];
        foreach ($monthly as $i => $m) {
            $l = $lump[$i];
            $n = $i + 1;
            if (Decimal::cmp((string) $m['rate'], (string) $l['rate']) !== 0) {
                $out[] = "Band {$n}: the rates differ ({$m['rate']}% monthly, {$l['rate']}% lump sum).";
            }
            $expected = Decimal::mul((string) $m['fixed'], '12');
            if ($differs($expected, (string) $l['fixed'])) {
                $out[] = "Band {$n}: the lump-sum fixed amount (".Format::money((string) $l['fixed']).') is not 12 x the monthly one ('.Format::money($expected).').';
            }
            if ($m['up_to'] !== null && $l['up_to'] !== null && $differs(Decimal::mul((string) $m['up_to'], '12'), (string) $l['up_to'])) {
                $out[] = "Band {$n}: the lump-sum limit (".Format::money((string) $l['up_to']).') is not about 12 x the monthly limit ('.Format::money(Decimal::mul((string) $m['up_to'], '12')).').';
            }
        }

        return $out;
    }
}
