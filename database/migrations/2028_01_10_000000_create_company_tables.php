<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The one legal entity. A single row, edited in place (every change is audited).
        Schema::create('company_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name', 150);
            $table->string('trading_name', 150)->nullable();
            $table->string('registration_no', 50)->nullable();
            $table->string('tin', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('epf_employer_no', 30)->nullable();
            $table->string('etf_employer_no', 30)->nullable();
            $table->timestamps();
        });

        // Accounts the company pays salaries from. Several can be active; exactly one is the default.
        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);                         // nickname, e.g. "Salary account, BOC Main"
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_branch_id')->constrained('bank_branches')->restrictOnDelete();

            $table->text('account_number');                      // encrypted by the model cast
            $table->char('account_number_hash', 64)->index();    // blind index for duplicate checks
            $table->string('account_last4', 4);
            $table->string('account_holder_name', 150);

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->unsignedBigInteger('added_by')->nullable();
            $table->string('added_by_name')->nullable();
            $table->unsignedBigInteger('removed_by')->nullable();
            $table->string('removed_by_name')->nullable();
            $table->string('removal_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Database-level guarantee: at most ONE default (non-removed) account.
            $table->unsignedTinyInteger('default_key')->nullable()
                ->virtualAs('IF(is_default = 1 AND deleted_at IS NULL, 1, NULL)')->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_bank_accounts');
        Schema::dropIfExists('company_profiles');
    }
};
