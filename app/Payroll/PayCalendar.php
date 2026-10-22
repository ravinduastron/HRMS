<?php

namespace App\Payroll;

use DateTimeImmutable;

/**
 * Pay cycles, attendance windows and pay-date rules of a scheme. Plain arrays in, plain arrays out: no database, no framework.
 *
 * Settings shape:
 *   cycles   [{start: 1, end: 15}, {start: 16, end: null}]   days of the month; end null = month end; must cover the whole month
 *   windows  overtime | no_pay | late | attendance_allowance, each
 *              {mode: 'cycle'}                                  same dates as the pay cycle
 *              {mode: 'custom', start_day, start_offset, end_day, end_offset}   offsets are months from the cycle's month (-1 = previous)
 *   pay_date {mode: 'range', month_offset, from_day, to_day}    e.g. the 20th to the 30th of the cycle month
 *            {mode: 'days_after', min, max}                     e.g. 1 to 7 days after the cycle ends
 * Days beyond the end of a short month are clamped to the month end (the 31st in February is the 28th or 29th).
 */
final class PayCalendar
{
    public const WINDOWS = [
        'overtime' => 'Overtime', 'no_pay' => 'No-pay leave', 'late' => 'Late deductions', 'attendance_allowance' => 'Attendance allowance',
    ];

    public const TIMINGS = ['every_cycle' => 'Every pay cycle', 'first_cycle' => 'Once a month, in the first cycle', 'last_cycle' => 'Once a month, in the last cycle'];

    public static function defaults(): array
    {
        return [
            'cycles' => [['start' => 1, 'end' => null]],
            'windows' => array_fill_keys(array_keys(self::WINDOWS), ['mode' => 'cycle']),
            'pay_date' => ['mode' => 'range', 'month_offset' => 0, 'from_day' => 20, 'to_day' => 30],
        ];
    }

    /** Turns form input (strings) into the settings shape. Does not validate: call validate() next. */
    public static function normalize(array $raw): array
    {
        $int = fn ($v) => ($v === null || $v === '' || $v === 'end') ? null : (is_numeric($v) ? (int) $v : null);

        $cycles = [];
        foreach (array_values((array) ($raw['cycles'] ?? [])) as $c) {
            $cycles[] = ['start' => $int($c['start'] ?? null), 'end' => $int($c['end'] ?? null)];
        }

        $windows = [];
        foreach (array_keys(self::WINDOWS) as $key) {
            $w = (array) ($raw['windows'][$key] ?? []);
            $windows[$key] = ($w['mode'] ?? 'cycle') === 'custom'
                ? ['mode' => 'custom', 'start_day' => $int($w['start_day'] ?? null), 'start_offset' => (int) ($w['start_offset'] ?? 0),
                    'end_day' => $int($w['end_day'] ?? null), 'end_offset' => (int) ($w['end_offset'] ?? 0)]
                : ['mode' => 'cycle'];
        }

        $p = (array) ($raw['pay_date'] ?? []);
        $payDate = ($p['mode'] ?? 'range') === 'days_after'
            ? ['mode' => 'days_after', 'min' => $int($p['min'] ?? null), 'max' => $int($p['max'] ?? null)]
            : ['mode' => 'range', 'month_offset' => (int) ($p['month_offset'] ?? 0), 'from_day' => $int($p['from_day'] ?? null), 'to_day' => $int($p['to_day'] ?? null)];

        return ['cycles' => $cycles, 'windows' => $windows, 'pay_date' => $payDate];
    }

    /** @return string[] problems in plain language (empty = valid) */
    public static function validate(array $s): array
    {
        $errors = [];
        $cycles = array_values($s['cycles'] ?? []);

        if (count($cycles) < 1 || count($cycles) > 4) {
            $errors[] = 'Add between 1 and 4 pay cycles.';
        } else {
            $n = count($cycles);
            foreach ($cycles as $i => $c) {
                $label = 'Cycle '.($i + 1);
                $start = $c['start'] ?? null;
                $end = $c['end'] ?? null;
                $last = $i === $n - 1;

                if (! is_int($start) || $start < 1 || $start > 28) {
                    $errors[] = "{$label}: the start day must be a whole number from 1 to 28.";
                    continue;
                }
                if ($i === 0 && $start !== 1) {
                    $errors[] = 'The first cycle must start on day 1.';
                }
                if ($i > 0) {
                    $prevEnd = $cycles[$i - 1]['end'] ?? null;
                    if (is_int($prevEnd) && $start !== $prevEnd + 1) {
                        $errors[] = "{$label} must start on day ".($prevEnd + 1).' (the day after the previous cycle ends), so no day is missed or counted twice.';
                    }
                }
                if ($last) {
                    if ($end !== null) {
                        $errors[] = "{$label} is the last cycle, so it must end at the month end (leave the end day empty).";
                    }
                } elseif (! is_int($end) || $end < $start || $end > 27) {
                    $errors[] = "{$label}: the end day must be a whole number from {$start} to 27, because another cycle follows.";
                }
            }
        }

        foreach (self::WINDOWS as $key => $label) {
            $w = $s['windows'][$key] ?? ['mode' => 'cycle'];
            if (($w['mode'] ?? 'cycle') !== 'custom') {
                continue;
            }
            $sd = $w['start_day'] ?? null;
            $ed = $w['end_day'] ?? null;
            $so = $w['start_offset'] ?? 0;
            $eo = $w['end_offset'] ?? 0;
            if (! is_int($sd) || $sd < 1 || $sd > 31 || ($ed !== null && (! is_int($ed) || $ed < 1 || $ed > 31))) {
                $errors[] = "{$label} window: days must be whole numbers from 1 to 31 (leave the end day empty for the month end).";
                continue;
            }
            if (! in_array($so, [-1, 0, 1], true) || ! in_array($eo, [-1, 0, 1], true)) {
                $errors[] = "{$label} window: choose the previous, same or next month.";
                continue;
            }
            if ($eo * 100 + ($ed ?? 99) < $so * 100 + $sd) {
                $errors[] = "{$label} window ends before it starts.";
            }
        }

        $p = $s['pay_date'] ?? [];
        if (($p['mode'] ?? '') === 'days_after') {
            $min = $p['min'] ?? null;
            $max = $p['max'] ?? null;
            if (! is_int($min) || ! is_int($max) || $min < 0 || $max < $min || $max > 31) {
                $errors[] = 'Pay date: days after the cycle must be whole numbers, with the last no smaller than the first (at most 31).';
            }
        } elseif (($p['mode'] ?? '') === 'range') {
            $from = $p['from_day'] ?? null;
            $to = $p['to_day'] ?? null;
            if (! in_array($p['month_offset'] ?? 0, [0, 1], true)) {
                $errors[] = 'Pay date: choose the same month or the next month.';
            } elseif (! is_int($from) || ! is_int($to) || $from < 1 || $to > 31 || $to < $from) {
                $errors[] = 'Pay date: the days must be whole numbers from 1 to 31, with the last no smaller than the first.';
            }
        } else {
            $errors[] = 'Choose how the pay date is worked out.';
        }

        return $errors;
    }

