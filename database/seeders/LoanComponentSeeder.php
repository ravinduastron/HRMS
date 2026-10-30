<?php

namespace Database\Seeders;

use App\Models\PayComponent;
use App\Models\PayComponentGroup;
use App\Services\PayComponentService;
use Illuminate\Database\Seeder;

/**
 * Creates the two payslip lines loans use: LOAN_CAPITAL and LOAN_INTEREST (deductions supplied by the loan module), in a group
 * "Loan deductions" that prints them as SEPARATE lines. Then pick them on each loan type. Safe to re-run; edit freely afterwards.
 */
class LoanComponentSeeder extends Seeder
{
    public function run(PayComponentService $service): void
    {
        $group = PayComponentGroup::firstOrCreate(
            ['name' => 'Loan deductions'],
            ['type' => 'deduction', 'description' => 'Loan capital and interest, shown on separate lines', 'payslip_order' => 65, 'combine_on_payslip' => false, 'is_active' => true],
        );

        $version = [
            'calc_method' => 'system', 'rounding_mode' => 'half_up', 'rounding_dp' => 2, 'proration' => 'none', 'payment_timing' => 'regular', 'pay_cycle_rule' => 'every_period',
            'epf_applicable' => false, 'msps_applicable' => false, 'etf_applicable' => false, 'tax_applicable' => false, 'ot_base' => false,
            'default_amount' => null, 'percentage_rate' => null, 'percentage_of' => null, 'formula' => null,
            'effective_from' => now()->startOfYear()->toDateString(), 'change_note' => 'Loan module',
        ];

        foreach ([['LOAN_CAPITAL', 'Loan repayment (capital)', 66], ['LOAN_INTEREST', 'Loan interest', 67]] as [$code, $name, $order]) {
            if (PayComponent::where('code', $code)->exists()) {
                continue;
            }
            $service->create([
                'code' => $code, 'name' => $name, 'type' => 'deduction', 'pay_component_group_id' => $group->id,
                'description' => 'Supplied by the loan module', 'show_on_payslip' => true, 'payslip_order' => $order,
            ], $version);
        }
    }
}
