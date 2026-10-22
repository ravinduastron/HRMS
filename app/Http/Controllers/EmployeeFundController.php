<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\ProvidentFundService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * EPF or MSPS. By default an employee follows the default fund of their scheme (Administration > Schemes > Pay settings).
 * Switching by hand here is an override that the MiHCM sync and scheme changes leave alone, until "Follow scheme default".
 */
class EmployeeFundController extends Controller
{
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $fund = $request->validate(['fund' => ['required', 'in:epf,msps']])['fund'];
        $old = $employee->provident_fund;

        if ($old === $fund) {
            return back();
        }

        $employee->forceFill(['provident_fund' => $fund, 'provident_fund_override' => true])->save();
        Audit::log('employee.fund_changed', $employee, 'Provident fund set by hand: '.strtoupper($old).' → '.strtoupper($fund), ['provident_fund' => $old], ['provident_fund' => $fund]);

        return back()->with('status', 'Provident fund set to '.strtoupper($fund).'. The scheme default no longer applies to this employee.');
    }

    public function followScheme(Employee $employee, ProvidentFundService $funds): RedirectResponse
    {
        if (! $employee->scheme_id) {
            return back()->with('error', 'This employee has no scheme, so there is no scheme default to follow.');
        }

        $old = $employee->provident_fund;
        $employee->forceFill(['provident_fund_override' => false])->save();
        $funds->apply($employee->refresh());
        Audit::log('employee.fund_follows_scheme', $employee, 'Provident fund now follows the scheme default', ['provident_fund' => $old], ['provident_fund' => $employee->provident_fund]);

        return back()->with('status', 'Provident fund now follows the scheme default ('.strtoupper($employee->provident_fund).').');
    }
}
