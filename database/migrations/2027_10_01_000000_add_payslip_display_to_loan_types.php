<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // separate = capital and interest on two payslip lines (as before); single = one line with capital + interest
        Schema::table('loan_types', function (Blueprint $table) {
            $table->string('payslip_display', 10)->default('separate')->after('settlement_interest_rule');
        });
    }

    public function down(): void
    {
        Schema::table('loan_types', function (Blueprint $table) {
            $table->dropColumn('payslip_display');
        });
    }
};
