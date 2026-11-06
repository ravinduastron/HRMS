<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The value in force: one amount per pay period, employee and component. Replacing a value keeps the old one in pay_input_history.
        Schema::create('pay_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_period_id')->constrained('pay_periods')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('pay_component_id')->constrained('pay_components')->restrictOnDelete();
            $table->text('amount');                                      // ENCRYPTED
            $table->string('source', 10);                                // screen | import | restored (put back by reversing an upload)
            $table->unsignedBigInteger('import_batch_id')->nullable()->index();

            $table->string('approval_status', 20)->default('approved')->index(); // auto-approved until the approval engine is built
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('entered_by')->nullable();        // who set the value in force
            $table->string('entered_by_name')->nullable();
            $table->timestamps();

            $table->unique(['pay_period_id', 'employee_id', 'pay_component_id'], 'pay_input_period_emp_comp_unique');
            $table->index(['employee_id', 'pay_period_id'], 'pay_input_emp_period_idx');
        });

        // Every change, never edited or deleted.
        Schema::create('pay_input_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_period_id')->constrained('pay_periods')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('pay_component_id')->constrained('pay_components')->restrictOnDelete();
            $table->string('action', 10);                                // created | replaced | removed | reversed
            $table->text('old_amount')->nullable();                      // ENCRYPTED
            $table->text('new_amount')->nullable();                      // ENCRYPTED (null = removed)
            $table->string('source', 10);
            $table->unsignedBigInteger('import_batch_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['pay_period_id', 'employee_id', 'pay_component_id'], 'pay_input_hist_key_idx');
        });

        Schema::create('pay_input_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_period_id')->constrained('pay_periods')->restrictOnDelete();
            $table->unsignedBigInteger('scheme_id')->index();            // snapshot of the period's scheme (visibility)
            $table->string('file_name');
            $table->string('status', 12)->index();                       // staged | applied | reversed | discarded
            $table->unsignedInteger('total_rows')->default(0);           // one row per employee + component amount in the file
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('applied_rows')->default(0);         // rows that changed a value when applied
            $table->unsignedInteger('unchanged_rows')->default(0);       // rows equal to the value already in force
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('uploaded_by_name')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversed_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('pay_input_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_input_batch_id')->constrained('pay_input_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->text('data');                                        // ENCRYPTED by the model cast (holds amounts)
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->string('status', 10);                                // valid | error | applied
            $table->timestamps();
            $table->index(['pay_input_batch_id', 'status'], 'pay_input_rows_batch_status_idx');
        });
    }

    public function down(): void
    {
        foreach (['pay_input_rows', 'pay_input_batches', 'pay_input_history', 'pay_inputs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
