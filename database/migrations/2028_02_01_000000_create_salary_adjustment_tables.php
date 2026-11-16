<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_adjustment_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheme_id')->constrained('schemes')->restrictOnDelete();
            $table->date('effective_from');
            $table->string('reason', 500);
            $table->string('source', 10);                          // screen | import
            $table->string('file_name')->nullable();
            $table->string('status', 12)->index();                 // staged | applied | rolled_back | discarded
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('applied_rows')->default(0);   // amounts changed when applied
            $table->unsignedInteger('unchanged_rows')->default(0); // new amount equal to the current one
            $table->unsignedInteger('employees_changed')->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('applied_by_name')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->string('rolled_back_by_name')->nullable();
            $table->string('rolled_back_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('salary_adjustment_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_adjustment_batch_id')->constrained('salary_adjustment_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->text('data');                                  // ENCRYPTED by the model cast (holds amounts)
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->string('status', 10);                          // valid | error | applied
            $table->timestamps();
            $table->index(['salary_adjustment_batch_id', 'status'], 'sal_adj_rows_batch_status_idx');
        });

        // What an applied adjustment really changed. The salary versions it created are linked, so a rollback can be checked and traced.
        Schema::create('salary_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_adjustment_batch_id')->constrained('salary_adjustment_batches')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('pay_component_id')->constrained('pay_components')->restrictOnDelete();
            $table->text('old_amount');                            // ENCRYPTED
            $table->text('new_amount');                            // ENCRYPTED
            $table->foreignId('applied_version_id')->constrained('employee_salary_versions')->restrictOnDelete();
            $table->foreignId('rollback_version_id')->nullable()->constrained('employee_salary_versions')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['salary_adjustment_batch_id', 'employee_id', 'pay_component_id'], 'sal_adj_line_batch_emp_comp_unique');
        });
    }

    public function down(): void
    {
        foreach (['salary_adjustment_lines', 'salary_adjustment_rows', 'salary_adjustment_batches'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
