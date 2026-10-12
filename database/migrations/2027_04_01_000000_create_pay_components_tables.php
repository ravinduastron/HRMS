<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_components', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();               // used in formulas, e.g. BASIC
            $table->string('name', 100);
            $table->string('type', 25);                          // earning | deduction | employer_contribution | info
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);         // inactive: cannot be used in new formulas/structures
            $table->boolean('show_on_payslip')->default(true);
            $table->unsignedSmallInteger('payslip_order')->default(100);
            $table->timestamps();
        });

        // How a component is calculated. Rows are never edited: a change adds a new version with a later effective date.
        Schema::create('pay_component_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_component_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->string('calc_method', 15);                   // fixed | percentage | formula | input | system
            $table->decimal('default_amount', 15, 2)->nullable();
            $table->decimal('percentage_rate', 9, 4)->nullable();
            $table->json('percentage_of')->nullable();           // component codes
            $table->text('formula')->nullable();
            $table->string('rounding_mode', 10)->default('half_up'); // half_up | up | down
            $table->unsignedTinyInteger('rounding_dp')->default(2);
            $table->boolean('epf_applicable')->default(false);
            $table->boolean('etf_applicable')->default(false);
            $table->boolean('tax_applicable')->default(false);
            $table->boolean('ot_base')->default(false);
            $table->string('proration', 20)->default('none');            // none | calendar_days | working_days
            $table->string('payment_timing', 10)->default('regular');    // regular | separate
            $table->string('pay_cycle_rule', 15)->default('every_period'); // every_period | first_period | last_period
            $table->string('change_note', 500)->nullable();

            // Approval hooks (everything is auto-approved until the approval engine is built)
            $table->string('approval_status', 20)->default('approved')->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->unique(['pay_component_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_component_versions');
        Schema::dropIfExists('pay_components');
    }
};
