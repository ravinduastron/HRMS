<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** EPF or MSPS: MiHCM sends one number for both, so the fund type is set here and never overwritten by the sync. */
class EmployeeFundController extends Controller
{
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $fund = $request->validate(['fund' => ['required', 'in:epf,msps']])['fund'];
        $old = $employee->provident_fund;

        if ($old === $fund) {
            return back();
        }

        $employee->forceFill(['provident_fund' => $fund])->save();
        Audit::log('employee.fund_changed', $employee, 'Provident fund: '.strtoupper($old).' → '.strtoupper($fund), ['provident_fund' => $old], ['provident_fund' => $fund]);

        return back()->with('status', 'Provident fund set to '.strtoupper($fund).'.');
    }
}
