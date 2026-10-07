<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MiHCM does not send globalId / id, so employee_no is the only match key.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'mihcm_global_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropUnique(['mihcm_global_id']);
                $table->dropColumn('mihcm_global_id');
            });
        }
        if (Schema::hasColumn('employees', 'mihcm_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropIndex(['mihcm_id']);
                $table->dropColumn('mihcm_id');
            });
        }

        // The sync log tables live on the log connection (or the default one)
        $conn = config('mihcm.log_connection') ?: config('database.default');
        if (Schema::connection($conn)->hasColumn('employee_sync_changes', 'mihcm_global_id')) {
            Schema::connection($conn)->table('employee_sync_changes', function (Blueprint $table) {
                $table->dropColumn('mihcm_global_id');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
