<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An employee added to a pay item for ONE pay period only (the component is not on their salary). Never changes the salary.
        Schema::create('pay_input_additions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_period_id')->constrained('pay_periods')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('pay_component_id')->constrained('pay_components')->restrictOnDelete();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->string('added_by_name')->nullable();
            $table->timestamps();

            $table->unique(['pay_period_id', 'employee_id', 'pay_component_id'], 'pay_input_add_period_emp_comp_unique');
            $table->index('employee_id', 'pay_input_add_emp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_input_additions');
    }
};
