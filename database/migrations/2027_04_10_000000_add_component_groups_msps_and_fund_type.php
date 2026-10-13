<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Pay component groups
        Schema::create('pay_component_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();               // payslip label when the group is summed
            $table->string('type', 25);                           // earning | deduction | employer_contribution | info
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('payslip_order')->default(100);
            $table->boolean('combine_on_payslip')->default(false); // true: payslip shows ONE line (sum of members), amount only
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $starter = [
            ['Basic', 'earning', 10, false], ['Allowances', 'earning', 20, false], ['Overtime', 'earning', 30, true],
            ['Other earnings', 'earning', 40, false], ['Statutory deductions', 'deduction', 50, false],
            ['Other deductions', 'deduction', 60, false], ['Employer contributions', 'employer_contribution', 70, false],
            ['Information', 'info', 80, false],
        ];
        foreach ($starter as [$name, $type, $order, $combine]) {
            DB::table('pay_component_groups')->insert([
                'name' => $name, 'type' => $type, 'payslip_order' => $order, 'combine_on_payslip' => $combine,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::table('pay_components', function (Blueprint $table) {
            // Required by the application; nullable here only so existing rows can be backfilled below.
            $table->foreignId('pay_component_group_id')->nullable()->after('type')->constrained('pay_component_groups')->restrictOnDelete();
        });

        // Backfill (query builder on purpose: a one-off data fix, no per-row audit noise)
        $byType = ['earning' => 'Other earnings', 'deduction' => 'Other deductions', 'employer_contribution' => 'Employer contributions', 'info' => 'Information'];
        foreach ($byType as $type => $name) {
            DB::table('pay_components')->where('type', $type)->update(['pay_component_group_id' => DB::table('pay_component_groups')->where('name', $name)->value('id')]);
        }
        $byCode = ['BASIC' => 'Basic', 'TRANSPORT' => 'Allowances', 'OT_NORMAL' => 'Overtime', 'OT_DOUBLE' => 'Overtime'];
        foreach ($byCode as $code => $name) {
            DB::table('pay_components')->where('code', $code)->update(['pay_component_group_id' => DB::table('pay_component_groups')->where('name', $name)->value('id')]);
        }

        // 2. MSPS base flag (separate from EPF)
        Schema::table('pay_component_versions', function (Blueprint $table) {
            $table->boolean('msps_applicable')->default(false)->after('epf_applicable');
        });

        // 3. Employee provident fund type. MiHCM sends one number in epfNo for both funds, so the TYPE is set here
        //    (the sync never writes it). msps_no was a misleading name for that number.
        if (Schema::hasColumn('employees', 'msps_no')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->renameColumn('msps_no', 'fund_no');
            });
        }
        Schema::table('employees', function (Blueprint $table) {
            $table->string('provident_fund', 10)->default('msps')->after('fund_no'); // epf | msps
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('provident_fund');
        });
        if (Schema::hasColumn('employees', 'fund_no')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->renameColumn('fund_no', 'msps_no');
            });
        }
        Schema::table('pay_component_versions', function (Blueprint $table) {
            $table->dropColumn('msps_applicable');
        });
        Schema::table('pay_components', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pay_component_group_id');
        });
        Schema::dropIfExists('pay_component_groups');
    }
};
