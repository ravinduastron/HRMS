<?php

namespace App\Payroll\HirePurchase;

use App\Payroll\Decimal;
use App\Payroll\FormulaException;
use DateTimeImmutable;

/**
 * Deduction schedule of a hire purchase: equal installments (no interest: the vendor's installment is final), one per month.
 * Plain strings in, plain arrays out: no database, no framework.
 */
final class HirePurchaseSchedule
{
    public const MAX_TERM = 120;

    /**
     * @param  string  $firstMonth  any date in the first deduction month
     * @return array<int, array{seq: int, due_month: string, amount: string}> due_month is the first day of the month
     */
    public static function build(string $amount, int $count, string $firstMonth, int $startSeq = 1): array
    {
        $amount = Decimal::round(Decimal::make($amount), 2);
        if (Decimal::cmp($amount, '0') <= 0) {
            throw new FormulaException('The installment amount must be more than 0.');
        }
        if ($count < 1 || $count > self::MAX_TERM) {
            throw new FormulaException('The number of installments must be from 1 to '.self::MAX_TERM.'.');
        }

        $d = new DateTimeImmutable($firstMonth);
        $first = $d->setDate((int) $d->format('Y'), (int) $d->format('n'), 1);

        $rows = [];
        for ($k = 0; $k < $count; $k++) {
            $rows[] = ['seq' => $startSeq + $k, 'due_month' => $first->modify("+{$k} month")->format('Y-m-d'), 'amount' => $amount];
        }

        return $rows;
    }

    public static function total(array $rows): string
    {
        $t = '0';
        foreach ($rows as $r) {
            $t = Decimal::add($t, (string) $r['amount']);
        }

        return Decimal::round($t, 2);
    }
}
