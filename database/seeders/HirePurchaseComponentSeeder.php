<?php

namespace Database\Seeders;

use App\Models\PayComponent;
use App\Models\PayComponentGroup;
use App\Services\PayComponentService;
use Illuminate\Database\Seeder;

/**
 * Creates the pay item hire purchases are deducted through by default: HIRE_PURCHASE (a deduction supplied by the hire purchase module),
 * in a group "Hire purchase". Safe to re-run. Each hire purchase can still pick a different deduction component.
 */
class HirePurchaseComponentSeeder extends Seeder
{
    public function run(PayComponentService $service): void
    {
        if (PayComponent::where('code', 'HIRE_PURCHASE')->exists()) {
            return;
        }

        $group = PayComponentGroup::firstOrCreate(
            ['name' => 'Hire purchase'],
            ['type' => 'deduction', 'description' => 'Hire purchase installments', 'payslip_order' => 68, 'combine_on_payslip' => false, 'is_active' => true],
        );

        $service->create([
            'code' => 'HIRE_PURCHASE', 'name' => 'Hire purchase', 'type' => 'deduction', 'pay_component_group_id' => $group->id,
            'description' => 'Supplied by the hire purchase module', 'show_on_payslip' => true, 'payslip_order' => 68,
        ], [
            'calc_method' => 'system', 'rounding_mode' => 'half_up', 'rounding_dp' => 2, 'proration' => 'none', 'payment_timing' => 'regular', 'pay_cycle_rule' => 'every_period',
            'epf_applicable' => false, 'msps_applicable' => false, 'etf_applicable' => false, 'tax_applicable' => false, 'ot_base' => false,
            'default_amount' => null, 'percentage_rate' => null, 'percentage_of' => null, 'formula' => null,
            'effective_from' => now()->startOfYear()->toDateString(), 'change_note' => 'Hire purchase module',
        ]);
    }
}
