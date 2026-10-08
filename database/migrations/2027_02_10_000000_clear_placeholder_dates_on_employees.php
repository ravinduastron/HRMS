<?php

use App\Support\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MiHCM's "no date" placeholder (1901-01-01 or earlier) was stored as a real date before the mapper learned to ignore it.
 * Clearing it now (before the next sync) means the sync will not report a change for every affected employee.
 */
return new class extends Migration
{
    private array $columns = ['birthday', 'date_joined', 'contract_expiry_date', 'resigned_date', 'resignation_effect_date', 'last_working_date'];

    public function up(): void
    {
        $cleared = [];

        foreach ($this->columns as $column) {
            $n = DB::table('employees')->where($column, '<=', '1901-01-01')->update([$column => null]);
            if ($n) {
                $cleared[$column] = $n;
            }
        }

        Audit::log('employee.placeholder_dates_cleared', null, 'Cleared MiHCM placeholder dates (1901-01-01) on employees', context: ['cleared' => $cleared]);
    }

    public function down(): void
    {
        // Intentionally empty: the placeholder carried no information.
    }
};
