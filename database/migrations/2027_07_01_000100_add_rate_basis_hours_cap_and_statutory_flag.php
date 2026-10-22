<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rate basis of a fixed-amount line: monthly (as before), daily or hourly. The amount stays encrypted.
        Schema::table('employee_salary_lines', function (Blueprint $table) {
            $table->string('rate_basis', 10)->default('monthly')->after('amount'); // monthly | daily | hourly
        });

        // Employee-level pay rules. Part of the (immutable) salary version, so they have history, an effective date and a reason.
        Schema::table('employee_salary_versions', function (Blueprint $table) {
            $table->decimal('max_hours', 6, 2)->nullable()->after('change_note');  // most hours paid per month, null = no limit
            $table->boolean('statutory_exempt')->default(false)->after('max_hours'); // true = no EPF / MSPS / ETF for this employee
        });
    }

    public function down(): void
    {
        Schema::table('employee_salary_versions', function (Blueprint $table) {
            $table->dropColumn(['max_hours', 'statutory_exempt']);
        });
        Schema::table('employee_salary_lines', function (Blueprint $table) {
            $table->dropColumn('rate_basis');
        });
    }
};
