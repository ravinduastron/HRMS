<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A stored pay period: the dates of one pay cycle of one scheme in one month, copied from the scheme's pay settings
        // in force that month. Later changes to the settings never alter a period that is Closed or Locked.
        Schema::create('pay_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheme_id')->constrained('schemes')->restrictOnDelete();
            $table->foreignId('scheme_pay_setting_id')->constrained('scheme_pay_settings')->restrictOnDelete(); // the version the dates came from
            $table->unsignedSmallInteger('tax_year_start');          // 2026 = Y/A 2026/27 (April 2026 to March 2027)
            $table->string('tax_year_label', 20);
            $table->unsignedSmallInteger('year');                    // calendar year and month the cycle belongs to
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('cycle_no');                 // 1, 2, ... within the month
            $table->date('period_start');
            $table->date('period_end');
            $table->json('windows');                                 // overtime, no_pay, late, attendance_allowance: [from, to]
            $table->date('pay_date_from');
            $table->date('pay_date_to');
            $table->boolean('statutory_applies')->default(true);     // EPF / MSPS / ETF are deducted in this cycle

            $table->string('status', 10)->index();                   // open | closed | locked (locked by a pay run)
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->string('closed_by_name')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['scheme_id', 'year', 'month', 'cycle_no'], 'pay_period_scheme_month_cycle_unique');
            $table->index(['scheme_id', 'tax_year_start'], 'pay_period_scheme_taxyear_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_periods');
    }
};
