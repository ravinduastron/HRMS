<?php

namespace App\Payroll\Loans;

use App\Payroll\Decimal;
use DateTimeImmutable;

/**
 * Early settlement of a loan: outstanding principal plus interest by the loan type's rule.
 *   full     all the interest still scheduled on the unpaid installments
 *   accrued  interest on installments already due, plus the part of the next one earned up to the settlement date
 *   none     no interest
 * "Ignore interest" charges none of it; the amount that would have been charged is reported as waived.
 */
final class LoanSettlement
{
    public const RULES = ['full' => 'Full scheduled interest', 'accrued' => 'Interest accrued to the settlement date', 'none' => 'No interest'];

    /**
     * @param  array<int, array{due: string, principal: string, interest: string}>  $pending  unpaid installments in order (due = the due date, Y-m-d)
     * @param  string  $periodStart  the date the first pending installment's period began (the previous due date, or the disbursement date)
     * @return array{principal: string, interest_full: string, interest_accrued: string, interest_by_rule: string, interest: string, waived: string, total: string, rule: string, ignored: bool}
     */
    public static function quote(array $pending, string $rule, string $date, string $periodStart, bool $ignoreInterest = false, int $dp = 2, string $mode = 'half_up'): array
    {
        $principal = $full = $accrued = '0';
        $start = new DateTimeImmutable($periodStart);
        $on = new DateTimeImmutable($date);
        $future = false;

        foreach ($pending as $p) {
            $principal = Decimal::add($principal, (string) $p['principal']);
            $full = Decimal::add($full, (string) $p['interest']);
            $due = new DateTimeImmutable($p['due']);

            if ($due <= $on) {
                $accrued = Decimal::add($accrued, (string) $p['interest']);   // already due: all of its interest
            } elseif (! $future) {
                $future = true;                                              // the installment the date falls in: the part earned so far
                $span = (int) $start->diff($due)->format('%r%a');
                $gone = (int) $start->diff($on)->format('%r%a');
                if ($span > 0 && $gone > 0) {
                    $fraction = Decimal::div((string) min($gone, $span), (string) $span);
                    $accrued = Decimal::add($accrued, Decimal::mul((string) $p['interest'], $fraction));
                }
            }
            $start = $due;
        }

        $round = fn (string $v) => Decimal::round($v, $dp, $mode);
        $byRule = match ($rule) { 'accrued' => $round($accrued), 'none' => $round('0'), default => $round($full) };
        $charged = $ignoreInterest ? $round('0') : $byRule;

        return [
            'principal' => $round($principal), 'interest_full' => $round($full), 'interest_accrued' => $round($accrued),
            'interest_by_rule' => $byRule, 'interest' => $charged, 'waived' => $ignoreInterest ? $byRule : $round('0'),
            'total' => $round(Decimal::add($principal, $charged)), 'rule' => $rule, 'ignored' => $ignoreInterest,
        ];
    }
}
