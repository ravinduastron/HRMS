<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves employee_sync_runs / employee_sync_changes to MIHCM_LOG_CONNECTION (if set and different from the default),
 * copying existing rows and ids, then removes them from the main database. Does nothing otherwise.
 * Cross-database foreign keys are not used: employee_id is a plain indexed column in the log database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $log = config('mihcm.log_connection');
        $main = config('database.default');

        if (! $log || $log === $main) {
            return;
        }

        $target = Schema::connection($log);

        if (! $target->hasTable('employee_sync_runs')) {
            $target->create('employee_sync_runs', function (Blueprint $table) {
                $table->id();
                $table->string('status', 20)->index();
                $table->string('trigger', 20);
                $table->unsignedBigInteger('triggered_by')->nullable();
                $table->string('triggered_by_name')->nullable();
                $table->boolean('is_baseline')->default(false);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                foreach (['fetched', 'created', 'updated', 'unchanged', 'deactivated', 'reactivated', 'skipped'] as $c) {
                    $table->unsignedInteger("{$c}_count")->default(0);
                }
                $table->text('notes')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        if (! $target->hasTable('employee_sync_changes')) {
            $target->create('employee_sync_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_sync_run_id')->constrained('employee_sync_runs')->cascadeOnDelete();
                $table->unsignedBigInteger('employee_id')->nullable()->index(); // no FK across databases
                $table->string('employee_no', 30)->nullable();
                $table->string('employee_name')->nullable();
                $table->string('change_type', 20);
                $table->json('changes')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['employee_sync_run_id', 'change_type']);
                $table->index(['change_type', 'created_at']);
            });
        }

        // Copy existing history (runs first, then changes), keeping ids
        if (Schema::connection($main)->hasTable('employee_sync_runs')) {
            DB::connection($main)->table('employee_sync_runs')->orderBy('id')->chunkById(500, function ($rows) use ($log) {
                DB::connection($log)->table('employee_sync_runs')->insertOrIgnore($rows->map(fn ($r) => (array) $r)->all());
            });
            DB::connection($main)->table('employee_sync_changes')->orderBy('id')->chunkById(1000, function ($rows) use ($log) {
                DB::connection($log)->table('employee_sync_changes')->insertOrIgnore($rows->map(fn ($r) => \Illuminate\Support\Arr::except((array) $r, ['mihcm_global_id']))->all());
            });

            Schema::connection($main)->dropIfExists('employee_sync_changes');
            Schema::connection($main)->dropIfExists('employee_sync_runs');
        }
    }

    public function down(): void
    {
        // Intentionally empty: moving the history back is a manual decision.
    }
};
