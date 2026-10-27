<?php

namespace App\Payroll\Statutory;

use App\Payroll\Decimal;
use App\Payroll\FormulaException;

/**
 * EPF / MSPS / ETF for one employee. The employee is on ONE provident fund (epf or msps); ETF applies to everyone (MSPS members too).
 * The employee's own contribution rate can differ from the default (pass it as $employeeRate). An exempt employee pays nothing.
 *
 * $rates: epf_employee, epf_employer, msps_employee, msps_employer, etf_employer (percentages, as strings)
 */
final class ContributionCalculator
{
    public const FUNDS = ['epf', 'msps'];

    public static function calculate(string $fund, string $fundBase, string $etfBase, array $rates, ?string $employeeRate = null, bool $exempt = false, int $dp = 2, string $mode = 'half_up'): array
    {
        if (! in_array($fund, self::FUNDS, true)) {
            throw new FormulaException("Unknown provident fund \"{$fund}\".");
        }

        $own = $employeeRate !== null && trim($employeeRate) !== '';
        $employeeRate = Decimal::make($own ? $employeeRate : $rates[$fund.'_employee']);
        $employerRate = Decimal::make($rates[$fund.'_employer']);
        $etfRate = Decimal::make($rates['etf_employer']);
        $fundBase = Decimal::make($fundBase);
        $etfBase = Decimal::make($etfBase);

        $zero = Decimal::round('0', $dp, $mode);
        $of = fn (string $base, string $rate) => $exempt || Decimal::cmp($base, '0') <= 0
            ? $zero
            : Decimal::round(Decimal::div(Decimal::mul($base, $rate), '100'), $dp, $mode);

        return [
            'fund' => $fund, 'exempt' => $exempt,
            'employee_rate' => $employeeRate, 'employee_rate_source' => $own ? 'employee' : 'default', 'employee' => $of($fundBase, $employeeRate),
            'employer_rate' => $employerRate, 'employer' => $of($fundBase, $employerRate),
            'etf_rate' => $etfRate, 'etf_employer' => $of($etfBase, $etfRate),
        ];
    }
}
