<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The MiHCM value is read-only and informational: keep it under a clear name.
        if (Schema::hasColumn('employees', 'cost_center') && ! Schema::hasColumn('employees', 'mihcm_costcenter')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->renameColumn('cost_center', 'mihcm_costcenter');
            });
        }

        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('cost_centers')->restrictOnDelete();
            $table->unsignedTinyInteger('level');                 // 1, 2 or 3 (config/costcenters.php)
            $table->string('code', 30)->unique();                 // cannot change
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);          // never deleted, only deactivated
            $table->timestamps();
            $table->index(['parent_id', 'name'], 'cc_parent_name_idx');
        });

        // Effective-dated, immutable: every change adds a row. The last saved row on or before a date is the one in force.
        Schema::create('employee_cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('cost_center_id')->constrained('cost_centers')->restrictOnDelete();
            $table->date('effective_from');
            $table->string('path_label', 255);                    // snapshot of the full path when it was assigned
            $table->string('change_note', 500);

            $table->string('approval_status', 20)->default('approved')->index(); // auto-approved until the approval engine is built
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from'], 'ecc_emp_eff_idx');
            $table->index('cost_center_id', 'ecc_cc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_cost_centers');
        Schema::dropIfExists('cost_centers');
        if (Schema::hasColumn('employees', 'mihcm_costcenter') && ! Schema::hasColumn('employees', 'cost_center')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->renameColumn('mihcm_costcenter', 'cost_center');
            });
        }
    }
};
