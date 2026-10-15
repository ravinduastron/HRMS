<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Several salary changes may share an effective date (assign a structure, then set amounts, add and remove components). The last saved one is in force. */
return new class extends Migration
{
    public function up(): void
    {
        // Add the plain index first: the employee_id foreign key must stay indexed when the unique index goes.
        Schema::table('employee_salary_versions', function (Blueprint $table) {
            $table->index(['employee_id', 'effective_from']);
        });
        Schema::table('employee_salary_versions', function (Blueprint $table) {
            $table->dropUnique('sal_v_emp_id_eff_unique');
        });
    }

    public function down(): void
    {
        // Fails if an employee already has two versions on the same date (that is the point of this migration).
        Schema::table('employee_salary_versions', function (Blueprint $table) {
            $table->unique(['employee_id', 'effective_from']);
        });
        Schema::table('employee_salary_versions', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'effective_from']);
        });
    }
};
