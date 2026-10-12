<?php

namespace Database\Seeders;

use App\Models\PayComponent;
use App\Services\PayComponentService;
use Illuminate\Database\Seeder;

/** Optional starter set to play with: php artisan db:seed --class=PayComponentSampleSeeder. Edit freely. Statutory (EPF/ETF/tax) rates are NOT seeded. */
class PayComponentSampleSeeder extends Seeder
{
    public function run(PayComponentService $service): void
    {
        $base = ['rounding_mode' => 'half_up', 'rounding_dp' => 2, 'proration' => 'none', 'payment_timing' => 'regular', 'pay_cycle_rule' => 'every_period',
            'epf_applicable' => false, 'etf_applicable' => false, 'tax_applicable' => false, 'ot_base' => false,
            'default_amount' => null, 'percentage_rate' => null, 'percentage_of' => null, 'formula' => null,
            'effective_from' => now()->startOfYear()->toDateString(), 'change_note' => 'Sample data'];

        $samples = [
            ['BASIC', 'Basic salary', 'earning', 10, ['calc_method' => 'fixed', 'proration' => 'calendar_days', 'epf_applicable' => true, 'etf_applicable' => true, 'tax_applicable' => true, 'ot_base' => true]],
            ['TRANSPORT', 'Transport allowance', 'earning', 20, ['calc_method' => 'fixed', 'default_amount' => 5000, 'tax_applicable' => true]],
            ['OT_NORMAL', 'Overtime (normal)', 'earning', 30, ['calc_method' => 'formula', 'formula' => 'BASIC / 240 * 1.5 * OT_HOURS', 'tax_applicable' => true]],
            ['OT_DOUBLE', 'Overtime (double)', 'earning', 31, ['calc_method' => 'formula', 'formula' => 'BASIC / 240 * 2 * OT_DOUBLE_HOURS', 'tax_applicable' => true]],
            ['INCENTIVE', 'Incentive', 'earning', 40, ['calc_method' => 'input', 'payment_timing' => 'separate', 'tax_applicable' => true]],
            ['NO_PAY', 'No-pay leave', 'deduction', 50, ['calc_method' => 'formula', 'formula' => 'IF(LWP_DAYS > 0, BASIC / DAYS_IN_MONTH * LWP_DAYS, 0)']],
            ['LOAN', 'Loan deduction', 'deduction', 60, ['calc_method' => 'system']],
        ];

        foreach ($samples as [$code, $name, $type, $order, $v]) {
            if (PayComponent::where('code', $code)->exists()) {
                continue;
            }
            $service->create(['code' => $code, 'name' => $name, 'type' => $type, 'show_on_payslip' => true, 'payslip_order' => $order, 'description' => null], $v + $base);
        }
    }
}
