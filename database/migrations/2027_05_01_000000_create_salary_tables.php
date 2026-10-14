<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Templates: belong to ONE scheme. Editing one never changes employees already assigned.
        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheme_id')->constrained('schemes')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['scheme_id', 'name'], 'sal_struct_scheme_name_unique');
        });

        Schema::create('salary_structure_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_structure_id')->constrained('salary_structures')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->constrained('pay_components')->restrictOnDelete();
            $table->decimal('default_amount', 15, 2)->nullable(); // only meaningful for "fixed" components
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['salary_structure_id', 'pay_component_id'], 'sal_struct_id_comp_id_unique');
        });

        // The employee's OWN salary. Immutable versions; every change adds a new one.
        Schema::create('employee_salary_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('effective_from');
            $table->unsignedBigInteger('salary_structure_id')->nullable()->index(); // structure it started from (no FK: structures may be removed later)
            $table->string('structure_name', 100)->nullable();                      // snapshot
            $table->string('change_type', 15);                                      // assigned | changed
            $table->string('change_note', 500);

            // Approval hooks (auto-approved until the approval engine is built)
            $table->string('approval_status', 20)->default('approved')->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'effective_from'], 'sal_v_emp_id_eff_unique');
        });

        Schema::create('employee_salary_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_salary_version_id')->constrained('employee_salary_versions')->restrictOnDelete();
            $table->foreignId('pay_component_id')->constrained('pay_components')->restrictOnDelete();
            $table->string('component_code', 30);          // snapshots, so history reads correctly
            $table->string('component_name', 100);
            $table->text('amount')->nullable();            // ENCRYPTED by the model cast (monthly equivalent); null for non-fixed components
            $table->string('source', 10);                  // structure | custom
            $table->timestamps();
            $table->unique(['employee_salary_version_id', 'pay_component_id'], 'sal_line_v_pay_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_lines');
        Schema::dropIfExists('employee_salary_versions');
        Schema::dropIfExists('salary_structure_lines');
        Schema::dropIfExists('salary_structures');
    }
};
