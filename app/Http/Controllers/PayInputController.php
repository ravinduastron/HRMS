<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayInput;
use App\Models\PayInputHistory;
use App\Models\PayPeriod;
use App\Payroll\Decimal;
use App\Services\PayInputService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Pay inputs of the employees in the user's schemes. Changes are possible only while the pay period is Open. */
class PayInputController extends Controller
{
    public function __construct(private PayInputService $service) {}

    public function index(Request $request): View
    {
        $pick = $this->service->picker($request->user(), (int) $request->query('scheme') ?: null, (int) $request->query('period') ?: null);
        $period = $pick['period'];

        $base = fn () => PayInput::where('pay_period_id', $period?->id ?? 0)
            ->when($request->query('component'), fn ($q, $c) => $q->where('pay_component_id', $c))
            ->when($request->query('q'), fn ($q, $s) => $q->whereHas('employee', fn ($e) => $e->where('employee_no', 'like', "%{$s}%")->orWhere('full_name', 'like', "%{$s}%")->orWhere('display_name', 'like', "%{$s}%")));

        $inputs = $period
            ? $base()->with(['employee', 'component'])
                ->orderBy(Employee::withoutGlobalScopes()->select('employee_no')->whereColumn('employees.id', 'pay_inputs.employee_id'))->orderBy('pay_component_id')
                ->paginate(40)->withQueryString()
            : null;

        $totals = [];
        if ($period) {
            foreach ($base()->get(['pay_component_id', 'employee_id', 'amount']) as $i) {
                $totals[$i->pay_component_id] ??= ['sum' => '0', 'count' => 0];
                $totals[$i->pay_component_id]['sum'] = Decimal::add($totals[$i->pay_component_id]['sum'], (string) $i->amount);
                $totals[$i->pay_component_id]['count']++;
            }
        }

        return view('payroll.inputs.index', $pick + [
            'inputs' => $inputs, 'totals' => $totals, 'components' => $this->service->components(),
            'codes' => \App\Models\PayComponent::whereIn('id', array_keys($totals))->pluck('code', 'id'),
        ]);
    }

    public function entry(Request $request): View
    {
        $pick = $this->service->picker($request->user(), (int) $request->query('scheme') ?: null, (int) $request->query('period') ?: null);
        $period = $pick['period'];
        $components = $this->service->components();
        $component = $components->firstWhere('id', (int) $request->query('component'));

        $employees = $period && $component ? $this->service->eligibleEmployees($period, $component) : collect();
        $current = $period && $component
            ? PayInput::where('pay_period_id', $period->id)->where('pay_component_id', $component->id)->whereIn('employee_id', $employees->pluck('id'))->get()->keyBy('employee_id')
            : collect();
        $payrollCount = $period ? $this->service->eligibleEmployees($period)->count() : 0;

        return view('payroll.inputs.entry', $pick + [
            'components' => $components, 'payComponent' => $component, 'employees' => $employees, 'current' => $current,
            'hidden' => $component ? $payrollCount - $employees->count() : 0,
        ]);
    }

    public function entrySave(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'period' => ['required', 'integer'], 'component' => ['required', 'integer'],
            'amounts' => ['required', 'array'], 'amounts.*' => ['nullable', 'string', 'max:20'],
        ]);
        $period = PayPeriod::findOrFail($d['period']); // the scheme scope applies: a period outside the user's schemes is a 404
        $component = $this->service->components()->firstWhere('id', (int) $d['component']);
        abort_unless($component, 422, 'Choose a component that can be entered per period.');

        $r = $this->service->saveMany($period, $component, $d['amounts'], $request->user());
        $n = $r['created'] + $r['replaced'];

        return redirect()->route('pay-inputs.entry', ['scheme' => $period->scheme_id, 'period' => $period->id, 'payComponent' => $component->id])
            ->with('status', $n ? "{$n} value(s) saved ({$r['created']} new, {$r['replaced']} replaced).".($r['unchanged'] ? " {$r['unchanged']} unchanged." : '') : 'Nothing changed.');
    }

    public function destroy(Request $request, PayInput $input): RedirectResponse
    {
        $this->service->remove($input, $request->user());

        return back()->with('status', 'Pay input removed. The old value stays in the history.');
    }

    public function history(Request $request): View
    {
        $pick = $this->service->picker($request->user(), (int) $request->query('scheme') ?: null, (int) $request->query('period') ?: null);
        $period = $pick['period'];

        $entries = $period
            ? PayInputHistory::with(['employee', 'component'])->where('pay_period_id', $period->id)
                ->when($request->query('component'), fn ($q, $c) => $q->where('pay_component_id', $c))
                ->when($request->query('q'), fn ($q, $s) => $q->whereHas('employee', fn ($e) => $e->where('employee_no', 'like', "%{$s}%")->orWhere('full_name', 'like', "%{$s}%")->orWhere('display_name', 'like', "%{$s}%")))
                ->orderByDesc('id')->paginate(40)->withQueryString()
            : null;

        return view('payroll.inputs.history', $pick + ['entries' => $entries, 'components' => $this->service->components()]);
    }
}
