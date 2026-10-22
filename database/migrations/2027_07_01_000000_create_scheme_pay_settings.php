<?php

use App\Support\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Versioned pay settings of a scheme (a scheme is the pay group). Rows are never edited: a change adds a new version.
        Schema::create('scheme_pay_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheme_id')->constrained('schemes')->restrictOnDelete();
            $table->date('effective_from');
            $table->json('cycles');                                  // [{start: 1, end: 15}, {start: 16, end: null}]  (null = month end)
            $table->json('windows');                                 // overtime, no_pay, late, attendance_allowance: cycle | custom
            $table->json('pay_date');                                // range (month offset + days) | days_after
            $table->string('statutory_timing', 15)->default('every_cycle'); // every_cycle | first_cycle | last_cycle
            $table->string('disbursement', 10)->default('bank');     // bank | cash
            $table->string('change_note', 500);

            // Approval hooks (auto-approved until the approval engine is built)
            $table->string('approval_status', 20)->default('approved')->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->unique(['scheme_id', 'effective_from'], 'sch_pay_scheme_eff_unique');
        });

        // Default provident fund of a scheme (replaces the Casual/Contract names that used to be hardcoded in EmployeeMapper).
        Schema::table('schemes', function (Blueprint $table) {
            $table->string('default_fund', 10)->default('msps')->after('description'); // epf | msps
        });

        // A manual EPF/MSPS choice on an employee is kept: while this is true the scheme default does not apply.
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('provident_fund_override')->default(false)->after('provident_fund');
        });

        // ONE-OFF backfill that reproduces the old rule exactly (MiHCM scheme "Casual" or "Contract" = EPF, everything else = MSPS),
        // so nothing changes on deploy. From now on the value is edited on the scheme screen, not in code.
        $epf = DB::table('schemes')->whereIn('mihcm_name', ['Casual', 'Contract'])->pluck('id')->all();
        if ($epf) {
            DB::table('schemes')->whereIn('id', $epf)->update(['default_fund' => 'epf']);
        }

        // Employees whose fund already differs from their scheme's default were switched by hand: protect those choices.
        $protected = 0;
        foreach (['epf', 'msps'] as $fund) {
            $ids = DB::table('schemes')->where('default_fund', $fund)->pluck('id')->all();
            if ($ids) {
                $protected += DB::table('employees')->whereIn('scheme_id', $ids)->where('provident_fund', '!=', $fund)->update(['provident_fund_override' => true]);
            }
        }

        Audit::log('scheme.fund_defaults_backfilled', null, 'Scheme default provident fund set from the previous Casual/Contract rule', context: ['epf_schemes' => count($epf), 'manual_overrides_kept' => $protected]);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('provident_fund_override');
        });
        Schema::table('schemes', function (Blueprint $table) {
            $table->dropColumn('default_fund');
        });
        Schema::dropIfExists('scheme_pay_settings');
    }
};
