<?php

namespace App\Payroll;

/**
 * The three ways to adjust an amount: increment amount, increment portion (percentage), new salary.
 * Plain strings in, plain strings out: no database, no framework. Results are rounded to 2 decimals, half up.
 */
final class SalaryAdjustment
{
    public const MODES = ['amount' => 'Increment amount', 'percent' => 'Increment portion', 'new' => 'New salary'];

    /** Mode from a form key or an Excel cell ("Increment amount", "increment portion", "New salary"...). Null when not recognised. */
    public static function mode(string $raw): ?string
    {
        $k = preg_replace('/[^a-z]/', '', mb_strtolower($raw));

        return match (true) {
            in_array($k, ['amount', 'incrementamount', 'incrementbyamount'], true) => 'amount',
            in_array($k, ['percent', 'percentage', 'portion', 'incrementportion', 'incrementpercent', 'incrementpercentage'], true) => 'percent',
            str_contains($raw, '%') && str_starts_with($k, 'increment') => 'percent',
            in_array($k, ['new', 'newsalary', 'newvalue', 'newamount'], true) => 'new',
            default => null,
        };
    }

    public static function valueHelp(string $mode): string
    {
        return match ($mode) {
            'percent' => 'The value must be a percentage from -100 to 1000 with up to 4 decimals (a minus sign reduces).',
            'new' => 'The value must be a number with up to 2 decimals, zero or more.',
            default => 'The value must be a number with up to 2 decimals (a minus sign reduces).',
        };
    }

    /** The cleaned value ("+1,000.50" => "1000.5"), or null when it is not valid for the mode. */
    public static function value(string $mode, string $raw): ?string
    {
        $s = str_replace([',', ' ', '%'], '', trim($raw));
        if ($s !== '' && $s[0] === '+') {
            $s = substr($s, 1);
        }

        $ok = match ($mode) {
            'amount' => (bool) preg_match('/^-?\d{1,13}(\.\d{1,2})?$/', $s),
            'percent' => (bool) preg_match('/^-?\d{1,3}(\.\d{1,4})?$/', $s),
            'new' => (bool) preg_match('/^\d{1,13}(\.\d{1,2})?$/', $s),
            default => false,
        };
        if (! $ok) {
            return null;
        }

        $v = Decimal::make($s);
        if ($mode === 'percent' && (Decimal::cmp($v, '-100') < 0 || Decimal::cmp($v, '1000') > 0)) {
            return null;
        }

        return $v;
    }

    /** The new amount. Throws FormulaException when the result would be below zero. */
    public static function calculate(string $mode, string $current, string $value, int $dp = 2, string $rounding = 'half_up'): string
    {
        $cur = Decimal::make($current);
        $v = Decimal::make($value);

        $new = match ($mode) {
            'amount' => Decimal::add($cur, $v),
            'percent' => Decimal::mul($cur, Decimal::add('1', Decimal::div($v, '100'))),
            'new' => $v,
            default => throw new FormulaException('Unknown adjustment mode.'),
        };

        $new = Decimal::round($new, $dp, $rounding);
        if (Decimal::cmp($new, '0') < 0) {
            throw new FormulaException('The result would be below 0.');
        }

        return $new;
    }
}
