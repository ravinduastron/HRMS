<?php

namespace Tests\Unit\Payroll\Statutory;

/** The IRD 2025/26 tables (Tax Table No. 01 and No. 02) as slabs. */
final class Fixtures
{
    private static function make(array $rows): array
    {
        $over = '0';
        $out = [];
        foreach ($rows as [$up, $rate, $fixed]) {
            $out[] = ['over' => $over, 'up_to' => $up, 'rate' => $rate, 'fixed' => $fixed];
            $over = $up ?? $over;
        }

        return $out;
    }

    public static function monthly(): array
    {
        return self::make([['150000', '0', '0'], ['233333', '6', '9000'], ['275000', '18', '37000'], ['316667', '24', '53500'], ['358333', '30', '72500'], [null, '36', '94000']]);
    }

    public static function lump(): array
    {
        return self::make([['1800000', '0', '0'], ['2800000', '6', '108000'], ['3300000', '18', '444000'], ['3800000', '24', '642000'], ['4300000', '30', '870000'], [null, '36', '1128000']]);
    }
}
