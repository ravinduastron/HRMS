<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The dividend tax table: a flat rate (final withholding tax), stored as a one-band table so versions, audit and calculators are shared. No rate is preloaded. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('tax_tables')->where('code', 'dividend')->exists()) {
            return;
        }
        $now = now();
        DB::table('tax_tables')->insert([
            'code' => 'dividend', 'name' => 'Dividend tax (final withholding)', 'kind' => 'flat',
            'description' => 'A flat rate deducted from dividends when they are paid. Dividends are kept out of the monthly and lump-sum APIT calculation.',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $id = DB::table('tax_tables')->where('code', 'dividend')->value('id');
        if ($id && ! DB::table('tax_table_versions')->where('tax_table_id', $id)->exists()) {
            DB::table('tax_tables')->where('id', $id)->delete();
        }
    }
};
