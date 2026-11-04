<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HirePurchase;
use App\Models\HirePurchaseInstallment;
use App\Models\HirePurchaseVendor;
use App\Models\PayComponent;
use App\Models\Scheme;
use App\Payroll\Statutory\Format;
use App\Services\HirePurchaseService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Hire purchases of the employees in the user's schemes (an employee out of scope gives a 404). */
class HirePurchaseController extends Controller
{
    public function __construct(private HirePurchaseService $service) {}

    public function index(Request $request): View
    {
        $purchases = HirePurchase::with(['employee', 'vendor'])
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('reference', 'like', "%{$s}%")->orWhere('item_description', 'like', "%{$s}%")
                ->orWhereHas('employee', fn ($e) => $e->where('employee_no', 'like', "%{$s}%")->orWhere('full_name', 'like', "%{$s}%")->orWhere('display_name', 'like', "%{$s}%"))))
            ->when(in_array($request->query('status'), ['active', 'completed', 'stopped'], true), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->query('vendor'), fn ($q, $v) => $q->where('hire_purchase_vendor_id', $v))
            ->when($request->query('scheme'), fn ($q, $s) => $q->whereHas('employee', fn ($e) => $e->where('scheme_id', $s)))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        $pending = HirePurchaseInstallment::whereIn('hire_purchase_id', $purchases->pluck('id'))->where('status', 'pending')
            ->orderBy('due_month')->orderBy('seq')->get()->groupBy('hire_purchase_id');

        return view('hire-purchases.index', [
            'purchases' => $purchases,
            'summary' => $purchases->getCollection()->mapWithKeys(fn ($p) => [$p->id => $this->service->summary($pending[$p->id] ?? collect())])->all(),
            'vendors' => HirePurchaseVendor::orderBy('name')->get(), 'schemes' => Scheme::visibleTo($request->user())->orderBy('name')->get(),
        ]);
    }

    /** `?employee_id=` pre-selects an employee (used by the Hire purchases tab of the employee page). The scheme is only a filter and is not stored. */
    public function create(Request $request): View
    {
        $user = $request->user();
        $canNone = $user->can('schemes.all');
        $employee = $request->filled('employee_id') ? Employee::query()->find((int) $request->query('employee_id')) : null; // the scheme scope applies

        $schemeOptions = Scheme::visibleTo($user)->orderBy('name')->get()->map(fn ($s) => ['value' => (string) $s->id, 'label' => $s->name])->values();
        if ($canNone) {
            $schemeOptions->push(['value' => 'none', 'label' => 'No scheme']);
        }

        $components = PayComponent::with('versions')->where('type', 'deduction')->where('is_active', true)->orderBy('code')->get()->filter(fn ($c) => $c->latestVersion())->values();

        return view('hire-purchases.create', [
            'employee' => $employee, 'schemeOptions' => $schemeOptions->values(),
            'initialScheme' => (string) old('scheme', $employee ? ($employee->scheme_id ?? ($canNone ? 'none' : '')) : ''),
            'vendors' => HirePurchaseVendor::where('is_active', true)->orderBy('name')->get(),
            'components' => $components->map(fn ($c) => ['value' => $c->id, 'label' => $c->code.' · '.$c->name])->values(),
            'defaultComponent' => (string) ($components->firstWhere('code', 'HIRE_PURCHASE')?->id ?? ''),
        ]);
    }

    /** Active employees of one scheme (or "none") that the user may see, for the employee dropdown of the new-hire-purchase form. */
    public function employees(Request $request): JsonResponse
    {
        $user = $request->user();
        $scheme = (string) $request->query('scheme');

        $query = Employee::query()->where('is_active', true); // the scheme scope applies on top
        if ($scheme === 'none') {
            abort_unless($user->can('schemes.all'), 403);
            $query->whereNull('scheme_id');
        } elseif (ctype_digit($scheme)) {
            abort_unless(Scheme::visibleTo($user)->whereKey((int) $scheme)->exists(), 404);
            $query->where('scheme_id', (int) $scheme);
        } else {
            abort(422, 'Choose a scheme.');
        }

        return response()->json(
            $query->orderBy('employee_no')->get(['id', 'employee_no', 'full_name', 'display_name'])
                ->map(fn ($e) => ['value' => $e->id, 'label' => $e->employee_no.' · '.($e->display_name ?? $e->full_name)])->values(),
        )->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'], 'hire_purchase_vendor_id' => ['required', 'integer', 'exists:hire_purchase_vendors,id'],
            'pay_component_id' => ['required', 'integer', 'exists:pay_components,id'],
            'reference' => ['nullable', 'string', 'max:40'], 'item_description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'string', 'max:20'], 'term' => ['required', 'integer', 'between:1,120'],
            'first_due_month' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'notes' => ['nullable', 'string', 'max:255'],
        ], ['employee_id.required' => 'Choose a scheme and then an employee.']);

        $employee = Employee::query()->find($data['employee_id']); // the scheme scope applies
        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => 'This employee was not found, or is not in your schemes.']);
        }

        $hp = $this->service->create($employee, HirePurchaseVendor::findOrFail($data['hire_purchase_vendor_id']), PayComponent::findOrFail($data['pay_component_id']),
            array_merge($data, ['first_due_month' => $data['first_due_month'].'-01']), $request->user());

        return redirect()->route('hire-purchases.show', $hp)->with('status', "Hire purchase {$hp->reference} created.");
    }

    public function show(HirePurchase $hirePurchase): View
    {
        $hirePurchase->load(['employee.scheme', 'vendor', 'component']);
        $all = $hirePurchase->installments()->get();
        $pending = $all->where('status', 'pending')->values();

        return view('hire-purchases.show', [
            'purchase' => $hirePurchase,
            'schedule' => $all->reject(fn ($i) => $i->status === 'replaced')->values(),
            'earlier' => $all->where('status', 'replaced')->values(),
            'pending' => $pending, 'summary' => $this->service->summary($pending),
            'changes' => $hirePurchase->changes()->get(),
            'left' => HirePurchaseService::hasLeft($hirePurchase->employee),
        ]);
    }

    public function changePreview(Request $request, HirePurchase $hirePurchase): JsonResponse
    {
        $d = $this->changeInput($request);
        $p = $this->service->changePlan($hirePurchase, Carbon::createFromFormat('Y-m-d', $d['effective_month'].'-01'), $d['new_amount'], (int) $d['new_count'], $this->restart($d));

        return response()->json([
            'old_amount' => Format::money($p['old_amount']), 'new_amount' => Format::money($p['amount']),
            'old_count' => $p['old_count'], 'new_count' => $p['count'],
            'old_total' => Format::money($p['old_total']), 'new_total' => Format::money($p['new_total']),
            'old_range' => $p['old_first']->format('M Y').' to '.$p['old_last']->format('M Y'), 'new_range' => $p['new_first']->format('M Y').' to '.$p['new_last']->format('M Y'),
        ]);
    }

    public function changeStore(Request $request, HirePurchase $hirePurchase): RedirectResponse
    {
        $d = $this->changeInput($request) + $request->validate(['change_note' => ['required', 'string', 'max:500']]);
        $this->service->changeFuture($hirePurchase, Carbon::createFromFormat('Y-m-d', $d['effective_month'].'-01'), $d['new_amount'], (int) $d['new_count'], $this->restart($d), $d['change_note'], $request->user());

        return redirect()->route('hire-purchases.show', $hirePurchase)->with('status', 'Future deductions changed. The unpaid installments were replaced.');
    }

    public function stop(Request $request, HirePurchase $hirePurchase): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $n = $this->service->stop($hirePurchase, $d['reason'], $request->user());

        return redirect()->route('hire-purchases.show', $hirePurchase)->with('status', "Deductions stopped. {$n} unpaid installment(s) will not be deducted.");
    }

    private function changeInput(Request $request): array
    {
        return $request->validate([
            'effective_month' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'new_amount' => ['required', 'string', 'max:20'],
            'new_count' => ['required', 'integer', 'between:1,120'], 'restart_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);
    }

    private function restart(array $d): ?Carbon
    {
        return ! empty($d['restart_month']) ? Carbon::createFromFormat('Y-m-d', $d['restart_month'].'-01') : null;
    }
}
