<?php

namespace App\Payroll\Statutory;

use App\Payroll\Decimal;

final class Format
{
    /** "1234567.5" => "1,234,567.50" (exact, no floats). */
    public static function money(string $value, int $dp = 2): string
    {
        $r = Decimal::round(Decimal::make($value), $dp);
        [$int, $frac] = array_pad(explode('.', $r), 2, '');
        $neg = str_starts_with($int, '-');
        $int = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', ltrim($int, '-'));

        return ($neg ? '-' : '').$int.($dp > 0 ? '.'.str_pad($frac, $dp, '0') : '');
    }
}
