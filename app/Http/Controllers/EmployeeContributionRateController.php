<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeContributionRate;
use App\Models\Scheme;
use App\Services\StatutoryService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** An employee's own EPF/MSPS contribution rate. Scheme restrictions apply (an employee out of scope is a 404). */
class EmployeeContributionRateController extends Controller
{
    public function __construct(private StatutoryService $statutory) {}

    public function index(Request $request): View
    {
        $employees = Employee::query()->with('scheme')->where('is_active', true)
            ->filter($request->only(['q', 'scheme']))
            ->when($request->query('own') === '1', fn ($q) => $q->whereIn('id', $this->employeesWithOwnRate()))
            ->orderBy('full_name')->paginate(20)->withQueryString();

        // The rate record in force today for these employees (newest first, so the first per employee wins)
        $own = EmployeeContributionRate::whereIn('employee_id', $employees->pluck('id'))->where('approval_status', 'approved')
            ->whereDate('effective_from', '<=', today()->toDateString())->orderByDesc('effective_from')->orderByDesc('id')->get()
            ->groupBy('employee_id')->map->first();

        return view('statutory.employee-rates.index', [
            'employees' => $employees, 'own' => $own, 'rates' => $this->statutory->ratesAt()?->rates(),
            'schemes' => Scheme::visibleTo($request->user())->orderBy('name')->get(),
        ]);
    }

    public function show(Employee $employee): View
    {
        return view('statutory.employee-rates.show', [
            'employee' => $employee->load('scheme'),
            'history' => EmployeeContributionRate::where('employee_id', $employee->id)->orderByDesc('effective_from')->orderByDesc('id')->get(),
            'effective' => $this->statutory->effectiveEmployeeRate($employee),
            'rates' => $this->statutory->ratesAt()?->rates(),
        ]);
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $request->merge(['employee_rate' => is_string($request->input('employee_rate')) ? str_replace([',', ' ', '%'], '', $request->input('employee_rate')) : null]);
        $data = $request->validate([
            'employee_rate' => ['nullable', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'effective_from' => ['required', 'date'], 'change_note' => ['required', 'string', 'max:500'],
        ], ['employee_rate.*' => 'Enter a rate from 0 to 100 (up to 4 decimals), or leave it empty to use the default.']);

        $this->statutory->setEmployeeRate($employee, $data['employee_rate'] ?? null, Carbon::parse($data['effective_from']), $data['change_note'], $request->user());

        return redirect()->route('statutory.employee-rates.show', $employee)->with('status', 'Employee contribution rate saved.');
    }

    /** Ids of employees whose rate in force today is their own (not the default). */
    private function employeesWithOwnRate(): array
    {
        return EmployeeContributionRate::where('approval_status', 'approved')->whereDate('effective_from', '<=', today()->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')->get(['employee_id', 'employee_rate'])
            ->groupBy('employee_id')->map->first()->filter(fn ($r) => $r->employee_rate !== null)->keys()->all();
    }
}
