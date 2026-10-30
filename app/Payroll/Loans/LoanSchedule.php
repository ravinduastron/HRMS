<?php

namespace App\Payroll\Loans;

use App\Payroll\Decimal;
use App\Payroll\FormulaException;

/**
 * Repayment schedule of a loan. Plain strings in, plain arrays out: no database, no framework.
 *
 *  straight_line  (flat)      interest = base x rate x term / 12, spread equally; principal equal; installments equal
 *  declining      (reducing)  principal equal; interest = opening balance x rate / 12; installments fall over time
 *  emi                        equal installments (annuity); interest on the reducing balance; principal part grows
 *
 * The last installment absorbs every rounding difference, so the principal always adds up to exactly the amount lent.
 * A rate of 0 means no interest, whatever the method.
 */
final class LoanSchedule
{
    public const METHODS = ['straight_line' => 'Straight line', 'declining' => 'Declining balance', 'emi' => 'EMI (equal installments)'];

    /**
     * @param  ?string  $interestBase  straight line only: the amount the flat interest is charged on (default: the principal given)
     * @return array<int, array{no: int, principal: string, interest: string, total: string, balance: string}>
     */
    public static function build(string $method, string $principal, string $annualRate, int $n, int $dp = 2, string $mode = 'half_up', ?string $interestBase = null): array
    {
        if (! isset(self::METHODS[$method])) {
            throw new FormulaException("Unknown interest method \"{$method}\".");
        }
        if ($n < 1) {
            throw new FormulaException('The number of installments must be at least 1.');
        }
        $principal = Decimal::make($principal);
        $rate = Decimal::make($annualRate);
        if (Decimal::cmp($principal, '0') < 0 || Decimal::cmp($rate, '0') < 0) {
            throw new FormulaException('The amount and the rate cannot be negative.');
        }

        $round = fn (string $v) => Decimal::round($v, $dp, $mode);
        $monthly = Decimal::div($rate, '1200');
        $zero = Decimal::cmp($rate, '0') === 0;
        $equal = $round(Decimal::div($principal, (string) $n));

        $totalInterest = '0';
        $payment = null;
        if (! $zero && $method === 'straight_line') {
            $base = $interestBase === null ? $principal : Decimal::make($interestBase);
            $totalInterest = $round(Decimal::div(Decimal::mul(Decimal::mul($base, $rate), (string) $n), '1200'));
        }
        if (! $zero && $method === 'emi') {
            $pow = bcpow(Decimal::add('1', $monthly), (string) $n, Decimal::SCALE);
            $den = Decimal::sub('1', bcdiv('1', $pow, Decimal::SCALE));
            $payment = $round(Decimal::div(Decimal::mul($principal, $monthly), $den));
        }

        $rows = [];
        $balance = $principal;
        $interestPaid = '0';
        for ($i = 1; $i <= $n; $i++) {
            $last = $i === $n;
            $before = $balance;

            if ($zero) {
                $interest = '0';
            } elseif ($method === 'straight_line') {
                $interest = $last ? Decimal::sub($totalInterest, $interestPaid) : $round(Decimal::div($totalInterest, (string) $n));
            } else {
                $interest = $round(Decimal::mul($before, $monthly));
            }

            if ($last) {
                $part = $before;
            } elseif ($method === 'emi' && ! $zero) {
                $part = Decimal::sub($payment, $interest);
            } else {
                $part = $equal;
            }
            if (Decimal::cmp($part, $before) > 0) {
                $part = $before;
            }
            if (Decimal::cmp($part, '0') < 0) {
                $part = '0';
            }

            $balance = Decimal::sub($before, $part);
            $interestPaid = Decimal::add($interestPaid, $interest);
            $rows[] = [
                'no' => $i, 'principal' => $round($part), 'interest' => $round($interest),
                'total' => $round(Decimal::add($part, $interest)), 'balance' => $round($balance),
            ];
        }

        return $rows;
    }

    /** @return array{principal: string, interest: string, total: string} */
    public static function totals(array $rows): array
    {
        $p = $i = '0';
        foreach ($rows as $r) {
            $p = Decimal::add($p, (string) $r['principal']);
            $i = Decimal::add($i, (string) $r['interest']);
        }

        return ['principal' => Decimal::round($p), 'interest' => Decimal::round($i), 'total' => Decimal::round(Decimal::add($p, $i))];
    }
}