    /**
     * Every pay cycle of one month with its windows and the range the pay date may fall in.
     *
     * @return array<int, array{index: int, from: string, to: string, windows: array<string, array{0: string, 1: string}>, pay: array{0: string, 1: string}}>
     */
    public static function periods(array $s, int $year, int $month): array
    {
        $ms = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $out = [];

        foreach (array_values($s['cycles']) as $i => $c) {
            $from = self::day($ms, 0, $c['start']);
            $to = self::day($ms, 0, $c['end'] ?? null);

            $windows = [];
            foreach (array_keys(self::WINDOWS) as $key) {
                $w = $s['windows'][$key] ?? ['mode' => 'cycle'];
                $windows[$key] = ($w['mode'] ?? 'cycle') === 'custom'
                    ? [self::day($ms, (int) $w['start_offset'], $w['start_day'])->format('Y-m-d'), self::day($ms, (int) $w['end_offset'], $w['end_day'] ?? null)->format('Y-m-d')]
                    : [$from->format('Y-m-d'), $to->format('Y-m-d')];
            }

            $p = $s['pay_date'];
            $pay = $p['mode'] === 'days_after'
                ? [$to->modify('+'.(int) $p['min'].' day')->format('Y-m-d'), $to->modify('+'.(int) $p['max'].' day')->format('Y-m-d')]
                : [self::day($ms, (int) $p['month_offset'], $p['from_day'])->format('Y-m-d'), self::day($ms, (int) $p['month_offset'], $p['to_day'])->format('Y-m-d')];

            $out[] = ['index' => $i, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'windows' => $windows, 'pay' => $pay];
        }

        return $out;
    }

    public static function isPayDateAllowed(array $period, string $date): bool
    {
        return $date >= $period['pay'][0] && $date <= $period['pay'][1];
    }

    /** Are EPF / MSPS / ETF deducted in this cycle? */
    public static function statutoryApplies(string $timing, int $cycleIndex, int $cycleCount): bool
    {
        return match ($timing) {
            'first_cycle' => $cycleIndex === 0,
            'last_cycle' => $cycleIndex === $cycleCount - 1,
            default => true,
        };
    }

    /* ------------------------------ plain-language text ------------------------------ */

    public static function ordinal(int $n): string
    {
        $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }

    public static function describeCycle(array $c): string
    {
        return self::ordinal((int) $c['start']).' to '.(($c['end'] ?? null) === null ? 'month end' : self::ordinal((int) $c['end']));
    }

    public static function describeWindow(array $w): string
    {
        if (($w['mode'] ?? 'cycle') !== 'custom') {
            return 'Same as the pay cycle';
        }
        $label = [-1 => 'the previous month', 0 => 'the same month', 1 => 'the next month'];
        $start = self::ordinal((int) $w['start_day']);
        $end = ($w['end_day'] ?? null) === null ? 'month end' : self::ordinal((int) $w['end_day']);

        return (int) $w['start_offset'] === (int) $w['end_offset']
            ? "{$start} to {$end} of {$label[(int) $w['start_offset']]}"
            : "{$start} of {$label[(int) $w['start_offset']]} to {$end} of {$label[(int) $w['end_offset']]}";
    }

    public static function describePayDate(array $p): string
    {
        if (($p['mode'] ?? '') === 'days_after') {
            return $p['min'].' to '.$p['max'].' days after the cycle ends';
        }

        return 'Between the '.self::ordinal((int) $p['from_day']).' and '.self::ordinal((int) $p['to_day']).' of '.((int) $p['month_offset'] === 1 ? 'the month after the cycle' : 'the cycle month');
    }

    /* ------------------------------ internals ------------------------------ */

    /** The date for a day number in the month $offset months from $monthStart. Null or too-large days become the month end. */
    private static function day(DateTimeImmutable $monthStart, int $offset, ?int $day): DateTimeImmutable
    {
        $first = $offset === 0 ? $monthStart : $monthStart->modify(($offset > 0 ? '+' : '').$offset.' month');
        $last = (int) $first->format('t');
        $d = ($day === null || $day > $last) ? $last : $day;

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $d);
    }
}
