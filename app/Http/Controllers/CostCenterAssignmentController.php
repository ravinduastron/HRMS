<?php

namespace App\Http\Controllers;

use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EmployeeCostCenter;
use App\Models\Scheme;
use App\Services\CostCenterService;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Assigning cost centers to employees: the bulk screen and the employee page tab. Scheme restrictions apply (out-of-scope employees are 404 / not found). */
class CostCenterAssignmentController extends Controller
{
    private const FILTERS = ['q', 'scheme', 'department', 'payroll', 'mihcm', 'state'];
    private const MAX_ALL = 5000;

    public function __construct(private CostCenterService $service) {}

    public function index(Request $request): View
    {
        $employees = $this->query($request)->orderBy('employee_no')->paginate(50)->withQueryString();
        $nodes = $this->service->nodes();

        return view('payroll.cost-centers.assign', [
            'employees' => $employees, 'current' => $this->service->inForce($employees->pluck('id')->all(), today()),
            'nodes' => $nodes, 'options' => $this->service->leafOptions($nodes),
            'schemes' => Scheme::visibleTo($request->user())->orderBy('name')->get(),
            'departments' => Employee::whereNotNull('department')->distinct()->orderBy('department')->pluck('department'),
            'mihcmValues' => Employee::whereNotNull('mihcm_costcenter')->distinct()->orderBy('mihcm_costcenter')->pluck('mihcm_costcenter'),
            'filters' => $request->only(self::FILTERS),
        ]);
    }

    /** Bulk: the ticked employees, or everyone matching the filters ("all_matching"). */
    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'cost_center_id' => ['required', 'integer', 'exists:cost_centers,id'], 'effective_from' => ['required', 'date'], 'change_note' => ['required', 'string', 'max:500'],
            'all_matching' => ['nullable', 'boolean'], 'employee_ids' => ['nullable', 'array', 'max:2000'], 'employee_ids.*' => ['integer'],
        ], ['cost_center_id.required' => 'Choose a cost center.']);

        if ($request->boolean('all_matching')) {
            $ids = $this->query($request)->limit(self::MAX_ALL + 1)->pluck('id')->all();
            if (count($ids) > self::MAX_ALL) {
                return $this->back($request)->with('error', 'More than '.self::MAX_ALL.' employees match the filters. Narrow the filters and try again.');
            }
        } else {
            $ids = $d['employee_ids'] ?? [];
        }

        try {
            $r = $this->service->assignMany($ids, CostCenter::findOrFail($d['cost_center_id']), Carbon::parse($d['effective_from']), $d['change_note'], $request->user());
        } catch (ValidationException $e) {
            return $this->back($request)->with('error', (string) collect($e->errors())->flatten()->first());
        }

        $msg = "{$r['assigned']} employee(s) assigned".($r['unchanged'] ? ", {$r['unchanged']} already had it" : '').($r['skipped'] ? ", {$r['skipped']} inactive skipped" : '').'.';

        return $this->back($request)->with('status', $msg);
    }

    /** From the Cost center tab of the employee page. */
    public function storeForEmployee(Request $request, Employee $employee): RedirectResponse
    {
        $d = $request->validate([
            'cost_center_id' => ['required', 'integer', 'exists:cost_centers,id'], 'effective_from' => ['required', 'date'], 'change_note' => ['required', 'string', 'max:500'],
        ], ['cost_center_id.required' => 'Choose a cost center.']);

        $back = redirect()->route('employees.show', [$employee, 'tab' => 'costcenter']);
        try {
            $this->service->assign($employee, CostCenter::findOrFail($d['cost_center_id']), Carbon::parse($d['effective_from']), $d['change_note'], $request->user());
        } catch (ValidationException $e) {
            return $back->with('error', (string) collect($e->errors())->flatten()->first());
        }

        return $back->with('status', 'Cost center saved.');
    }

    private function back(Request $request): RedirectResponse
    {
        return redirect()->route('cost-centers.assign', array_filter($request->only(self::FILTERS), fn ($v) => $v !== null && $v !== ''));
    }

    /** Active employees matching the filters (the scheme scope applies). */
    private function query(Request $request): Builder
    {
        $assigned = fn () => EmployeeCostCenter::withoutGlobalScopes()->whereDate('effective_from', '<=', today()->toDateString())->distinct()->pluck('employee_id');
        $mihcm = (string) $request->input('mihcm');
        $state = $request->input('state');

        return Employee::query()->where('is_active', true)->with('scheme')
            ->filter($request->only(['q', 'scheme', 'department', 'payroll']))
            ->when($mihcm === '__empty__', fn ($q) => $q->whereNull('mihcm_costcenter'))
            ->when($mihcm !== '' && $mihcm !== '__empty__', fn ($q) => $q->where('mihcm_costcenter', $mihcm))
            ->when($state === 'none', fn ($q) => $q->whereNotIn('id', $assigned()))
            ->when($state === 'assigned', fn ($q) => $q->whereIn('id', $assigned()));
    }
}
