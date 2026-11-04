<?php

namespace App\Payroll;

use DateTimeImmutable;

/** Works out the pay periods of a month from a scheme's pay settings. Plain arrays in, plain arrays out: no database, no framework. */
final class PayPeriodPlan
{
    /** @return array<int, array{0: int, 1: int}> the 12 [year, month] pairs of a tax year that starts in $startMonth of $startYear */
    public static function taxYearMonths(int $startYear, int $startMonth = 4): array
    {
        $out = [];
        $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $startYear, $startMonth));
        for ($k = 0; $k < 12; $k++) {
            $m = $d->modify("+{$k} month");
            $out[] = [(int) $m->format('Y'), (int) $m->format('n')];
        }

        return $out;
    }

    /**
     * @param  array  $calendar  scheme settings in the PayCalendar shape (cycles, windows, pay_date)
     * @param  string  $timing  statutory timing (every_cycle | first_cycle | last_cycle)
     * @return array<int, array{cycle_no: int, period_start: string, period_end: string, windows: array, pay_date_from: string, pay_date_to: string, statutory_applies: bool}>
     */
    public static function forMonth(array $calendar, string $timing, int $year, int $month): array
    {
        $periods = PayCalendar::periods($calendar, $year, $month);
        $count = count($periods);

        return array_map(fn ($p) => [
            'cycle_no' => $p['index'] + 1, 'period_start' => $p['from'], 'period_end' => $p['to'], 'windows' => $p['windows'],
            'pay_date_from' => $p['pay'][0], 'pay_date_to' => $p['pay'][1],
            'statutory_applies' => PayCalendar::statutoryApplies($timing, $p['index'], $count),
        ], $periods);
    }
}
