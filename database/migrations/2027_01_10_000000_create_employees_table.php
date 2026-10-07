<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id(); // other modules (bank accounts, salaries...) reference THIS id, never employee_no

            // ---- MiHCM identity ----
            $table->string('employee_no', 30)->unique();           // the sync match key (MiHCM employeeNumber)

            // ---- Person ----
            $table->string('emp_title', 20)->nullable();
            $table->string('display_name', 150)->nullable();
            $table->string('calling_name', 100)->nullable();       // API firstName
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('surname', 100)->nullable();
            $table->string('full_name', 255)->nullable()->index();
            $table->string('nic_no', 20)->nullable()->index();
            $table->date('birthday')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('marital_status', 30)->nullable();

            // ---- Contact ----
            $table->string('mobile', 30)->nullable();
            $table->string('email', 255)->nullable()->index();
            $table->string('permanent_address_1')->nullable();
            $table->string('permanent_address_2')->nullable();
            $table->string('permanent_address_3')->nullable();
            $table->string('permanent_postal_code', 20)->nullable();
            $table->string('permanent_district', 100)->nullable();
            $table->string('permanent_country', 100)->nullable();
            $table->string('residential_address_1')->nullable();
            $table->string('residential_address_2')->nullable();
            $table->string('residential_address_3')->nullable();
            $table->string('residential_postal_code', 20)->nullable();
            $table->string('residential_district', 100)->nullable();
            $table->string('residential_country', 100)->nullable();

            // ---- Employment ----
            $table->date('date_joined')->nullable();
            $table->date('contract_expiry_date')->nullable();
            $table->date('resigned_date')->nullable();
            $table->date('resignation_effect_date')->nullable();
            $table->date('last_working_date')->nullable();
            $table->string('emp_type', 50)->nullable();
            $table->string('ct_code', 50)->nullable();
            $table->string('ct_name', 100)->nullable();            // job category
            $table->string('dsg_name', 150)->nullable();           // designation
            $table->string('grade_name', 100)->nullable();
            $table->string('scheme_name', 100)->nullable();
            $table->string('msps_no', 30)->nullable()->index();    // EPF number (API epfNo)

            // ---- Organisation ----
            $table->string('mihcm_department_id', 50)->nullable()->index();
            $table->string('department', 150)->nullable()->index();
            $table->string('mihcm_section_id', 50)->nullable();
            $table->string('mihcm_section', 150)->nullable();
            $table->string('team', 150)->nullable();
            $table->json('mihcm_org_structure')->nullable();       // raw orgStructure, for deeper levels later

            // ---- Reporting person ----
            $table->string('reporting_person_employee_no', 30)->nullable();
            $table->string('reporting_person_name', 150)->nullable();
            $table->string('reporting_person_email', 255)->nullable();

            // ---- MiHCM status (overwritten by every sync) ----
            $table->boolean('mihcm_active')->default(true);
            $table->boolean('mihcm_deleted')->default(false);

            // ---- Local status (set in this system; the sync NEVER touches these) ----
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_payroll_active')->default(true)->index();

            $table->timestamp('first_synced_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
