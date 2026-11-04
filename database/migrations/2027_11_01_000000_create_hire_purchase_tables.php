<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hire_purchase_vendors', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150)->unique();
            $table->string('contact_person', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);   // vendors are never deleted, only deactivated
            $table->timestamps();
        });

        Schema::create('hire_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('hire_purchase_vendor_id')->constrained('hire_purchase_vendors')->restrictOnDelete();
            $table->foreignId('pay_component_id')->constrained('pay_components')->restrictOnDelete(); // the deduction the installments are paid through
            $table->string('reference', 40);
            $table->string('item_description', 255);
            $table->text('installment_amount');                          // ENCRYPTED: the installment the vendor gave (original; later changes are new installments)
            $table->unsignedSmallInteger('term_months');                 // the number of installments it started with
            $table->date('first_due_month');
            $table->string('status', 12)->index();                       // active | completed | stopped
            $table->string('notes')->nullable();
            $table->date('stopped_on')->nullable();
            $table->string('closed_reason', 500)->nullable();

            $table->string('approval_status', 20)->default('approved')->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'reference']);
        });

        // One row per month. A change supersedes ("replaced") the unpaid rows and adds new ones; nothing is deleted.
        Schema::create('hire_purchase_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hire_purchase_id')->constrained('hire_purchases')->restrictOnDelete();
            $table->unsignedSmallInteger('schedule_version')->default(1);
            $table->unsignedSmallInteger('seq');
            $table->date('due_month');                                   // first day of the month it is deducted in
            $table->text('amount');                                      // ENCRYPTED
            $table->string('status', 12)->index();                       // pending | deducted | stopped | replaced
            $table->date('deducted_on')->nullable();
            $table->timestamps();
            $table->index(['hire_purchase_id', 'status', 'due_month'], 'hp_inst_hp_status_due_idx');
            $table->index(['due_month', 'status'], 'hp_inst_due_status_idx');
        });

        // History of "edit future deductions". Immutable.
        Schema::create('hire_purchase_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hire_purchase_id')->constrained('hire_purchases')->restrictOnDelete();
            $table->date('effective_month');                             // the unpaid installments from this month were replaced
            $table->date('restart_month');                               // the first month of the new schedule (later than effective_month = months skipped)
            $table->text('old_amount');                                  // ENCRYPTED: the next installment before / after
            $table->text('new_amount');
            $table->unsignedSmallInteger('old_remaining');
            $table->unsignedSmallInteger('new_remaining');
            $table->text('old_total');                                   // ENCRYPTED: still to be deducted before / after
            $table->text('new_total');
            $table->unsignedSmallInteger('schedule_version');            // the version this change created
            $table->string('change_note', 500);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['hire_purchase_changes', 'hire_purchase_installments', 'hire_purchases', 'hire_purchase_vendors'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
