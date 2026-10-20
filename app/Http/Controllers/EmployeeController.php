<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Bank;
use App\Models\Employee;
use App\Models\EmployeeSyncChange;
use App\Models\EmployeeSyncRun;
use App\Models\LeaveEntry;
use App\Models\LeaveType;
use App\Models\SalaryStructure;
use App\Models\Scheme;
use App\Services\AttendanceSummaryService;
use App\Services\EmployeeSalaryService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class EmployeeController extends Controller
{
    public function __construct(private EmployeeSalaryService $salary, private AttendanceSummaryService $attendanceSummary) {}

    public function index(Request $request): View
    {
        $view = in_array($request->query('view'), ['new', 'deactivated'], true) ? $request->query('view') : 'all';
        $period = in_array($request->query('period'), ['7', '30'], true) ? $request->query('period') : 'last';
        $latestRun = EmployeeSyncRun::where('status', 'completed')->latest('id')->first();

        // The sync log can live in another database, so no joins / whereHas: ask the log for
        // [employee_id => when it happened], then filter employees by those ids.
        $events = fn (string $type) => EmployeeSyncChange::query()
            ->where('change_type', $type)->whereNotNull('employee_id')
            ->when($period === 'last',
                fn ($q) => $q->where('employee_sync_run_id', $latestRun?->id ?? 0),
                fn ($q) => $q->where('created_at', '>=', now()->subDays((int) $period)))
            ->groupBy('employee_id')->selectRaw('employee_id, max(created_at) as event_at')
            ->pluck('event_at', 'employee_id');

        $newMap = $events('created');
        $deactivatedMap = $events('deactivated');
        $map = $view === 'new' ? $newMap : ($view === 'deactivated' ? $deactivatedMap : null);

        $employees = Employee::query()
            ->with('scheme')->filter($request->only(['q', 'status', 'payroll', 'department', 'scheme']))
            ->when($map !== null, function ($q) use ($map) {
                $ids = $map->sortDesc()->keys()->map(fn ($i) => (int) $i)->all();

                return $ids
                    ? $q->whereIn('id', $ids)->orderByRaw('FIELD(id, '.implode(',', $ids).')')
                    : $q->whereRaw('1 = 0');
            }, fn ($q) => $q->orderBy('full_name'))
            ->paginate(20)->withQueryString();

        if ($map !== null) {
            $employees->getCollection()->each(fn ($e) => $e->setAttribute('event_at', $map[$e->id] ?? null));
        }

        return view('employees.index', [
            'employees'   => $employees,
            'view'        => $view,
            'period'      => $period,
            'latestRun'   => $latestRun,
            // counted through Employee so the scheme scope applies (the log itself knows every employee)
            'counts'      => ['all' => Employee::count(), 'new' => Employee::whereIn('id', $newMap->keys())->count(), 'deactivated' => Employee::whereIn('id', $deactivatedMap->keys())->count()],
            'schemes'     => Scheme::visibleTo(auth()->user())->orderBy('name')->get(),
            'departments' => Employee::whereNotNull('department')->distinct()->orderBy('department')->pluck('department'),
        ]);
    }

    public function show(Request $request, Employee $employee): View
    {
        $user = auth()->user();
        $salaryDate = $this->salary->dateOrToday($request->query('salary_date')); // "view the salary as at this date"
        $salary = $user->can('employee-salary.view') ? $this->salary->summary($employee, $salaryDate) : null;
        $canManageSalary = $user->can('employee-salary.manage');

        // Attendance and leave tabs share one date range (default: this month so far)
        [$attFrom, $attTo] = $this->attendanceRange($request);
        $attendance = $user->can('attendance.view') ? [
            'rows' => AttendanceRecord::where('employee_id', $employee->id)->whereBetween('work_date', [$attFrom->toDateString(), $attTo->toDateString()])->orderBy('work_date')->get(),
            'summary' => $this->attendanceSummary->forEmployees([$employee->id], $attFrom, $attTo)[$employee->id],
        ] : null;
        $leave = $user->can('leave.view') ? [
            'entries' => LeaveEntry::where('employee_id', $employee->id)->where('start_date', '<=', $attTo->toDateString())->where('end_date', '>=', $attFrom->toDateString())->orderBy('start_date')->get(),
            'types' => LeaveType::get()->keyBy('code'),
        ] : null;

        return view('employees.show', [
            'employee' => $employee,
            'bankAccounts' => $user->can('bank-accounts.view') ? $employee->bankAccounts()->with(['bank', 'branch'])->orderByDesc('is_active')->orderBy('id')->get() : collect(),
            'banks' => $user->can('bank-accounts.add') ? Bank::with('branches')->orderBy('name')->get() : collect(),
            'changes'  => $employee->syncChanges()->with('run')->latest('id')->limit(30)->get(),
            // salary tab (structures are limited to the employee's own scheme)
            'salary' => $salary,
            'attendance' => $attendance, 'leave' => $leave, 'attFrom' => $attFrom, 'attTo' => $attTo,
            'salaryDate' => $salaryDate,
            'structures' => $canManageSalary && $employee->scheme_id
                ? SalaryStructure::where('is_active', true)->where('scheme_id', $employee->scheme_id)->orderBy('name')->get() : collect(),
            'addable' => $canManageSalary && $salary ? $this->salary->addable($salary['current']) : collect(),
        ]);
    }

    /** The range both the Attendance and Leave tabs show (at most 100 days). */
    private function attendanceRange(Request $request): array
    {
        $parse = function (?string $v): ?Carbon {
            if (! $v || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return null;
            }
            try {
                return Carbon::parse($v)->startOfDay();
            } catch (Throwable) {
                return null;
            }
        };
        $from = $parse($request->query('att_from')) ?? today()->startOfMonth();
        $to = $parse($request->query('att_to')) ?? today();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ((int) $from->diffInDays($to, true) > 99) {
            $from = $to->copy()->subDays(99);
        }

        return [$from, $to];
    }

    public function toggle(Request $request, Employee $employee): RedirectResponse
    {
        $field = $request->validate(['field' => ['required', 'in:is_active,is_payroll_active']])['field'];
        $label = $field === 'is_active' ? 'Employee status' : 'Payroll status';
        $old = (bool) $employee->{$field};

        // The MiHCM sync never writes these two columns, so this choice survives every sync.
        $employee->forceFill([$field => ! $old])->save();
        Audit::log('employee.status_changed', $employee, "{$label}: ".($old ? 'activated → deactivated' : 'deactivated → activated'),
            [$field => $old], [$field => ! $old]);

        return back()->with('status', "{$label} ".($old ? 'deactivated.' : 'activated.'));
    }
}
