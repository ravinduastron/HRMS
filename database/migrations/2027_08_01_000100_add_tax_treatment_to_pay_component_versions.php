<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_component_versions', function (Blueprint $table) {
            $table->string('tax_treatment', 10)->default('regular')->after('tax_applicable'); // regular (monthly table) | lump_sum (lump-sum table)
            $table->decimal('taxable_percentage', 5, 2)->default(100)->after('tax_treatment'); // share of the amount that is taxable
        });

        // One-off: components already paid separately and taxable are treated as lump sums. Check them on the component pages.
        DB::table('pay_component_versions')->where('payment_timing', 'separate')->where('tax_applicable', true)->update(['tax_treatment' => 'lump_sum']);
    }

    public function down(): void
    {
        Schema::table('pay_component_versions', function (Blueprint $table) {
            $table->dropColumn(['tax_treatment', 'taxable_percentage']);
        });
    }
};
