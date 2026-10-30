<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->string('interest_method', 15);                      // straight_line | declining | emi (a loan keeps its own copy)
            $table->decimal('default_rate', 7, 4)->default(0);          // yearly %, 0 = interest free
            $table->unsignedSmallInteger('max_term_months')->nullable();
            $table->decimal('max_amount', 15, 2)->nullable();
            $table->string('settlement_interest_rule', 10)->default('full'); // full | accrued | none
            // The deductions payroll shows: capital and interest are SEPARATE lines on the payslip and paysheet
            $table->foreignId('capital_component_id')->nullable()->constrained('pay_components')->restrictOnDelete();
            $table->foreignId('interest_component_id')->nullable()->constrained('pay_components')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('loan_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->string('status', 12)->index();                       // staged | applied | reversed | discarded
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('loans_created')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('uploaded_by_name')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversed_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('loan_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_import_batch_id')->constrained('loan_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->text('data');                                        // ENCRYPTED by the model cast (holds amounts)
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->string('status', 10);                                // valid | error | created
            $table->unsignedBigInteger('loan_id')->nullable();
            $table->timestamps();
            $table->index(['loan_import_batch_id', 'status']);
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('loan_type_id')->constrained('loan_types')->restrictOnDelete();
            $table->string('reference', 40);
            $table->string('interest_method', 15);
            $table->string('settlement_interest_rule', 10);
            $table->text('principal');                                   // ENCRYPTED: the amount originally lent
            $table->decimal('current_rate', 7, 4);                       // yearly %, changed through "Change rate"
            $table->unsignedSmallInteger('term_months');                 // original term
            $table->date('disbursed_on');
            $table->date('first_due_month');
            $table->string('status', 12)->index();                       // active | settled | cancelled
            $table->string('source', 10)->default('manual');             // manual | import
            $table->unsignedBigInteger('import_batch_id')->nullable()->index();
            $table->string('notes')->nullable();
            $table->date('settled_on')->nullable();
            $table->string('closed_reason', 500)->nullable();

            $table->string('approval_status', 20)->default('approved')->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'reference']);
        });

        // Installments of every schedule version. A rate change supersedes the unpaid ones and adds new ones; nothing is deleted.
        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->unsignedSmallInteger('schedule_version')->default(1);
            $table->unsignedSmallInteger('seq');
            $table->date('due_month');                                   // first day of the month it is deducted in
            $table->text('principal_amount');                            // ENCRYPTED: capital
            $table->text('interest_amount');                             // ENCRYPTED: interest
            $table->string('status', 12)->index();                       // pending | deducted | settled | superseded | cancelled
            $table->date('deducted_on')->nullable();
            $table->unsignedBigInteger('ledger_entry_id')->nullable();
            $table->timestamps();
            $table->index(['loan_id', 'status', 'due_month']);
            $table->index(['due_month', 'status']);
        });

        // Every money movement, never edited. Outstanding principal = the sum of the principal column.
        Schema::create('loan_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->string('entry_type', 20);                            // disbursement | opening | installment | settlement | interest_waived | rate_change
            $table->date('effective_date');
            $table->text('principal');                                   // ENCRYPTED, signed: + lent / opening balance, - repaid
            $table->text('interest');                                    // ENCRYPTED: interest collected (or waived, for interest_waived)
            $table->unsignedBigInteger('installment_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['loan_id', 'entry_type']);
        });

        Schema::create('loan_rate_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->date('effective_month');
            $table->decimal('previous_rate', 7, 4);
            $table->decimal('new_rate', 7, 4);
            $table->text('outstanding_principal');                       // ENCRYPTED: re-priced from this balance
            $table->unsignedSmallInteger('remaining_installments');
            $table->text('old_installment');                             // ENCRYPTED: the next installment before / after
            $table->text('new_installment');
            $table->text('old_remaining_interest');                      // ENCRYPTED: interest still to come before / after
            $table->text('new_remaining_interest');
            $table->unsignedSmallInteger('schedule_version');            // the version this change created
            $table->string('change_note', 500);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();
        });

        // Market rate used to value loans at fair value (versioned; a valuation can still override it)
        Schema::create('loan_market_rates', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->decimal('rate', 7, 4);
            $table->string('change_note', 500);
            $table->string('approval_status', 20)->default('approved')->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();
        });

        // Saved fair-value runs (snapshots): a month-end valuation is kept exactly as it was calculated
        Schema::create('loan_valuations', function (Blueprint $table) {
            $table->id();
            $table->date('as_at');
            $table->decimal('market_rate', 7, 4);
            $table->string('note')->nullable();
            $table->unsignedSmallInteger('loan_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_valuation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_valuation_id')->constrained('loan_valuations')->restrictOnDelete();
            $table->unsignedBigInteger('loan_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('employee_no', 30);
            $table->string('employee_name')->nullable();
            $table->string('loan_reference', 40);
            $table->string('loan_type_name', 100);
            $table->decimal('contractual_rate', 7, 4);
            $table->unsignedSmallInteger('remaining_installments');
            $table->text('outstanding_principal');                       // ENCRYPTED
            $table->text('nominal_remaining');                           // ENCRYPTED: all still to be collected, principal and interest
            $table->text('fair_value');                                  // ENCRYPTED
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['loan_valuation_lines', 'loan_valuations', 'loan_market_rates', 'loan_rate_changes', 'loan_ledger_entries', 'loan_installments', 'loans', 'loan_import_rows', 'loan_import_batches', 'loan_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
