<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rate basis of a fixed-amount structure line (monthly, daily or hourly). Existing lines stay monthly.
        Schema::table('salary_structure_lines', function (Blueprint $table) {
            $table->string('rate_basis', 10)->default('monthly')->after('default_amount');
        });
    }

    public function down(): void
    {
        Schema::table('salary_structure_lines', function (Blueprint $table) {
            $table->dropColumn('rate_basis');
        });
    }
};
