<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLoadRun;
use App\Models\Employee;
use App\Models\EmployeeSyncRun;
use App\Models\LeaveType;
use App\Models\Scheme;
use Illuminate\View\View;

/** Dashboard: real figures only. Employee counts follow the user's schemes (the Employee scope applies). */
class HomeController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $all = $user->can('schemes.all');
        $cards = [];

        $active = Employee::where('is_active', true)->count();
        $inPayroll = Employee::payrollEligible()->count();
        $link = fn (string $perm, string $route, array $params = []) => $user->can($perm) ? route($route, $params) : null;

        $cards[] = ['label' => 'Active employees', 'value' => number_format($active), 'icon' => 'users',
            'delta' => $all ? 'All schemes' : 'In your schemes', 'trend' => 'up',
            'href' => $link('employees.view', 'employees.index', ['status' => 'active'])];
        $cards[] = ['label' => 'Employees in payroll', 'value' => number_format($inPayroll), 'icon' => 'banknotes',
            'delta' => $active - $inPayroll > 0 ? ($active - $inPayroll).' active but excluded from payroll' : 'Everyone active is included', 'trend' => $active - $inPayroll > 0 ? 'down' : 'up',
            'href' => $link('employees.view', 'employees.index', ['payroll' => 'active'])];

        if ($all) {
            $noScheme = Employee::where('is_active', true)->whereNull('scheme_id')->count();
            $cards[] = ['label' => 'Employees with no scheme', 'value' => number_format($noScheme), 'icon' => 'users',
                'delta' => $noScheme ? 'Only all-scheme users can see them' : 'None', 'trend' => $noScheme ? 'down' : 'up', 'href' => null];

            $noPay = Scheme::whereDoesntHave('paySettings')->count();
            $cards[] = ['label' => 'Schemes without pay settings', 'value' => number_format($noPay), 'icon' => 'library',
                'delta' => $noPay ? 'They cannot be used in a pay run' : 'All schemes are set up', 'trend' => $noPay ? 'down' : 'up',
                'href' => $link('schemes.view', 'schemes.index')];
        }

        if ($user->can('leave-types.manage')) {
            $review = LeaveType::whereNull('is_no_pay')->count();
            $cards[] = ['label' => 'Leave types to review', 'value' => number_format($review), 'icon' => 'calendar',
                'delta' => $review ? 'Mark each as paid or no-pay' : 'All reviewed', 'trend' => $review ? 'down' : 'up',
                'href' => route('leave-types.index')];
        }

        if ($all && $user->can('employees.sync-log')) {
            $run = EmployeeSyncRun::where('status', 'completed')->latest('id')->first();
            $cards[] = ['label' => 'Last MiHCM employee sync', 'value' => $run?->finished_at?->diffForHumans() ?? 'Never', 'icon' => 'clock',
                'delta' => $run ? $run->fetched_count.' records' : 'No sync has completed yet', 'trend' => $run ? 'up' : 'down',
                'href' => route('employees.sync.index')];
        }

        if ($all && $user->can('attendance.load-log')) {
            $run = AttendanceLoadRun::where('status', 'completed')->latest('id')->first();
            $cards[] = ['label' => 'Last attendance load', 'value' => $run?->finished_at?->diffForHumans() ?? 'Never', 'icon' => 'clock',
                'delta' => $run ? $run->from_date->format('d M').' to '.$run->to_date->format('d M Y') : 'No load has completed yet', 'trend' => $run ? 'up' : 'down',
                'href' => route('attendance.load.index')];
        }

        return view('home', [
            'cards' => $cards,
            'noAccess' => ! $all && $user->schemeIds() === [],
        ]);
    }
}
