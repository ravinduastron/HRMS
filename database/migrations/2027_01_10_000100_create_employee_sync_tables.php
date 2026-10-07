<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->index();            // queued, running, completed, failed
            $table->string('trigger', 20);                    // manual, scheduled
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->string('triggered_by_name')->nullable();
            $table->boolean('is_baseline')->default(false);   // first load: employees are "imported", not "new"
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('fetched_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('deactivated_count')->default(0);
            $table->unsignedInteger('reactivated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->text('notes')->nullable();                // warnings (e.g. safety threshold hit)
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_sync_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_sync_run_id')->constrained('employee_sync_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('employee_no', 30)->nullable();
            $table->string('employee_name')->nullable();       // snapshot
            $table->string('change_type', 20);                 // created, imported, updated, deactivated, reactivated, skipped
            $table->json('changes')->nullable();               // {field: {old, new}} (sensitive fields masked)
            $table->timestamp('created_at')->useCurrent();
            $table->index(['employee_sync_run_id', 'change_type']);
            $table->index(['change_type', 'created_at']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_sync_changes');
        Schema::dropIfExists('employee_sync_runs');
    }
};
