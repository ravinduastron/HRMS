<?php

namespace Database\Seeders;

use App\Models\TaxTable;
use App\Models\TaxTableSlab;
use App\Models\TaxTableVersion;
use Illuminate\Database\Seeder;

/**
 * Loads the IRD APIT tables for the 2025/26 year of assessment (Tax Table No. 01 and No. 02), as published.
 * Safe to re-run. Check the figures against the IRD, and add the next year as a new version in the system.
 */
class StatutoryTaxTableSeeder extends Seeder
{
    public function run(): void
    {
        $this->load('apit_monthly', 'Monthly tax table (regular pay)', 'monthly', 'IRD APIT Tax Table No. 01, 2025/26', [
            ['150000', '0', '0'], ['233333', '6', '9000'], ['275000', '18', '37000'], ['316667', '24', '53500'], ['358333', '30', '72500'], [null, '36', '94000'],
        ]);

        $this->load('apit_lump_sum', 'Lump-sum tax table (bonus, incentives)', 'lump_sum', 'IRD APIT Tax Table No. 02, 2025/26', [
            ['1800000', '0', '0'], ['2800000', '6', '108000'], ['3300000', '18', '444000'], ['3800000', '24', '642000'], ['4300000', '30', '870000'], [null, '36', '1128000'],
        ]);
    }

    private function load(string $code, string $name, string $kind, string $source, array $rows): void
    {
        $table = TaxTable::firstOrCreate(['code' => $code], ['name' => $name, 'kind' => $kind]);
        if ($table->versions()->whereDate('effective_from', '2025-04-01')->exists()) {
            return;
        }

        $version = new TaxTableVersion;
        $version->forceFill([
            'tax_table_id' => $table->id, 'effective_from' => '2025-04-01', 'label' => 'Y/A 2025/26', 'source_note' => $source,
            'change_note' => 'Initial load from the IRD table. Verify against the IRD.',
            'approval_status' => 'approved', 'approved_at' => now(), 'created_by_name' => 'System (seeder)',
        ])->save();

        $over = '0';
        foreach ($rows as $i => [$up, $rate, $fixed]) {
            (new TaxTableSlab)->forceFill([
                'tax_table_version_id' => $version->id, 'sort' => $i, 'over_amount' => $over, 'up_to_amount' => $up, 'rate' => $rate, 'fixed_amount' => $fixed,
            ])->save();
            $over = $up ?? $over;
        }
    }
}
