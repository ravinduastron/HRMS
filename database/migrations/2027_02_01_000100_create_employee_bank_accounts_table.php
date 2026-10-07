<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_branch_id')->constrained('bank_branches')->restrictOnDelete();

            $table->text('account_number');                    // encrypted by the model cast
            $table->char('account_number_hash', 64)->index();  // HMAC "blind index": duplicate checks without decrypting
            $table->string('account_last4', 4);                // for masked display
            $table->string('account_holder_name', 150);

            $table->boolean('is_active')->default(false);

            // Approval workflow hooks (not used yet: everything is auto-approved)
            $table->string('approval_status', 20)->default('approved')->index(); // pending | approved | rejected
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->unsignedBigInteger('added_by')->nullable();
            $table->string('added_by_name')->nullable();
            $table->unsignedBigInteger('removed_by')->nullable();
            $table->string('removed_by_name')->nullable();
            $table->string('removal_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Database-level guarantee: at most ONE active (non-removed) account per employee.
            $table->unsignedBigInteger('active_employee_key')->nullable()
                ->virtualAs('IF(is_active = 1 AND deleted_at IS NULL, employee_id, NULL)')->unique();

            $table->index(['employee_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_bank_accounts');
    }
};
