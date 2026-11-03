<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payslip layout of a loan comes from the pay component GROUP of its capital and interest items, so the loan type needs no display
 * settings. Removes payslip_display / paysheet_display if an earlier package added them (does nothing otherwise).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payslip_display', 'paysheet_display'] as $column) {
            if (Schema::hasColumn('loan_types', $column)) {
                Schema::table('loan_types', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
