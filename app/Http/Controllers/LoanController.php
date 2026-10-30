<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanType;
use App\Models\Scheme;
use App\Payroll\Loans\LoanSchedule;
use App\Payroll\Statutory\Format;
use App\Services\LoanService;
use App\Services\LoanValuationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Loans of the employees in the user's schemes (an employee out of scope gives a 404). */
class LoanController extends Controller
{
    public function __construct(private LoanService $loans, private LoanValuationService $valuation) {}

    public function index(Request $request): View
    {
        $loans = Loan::with(['employee', 'type'])
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('reference', 'like', "%{$s}%")
                ->orWhereHas('employee', fn ($e) => $e->where('employee_no', 'like', "%{$s}%")->orWhere('full_name', 'like', "%{$s}%")->orWhere('display_name', 'like', "%{$s}%"))))
            ->when(in_array($request->query('status'), ['active', 'settled', 'cancelled'], true), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->query('type'), fn ($q, $t) => $q->where('loan_type_id', $t))
            ->when($request->query('scheme'), fn ($q, $s) => $q->whereHas('employee', fn ($e) => $e->where('scheme_id', $s)))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        $next = LoanInstallment::whereIn('loan_id', $loans->pluck('id'))->where('status', 'pending')->orderBy('due_month')->orderBy('seq')->get()->groupBy('loan_id')->map->first();

        return view('loans.index', [
            'loans' => $loans, 'outstanding' => $this->loans->outstandingFor($loans->getCollection()), 'next' => $next,
            'types' => LoanType::orderBy('name')->get(), 'schemes' => Scheme::visibleTo($request->user())->orderBy('name')->get(),
        ]);
    }

    /**
     * The employee is chosen from the user's own schemes (scheme first, then employee). `?employee_id=` pre-selects one
     * (used by the Loans tab of the employee page). The scheme is only a filter: it is not stored on the loan.
     */
    public function create(Request $request): View
    {
        $user = $request->user();
        $canNone = $user->can('schemes.all'); // employees without a scheme are visible to all-scheme users only
        $employee = $request->filled('employee_id') ? Employee::query()->find((int) $request->query('employee_id')) : null; // the scheme scope applies

        $schemeOptions = Scheme::visibleTo($user)->orderBy('name')->get()->map(fn ($s) => ['value' => (string) $s->id, 'label' => $s->name])->values();
        if ($canNone) {
            $schemeOptions->push(['value' => 'none', 'label' => 'No scheme']);
        }

        $initialScheme = (string) old('scheme', $employee ? ($employee->scheme_id ?? ($canNone ? 'none' : '')) : '');

        return view('loans.create', [
            'types' => LoanType::where('is_active', true)->orderBy('name')->get(),
            'employee' => $employee, 'schemeOptions' => $schemeOptions->values(), 'initialScheme' => $initialScheme,
        ]);
    }

    /** Active employees of one scheme (or "none") that the user may see, for the employee dropdown of the new-loan form. */
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
            'employee_id' => ['required', 'integer'], 'loan_type_id' => ['required', 'integer', 'exists:loan_types,id'],
            'reference' => ['nullable', 'string', 'max:40'], 'amount' => ['required', 'string', 'max:20'], 'rate' => ['nullable', 'string', 'max:10'],
            'term' => ['required', 'integer', 'between:1,600'], 'disbursed_on' => ['required', 'date'], 'first_due_month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['employee_id.required' => 'Choose a scheme and then an employee.']);

        $employee = Employee::query()->find($data['employee_id']); // the scheme scope applies: an employee outside the user's schemes is not found
        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => 'This employee was not found, or is not in your schemes.']);
        }

        $loan = $this->loans->create($employee, LoanType::findOrFail($data['loan_type_id']), array_merge($data, ['first_due_month' => $data['first_due_month'].'-01']), $request->user());

        return redirect()->route('loans.show', $loan)->with('status', "Loan {$loan->reference} created.");
    }

    /** Schedule summary for the "new loan" form. Nothing is saved. */
    public function preview(Request $request): JsonResponse
    {
        $d = $request->validate(['loan_type_id' => ['required', 'integer', 'exists:loan_types,id'], 'amount' => ['required', 'string'], 'rate' => ['nullable', 'string'], 'term' => ['required', 'integer', 'between:1,600']]);
        $p = $this->loans->preview(LoanType::findOrFail($d['loan_type_id']), $d['amount'], $d['rate'] ?? null, (int) $d['term']);
        $line = fn ($r) => ['capital' => Format::money($r['principal']), 'interest' => Format::money($r['interest']), 'total' => Format::money($r['total'])];

        return response()->json(['first' => $line($p['first']), 'last' => $line($p['last']), 'interest' => Format::money($p['totals']['interest']), 'repayable' => Format::money($p['totals']['total'])]);
    }

    public function show(Loan $loan): View
    {
        $loan->load(['employee.scheme', 'type']);
        $all = $loan->installments()->get();

        return view('loans.show', [
            'loan' => $loan,
            'outstanding' => $this->loans->outstanding($loan),
            'schedule' => $all->reject(fn ($i) => $i->status === 'superseded')->values(),
            'earlier' => $all->where('status', 'superseded')->values(),
            'pending' => $all->where('status', 'pending')->values(),
            'rateChanges' => $loan->rateChanges()->get(),
            'ledger' => $loan->ledger()->get(),
            'atStart' => $this->valuation->fairValueAtStart($loan),
            'rateNow' => $loan->status === 'active' ? $loan->current_rate : null,
        ]);
    }

    public function ratePreview(Request $request, Loan $loan): JsonResponse
    {
        $d = $request->validate(['effective_month' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'new_rate' => ['required', 'string', 'max:10']]);
        $p = $this->loans->rateChangePlan($loan, Carbon::createFromFormat('Y-m-d', $d['effective_month'].'-01'), $d['new_rate']);

        return response()->json([
            'outstanding' => Format::money($p['outstanding']), 'count' => $p['count'],
            'old_installment' => Format::money($p['old_installment']), 'new_installment' => Format::money($p['new_installment']),
            'old_interest' => Format::money($p['old_interest']), 'new_interest' => Format::money($p['new_interest']),
            'old_total' => Format::money($p['old_total']), 'new_total' => Format::money($p['new_total']),
        ]);
    }

    public function rateStore(Request $request, Loan $loan): RedirectResponse
    {
        $d = $request->validate(['effective_month' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'new_rate' => ['required', 'string', 'max:10'], 'change_note' => ['required', 'string', 'max:500']]);
        $this->loans->changeRate($loan, Carbon::createFromFormat('Y-m-d', $d['effective_month'].'-01'), $d['new_rate'], $d['change_note'], $request->user());

        return redirect()->route('loans.show', $loan)->with('status', 'Interest rate changed. The remaining installments were re-calculated.');
    }

    public function settle(Request $request, Loan $loan): View
    {
        $date = $this->date($request->query('date')) ?? today();
        $ignore = $request->boolean('ignore') && $request->user()->can('loans.waive-interest');
        $quote = null;
        $error = null;
        try {
            $quote = $this->loans->quote($loan->load(['employee', 'type']), $date, $ignore);
        } catch (ValidationException $e) {
            $error = (string) collect($e->errors())->flatten()->first();
        }

        return view('loans.settle', ['loan' => $loan, 'date' => $date, 'ignore' => $ignore, 'quote' => $quote, 'error' => $error]);
    }

    public function settleStore(Request $request, Loan $loan): RedirectResponse
    {
        $d = $request->validate([
            'date' => ['required', 'date'], 'ignore' => ['nullable', 'boolean'], 'reason' => ['nullable', 'string', 'max:500'],
            'received_on' => ['required', 'date'], 'receipt' => ['nullable', 'string', 'max:60'],
        ]);
        $ignore = $request->boolean('ignore');
        abort_if($ignore && ! $request->user()->can('loans.waive-interest'), 403, 'You are not allowed to ignore the interest.');

        $this->loans->settle($loan, Carbon::parse($d['date'])->startOfDay(), $ignore, $d['reason'] ?? null, Carbon::parse($d['received_on'])->startOfDay(), $d['receipt'] ?? null, $request->user());

        return redirect()->route('loans.show', $loan)->with('status', 'Loan settled. The cash receipt is recorded.');
    }

    public function cancel(Request $request, Loan $loan): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->loans->cancel($loan, $d['reason'], $request->user());

        return redirect()->route('loans.show', $loan)->with('status', 'Loan cancelled.');
    }

    private function date(?string $v): ?Carbon
    {
        return $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? Carbon::parse($v)->startOfDay() : null;
    }
}
