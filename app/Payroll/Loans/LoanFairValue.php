<?php

namespace App\Payroll\Loans;

use App\Payroll\Decimal;
use DateTimeImmutable;

/**
 * Fair value of a loan: the present value of what is still to be collected (principal and interest), discounted at the market rate.
 * Each installment is discounted by the whole months from the valuation month to its due month (an installment due in the valuation
 * month is not discounted): value = installment / (1 + market rate / 12) ^ months.
 */
final class LoanFairValue
{
    public static function months(string $asAt, string $dueMonth): int
    {
        $a = new DateTimeImmutable($asAt);
        $d = new DateTimeImmutable($dueMonth);

        return max(0, ((int) $d->format('Y') - (int) $a->format('Y')) * 12 + ((int) $d->format('n') - (int) $a->format('n')));
    }

    public static function discountFactor(string $marketRate, int $months): string
    {
        if ($months === 0 || Decimal::cmp(Decimal::make($marketRate), '0') === 0) {
            return '1';
        }
        $base = Decimal::add('1', Decimal::div(Decimal::make($marketRate), '1200'));

        return Decimal::plain(bcdiv('1', bcpow($base, (string) $months, Decimal::SCALE), Decimal::SCALE));
    }

    /**
     * @param  array<int, array{due_month: string, total: string}>  $installments  what is still to be collected
     * @return array{fair_value: string, nominal: string}
     */
    public static function present(array $installments, string $asAt, string $marketRate, int $dp = 2, string $mode = 'half_up'): array
    {
        $pv = $nominal = '0';
        foreach ($installments as $i) {
            $total = Decimal::make((string) $i['total']);
            $nominal = Decimal::add($nominal, $total);
            $pv = Decimal::add($pv, Decimal::mul($total, self::discountFactor($marketRate, self::months($asAt, $i['due_month']))));
        }

        return ['fair_value' => Decimal::round($pv, $dp, $mode), 'nominal' => Decimal::round($nominal, $dp, $mode)];
    }
}
