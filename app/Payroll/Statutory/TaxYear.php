<?php

namespace App\Payroll\Statutory;

use DateTimeImmutable;
use DateTimeInterface;

/** The tax year (year of assessment) a date falls in. Default April to March: 2026-10-23 is Y/A 2026/27. */
final class TaxYear
{
    /** @return array{label: string, start: string, end: string, year: int} */
    public static function for(DateTimeInterface $date, int $startMonth = 4): array
    {
        $y = (int) $date->format('Y');
        $m = (int) $date->format('n');
        $startYear = $m >= $startMonth ? $y : $y - 1;

        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $startYear, $startMonth));
        $end = $start->modify('+1 year')->modify('-1 day');
        $endYear = (int) $end->format('Y');

        return [
            'label' => $endYear === $startYear ? "Y/A {$startYear}" : sprintf('Y/A %d/%02d', $startYear, $endYear % 100),
            'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'year' => $startYear,
        ];
    }
}
