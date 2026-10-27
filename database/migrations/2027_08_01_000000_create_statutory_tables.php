<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contribution rates (percentages). Rows are never edited: a change adds a new version with a later effective date.
        Schema::create('statutory_rate_versions', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->decimal('epf_employee_rate', 7, 4);    // default; an employee can have their own (employee_contribution_rates)
            $table->decimal('epf_employer_rate', 7, 4);
            $table->decimal('msps_employee_rate', 7, 4);   // default; an employee can have their own
            $table->decimal('msps_employer_rate', 7, 4);
            $table->decimal('etf_employer_rate', 7, 4);    // ETF is paid for every employee, MSPS members included
            $table->string('change_note', 500);

            // Approval hooks (auto-approved until the approval engine is built)
            $table->string('approval_status', 20)->default('approved')->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();
        });

        // The two APIT tables (monthly regular pay, lump sums). Each has versions (one per tax year or change), each version has bands.
        Schema::create('tax_tables', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();          // apit_monthly | apit_lump_sum
            $table->string('name', 100);
            $table->string('kind', 15);                    // monthly | lump_sum
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('tax_table_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_table_id')->constrained('tax_tables')->restrictOnDelete();
            $table->date('effective_from');
            $table->string('label', 50);                   // e.g. "Y/A 2026/27"
            $table->string('source_note')->nullable();     // where the figures came from
            $table->string('change_note', 500);

            $table->string('approval_status', 20)->default('approved')->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->unique(['tax_table_id', 'effective_from'], 'tax_ver_table_eff_unique');
        });

        Schema::create('tax_table_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_table_version_id')->constrained('tax_table_versions')->restrictOnDelete();
            $table->unsignedSmallInteger('sort');
            $table->decimal('over_amount', 15, 2);         // the band covers amounts above this...
            $table->decimal('up_to_amount', 15, 2)->nullable(); // ...up to and including this (null = no limit)
            $table->decimal('rate', 7, 4);                 // percent
            $table->decimal('fixed_amount', 15, 2)->default(0); // tax = amount x rate% - this
            $table->timestamps();
            $table->unique(['tax_table_version_id', 'sort'], 'tax_slab_ver_sort_unique');
        });

        // An employee's own EPF/MSPS contribution rate (replaces the default of their fund). Immutable history; null = back to the default.
        Schema::create('employee_contribution_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('effective_from');
            $table->decimal('employee_rate', 7, 4)->nullable();
            $table->string('change_note', 500);

            $table->string('approval_status', 20)->default('approved')->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        $now = now();
        DB::table('tax_tables')->insert([
            ['code' => 'apit_monthly', 'name' => 'Monthly tax table (regular pay)', 'kind' => 'monthly',
                'description' => 'IRD APIT Tax Table No. 01: salary and other regular monthly pay.', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'apit_lump_sum', 'name' => 'Lump-sum tax table (bonus, incentives)', 'kind' => 'lump_sum',
                'description' => 'IRD APIT Tax Table No. 02: bonus, incentives and other lump-sum payments.', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contribution_rates');
        Schema::dropIfExists('tax_table_slabs');
        Schema::dropIfExists('tax_table_versions');
        Schema::dropIfExists('tax_tables');
        Schema::dropIfExists('statutory_rate_versions');
    }
};
