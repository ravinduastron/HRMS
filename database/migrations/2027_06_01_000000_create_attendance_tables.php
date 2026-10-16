<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the tables on the attendance connection (null = default connection).
 * No foreign keys to the main database: employee_id is a plain indexed column (the databases may be separate).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('attendance.connection');
    }

    public function up(): void
    {
        Schema::create('attendance_load_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->index();             // queued | running | completed | failed
            $table->string('trigger', 20);                     // manual | payrun | console
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->string('triggered_by_name')->nullable();
            $table->string('scope', 12);                       // attendance | leave | both
            $table->date('from_date');
            $table->date('to_date');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('counts')->nullable();                // {attendance: {...}, leave: {...}}
            $table->json('quality')->nullable();               // data-quality report shown after the load
            $table->text('notes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('employee_no', 30);                 // snapshot of the number MiHCM sent
            $table->date('work_date')->index();
            $table->boolean('expected')->default(false);
            $table->boolean('present')->default(false);
            $table->boolean('midnight')->default(false);
            $table->boolean('lieu')->default(false);
            $table->boolean('day_off')->default(false);
            $table->smallInteger('day_type')->nullable();
            $table->integer('shift_code')->nullable();
            $table->dateTime('in_time')->nullable();
            $table->dateTime('out_time')->nullable();
            $table->dateTime('in_time_2')->nullable();
            $table->dateTime('out_time_2')->nullable();
            $table->unsignedInteger('work_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('ot_minutes')->default(0);
            $table->unsignedInteger('ot_approved_minutes')->default(0);        // from the decimal field (overtimeApproved)
            $table->unsignedInteger('ot_approved_text_minutes')->default(0);   // from the text field (overtimeApprovedString)
            $table->unsignedInteger('double_ot_minutes')->default(0);
            $table->unsignedInteger('double_ot_approved_minutes')->default(0);
            $table->unsignedInteger('double_ot_approved_text_minutes')->default(0);
            $table->unsignedInteger('triple_ot_minutes')->default(0);
            $table->unsignedInteger('triple_ot_approved_minutes')->default(0);
            $table->unsignedInteger('triple_ot_approved_text_minutes')->default(0);
            $table->string('leave_name', 100)->nullable();     // informational; payroll uses leave_entries
            $table->integer('leave_code')->nullable();
            $table->decimal('leave_amount', 8, 2)->nullable();
            $table->decimal('short_leave_amount', 8, 2)->nullable();
            $table->boolean('approval_done')->default(false);
            $table->string('approval_status', 30)->nullable();

            $table->char('row_hash', 64);
            $table->unsignedBigInteger('load_run_id')->index();   // last load that saw this row
            $table->timestamp('first_loaded_at')->nullable();
            $table->timestamp('loaded_at')->nullable();
            $table->timestamp('missing_since')->nullable();       // set when MiHCM stopped returning the row (kept, flagged)
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();              // MiHCM sysLeaveTypeCode
            $table->string('name', 100);
            $table->boolean('is_no_pay')->nullable();          // null = not reviewed yet (blocks payroll approval later)
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('leave_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('leave_entry_code')->unique(); // MiHCM leaveEntryCode
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('employee_no', 30);
            $table->string('leave_type_code', 20)->index();
            $table->string('leave_type_name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('start_session', 2)->nullable();    // FH | SH
            $table->string('end_session', 2)->nullable();
            $table->decimal('days', 6, 2);
            $table->string('status', 2);                       // P pending | C confirmed
            $table->dateTime('requested_at')->nullable();
            // leave reason / remark are deliberately NOT stored (may contain medical or personal details)

            $table->char('row_hash', 64);
            $table->unsignedBigInteger('load_run_id')->index();
            $table->timestamp('first_loaded_at')->nullable();
            $table->timestamp('loaded_at')->nullable();
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'start_date']);
            $table->index('end_date');
        });

        Schema::create('attendance_load_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('load_run_id')->constrained('attendance_load_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('employee_no', 30)->nullable();
            $table->string('kind', 12);                        // attendance | leave
            $table->string('ref', 30);                         // the date, or the leave entry code
            $table->string('change_type', 15);                 // updated | missing | restored
            $table->json('changes')->nullable();               // {field: {old, new}}
            $table->timestamp('created_at')->useCurrent();
            $table->index(['load_run_id', 'kind', 'change_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_load_changes');
        Schema::dropIfExists('leave_entries');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_load_runs');
    }
};
