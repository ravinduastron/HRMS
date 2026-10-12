<?php

namespace App\Payroll;

/**
 * Exact decimal arithmetic on numeric strings (bcmath). Floats are never used for money.
 * Internal scale is 20 digits; results are rounded explicitly with round().
 */
final class Decimal
{
    public const SCALE = 20;

    public static function ensureAvailable(): void
    {
        if (! function_exists('bcadd')) {
            throw new \RuntimeException('The PHP bcmath extension is required for payroll calculations. Enable extension=bcmath in php.ini.');
        }
    }

    public static function make(mixed $value): string
    {
        if (is_bool($value) || is_array($value) || $value === null) {
            throw new FormulaException('Expected a number.');
        }
        $s = trim((string) $value);
        if (! preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $s)) {
            throw new FormulaException("\"{$s}\" is not a valid number.");
        }
        $neg = $s[0] === '-';
        $s = ltrim($s, '+-');
        if ($s[0] === '.') {
            $s = '0'.$s;
        }
        $s = preg_replace('/^0+(?=\d)/', '', $s);

        return self::plain(($neg ? '-' : '').$s);
    }

    /** Strip trailing zeros: "12.500" => "12.5", "-0.00" => "0". */
    public static function plain(string $v): string
    {
        if (str_contains($v, '.')) {
            $v = rtrim(rtrim($v, '0'), '.');
        }
        if ($v === '' || $v === '-' || $v === '-0') {
            return '0';
        }

        return $v;
    }

    public static function add(string $a, string $b): string
    {
        return self::plain(bcadd($a, $b, self::SCALE));
    }

    public static function sub(string $a, string $b): string
    {
        return self::plain(bcsub($a, $b, self::SCALE));
    }

    public static function mul(string $a, string $b): string
    {
        return self::plain(bcmul($a, $b, self::SCALE));
    }

    public static function div(string $a, string $b): string
    {
        if (bccomp($b, '0', self::SCALE) === 0) {
            throw new FormulaException('Division by zero.');
        }

        return self::plain(bcdiv($a, $b, self::SCALE));
    }

    public static function mod(string $a, string $b): string
    {
        if (bccomp($b, '0', self::SCALE) === 0) {
            throw new FormulaException('Division by zero (MOD).');
        }

        return self::plain(bcmod($a, $b, self::SCALE));
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function neg(string $a): string
    {
        return self::plain(bcmul($a, '-1', self::SCALE));
    }

    public static function abs(string $a): string
    {
        return $a !== '' && $a[0] === '-' ? substr($a, 1) : $a;
    }

    public static function floor(string $v): string
    {
        $t = bcadd($v, '0', 0); // truncates toward zero
        if (bccomp($v, '0', self::SCALE) < 0 && bccomp($t, $v, self::SCALE) !== 0) {
            $t = bcsub($t, '1', 0);
        }

        return self::plain($t);
    }

    public static function ceil(string $v): string
    {
        $t = bcadd($v, '0', 0);
        if (bccomp($v, '0', self::SCALE) > 0 && bccomp($t, $v, self::SCALE) !== 0) {
            $t = bcadd($t, '1', 0);
        }

        return self::plain($t);
    }

    /** @param 'half_up'|'up'|'down' $mode  half_up: 2.345 -> 2.35 (and -2.345 -> -2.35); up: away from zero; down: toward zero */
    public static function round(string $v, int $dp = 2, string $mode = 'half_up'): string
    {
        $dp = max(0, min(10, $dp));
        $neg = bccomp($v, '0', self::SCALE) < 0;
        $abs = $neg ? substr($v, 1) : $v;

        switch ($mode) {
            case 'down':
                $r = bcadd($abs, '0', $dp);
                break;
            case 'up':
                $t = bcadd($abs, '0', $dp);
                $unit = $dp > 0 ? '0.'.str_repeat('0', $dp - 1).'1' : '1';
                $r = bccomp($t, $abs, self::SCALE) === 0 ? $t : bcadd($t, $unit, $dp);
                break;
            default:
                $half = bcdiv('5', bcpow('10', (string) ($dp + 1)), $dp + 1);
                $r = bcadd(bcadd($abs, $half, self::SCALE), '0', $dp);
        }

        return $neg && bccomp($r, '0', $dp) !== 0 ? '-'.$r : $r;
    }
}
