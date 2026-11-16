<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\SalaryAdjustmentBatch;
use App\Models\SalaryAdjustmentRow;
use App\Models\Scheme;
use App\Payroll\SalaryAdjustment;
use App\Services\SalaryAdjustmentService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Salary adjustments of the schemes the user may see (out-of-scope adjustments are 404). Nothing changes until an adjustment is applied. */
class SalaryAdjustmentController extends Controller
{
    public function __construct(private SalaryAdjustmentService $service) {}

    public function index(Request $request): View
    {
        $status = array_key_exists((string) $request->query('status'), SalaryAdjustmentBatch::STATUSES) ? $request->query('status') : null;

        return view('salary.adjustments.index', [
            'batches' => SalaryAdjustmentBatch::with('scheme')->when($status, fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('scheme'), fn ($q, $s) => $q->where('scheme_id', $s))->latest('id')->paginate(20)->withQueryString(),
            'status' => $status, 'schemes' => Scheme::visibleTo($request->user())->orderBy('name')->get(),
        ]);
    }

    /* ------------------------------ on screen ------------------------------ */

    public function create(Request $request): View
    {
        $pick = $this->pick($request);
        $scheme = $pick['scheme'];
        $cfg = null;
        $hidden = 0;

        if ($scheme) {
            $employees = Employee::query()->payrollEligible()->where('scheme_id', $scheme->id)->orderBy('employee_no')->get();
            $salary = $this->service->salaryAt($employees->pluck('id')->all(), $pick['date']);
            $components = $this->service->components();
            $ids = $components->pluck('id')->all();

            $rows = $employees->map(fn ($e) => [
                'id' => $e->id, 'no' => $e->employee_no, 'name' => $e->display_name ?? $e->full_name ?? $e->employee_no, 'dept' => $e->department ?? '',
                'comps' => collect($salary[$e->id]['lines'] ?? [])->filter(fn ($l, $cid) => $l['amount'] !== null && in_array($cid, $ids, true))->keys()->map(fn ($i) => (string) $i)->values()->all(),
            ])->filter(fn ($r) => $r['comps'])->values();
            $hidden = $employees->count() - $rows->count();

            $cfg = ['rows' => $rows->all(), 'components' => $components->map(fn ($c) => ['id' => (string) $c->id, 'code' => $c->code, 'name' => $c->name])->values()->all()];
        }

        return view('salary.adjustments.create', $pick + ['cfg' => $cfg, 'hidden' => $hidden]);
    }

    public function store(Request $request): RedirectResponse
    {
        $allowed = Scheme::visibleTo($request->user())->pluck('id')->all();
        $d = $request->validate([
            'scheme' => ['required', 'integer', Rule::in($allowed)], 'effective_from' => ['required', 'date'], 'reason' => ['required', 'string', 'max:500'],
            'mode' => ['required', Rule::in(array_keys(SalaryAdjustment::MODES))], 'value' => ['required', 'string', 'max:20'],
            'component_ids' => ['required', 'array', 'min:1', 'max:50'], 'component_ids.*' => ['integer'],
            'employee_ids' => ['required', 'array', 'min:1', 'max:2000'], 'employee_ids.*' => ['integer'],
        ], ['scheme.in' => 'You can only use schemes that are allocated to you.', 'employee_ids.required' => 'Choose at least one employee.', 'component_ids.required' => 'Choose at least one component.']);

        if (SalaryAdjustment::value($d['mode'], $d['value']) === null) {
            throw ValidationException::withMessages(['value' => SalaryAdjustment::valueHelp($d['mode'])]);
        }

        $scheme = Scheme::findOrFail($d['scheme']);
        $compIds = array_values(array_unique(array_map('intval', $d['component_ids'])));
        $empIds = array_values(array_unique(array_map('intval', $d['employee_ids'])));

        $components = $this->service->components()->whereIn('id', $compIds)->values();
        if ($components->count() !== count($compIds)) {
            throw ValidationException::withMessages(['component_ids' => 'A chosen component is inactive or does not carry an amount.']);
        }
        $employees = Employee::query()->payrollEligible()->where('scheme_id', $scheme->id)->whereIn('id', $empIds)->orderBy('employee_no')->get(); // the scheme scope applies
        if ($employees->count() !== count($empIds)) {
            throw ValidationException::withMessages(['employee_ids' => 'An employee was not found, is not in this scheme, or is not in payroll.']);
        }

        $entries = [];
        foreach ($employees as $e) {
            foreach ($components as $c) {
                $entries[] = ['row' => null, 'employee_no' => $e->employee_no, 'component' => $c->code, 'mode_raw' => $d['mode'], 'value_raw' => $d['value']];
            }
        }

        $batch = $this->service->stage($scheme, Carbon::parse($d['effective_from']), $d['reason'], 'screen', null, $entries, $request->user(), true);

        return redirect()->route('salary-adjustments.show', $batch)->with('status', 'Review the old and new amounts, then confirm.');
    }

    /* ------------------------------ Excel ------------------------------ */

    public function importIndex(Request $request): View
    {
        return view('salary.adjustments.import', $this->pick($request) + [
            'components' => $this->service->components(),
            'batches' => SalaryAdjustmentBatch::with('scheme')->where('source', 'import')->latest('id')->limit(15)->get(),
        ]);
    }

    public function template(Request $request): Response
    {
        $d = $request->validate(['scheme' => ['required', 'integer'], 'date' => ['required', 'date'], 'components' => ['nullable', 'array'], 'components.*' => ['integer']]);
        $scheme = Scheme::visibleTo($request->user())->findOrFail($d['scheme']);
        $date = Carbon::parse($d['date'])->startOfDay();
        $show = $request->user()->can('employee-salary.reveal'); // the sheet prints current salaries, so it follows the same permission as "Show amounts"

        $binary = $this->service->template($scheme, $date, array_map('intval', $d['components'] ?? []), $show);
        Audit::log('salary_adjustment.template_downloaded', $scheme, "Salary adjustment sheet downloaded for {$scheme->name}", context: ['scheme_id' => $scheme->id, 'as_at' => $date->toDateString(), 'amounts_included' => $show]);

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="salary-adjustment-scheme'.$scheme->id.'-'.$date->format('Y-m-d').'.xlsx"', 'Cache-Control' => 'no-store',
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $allowed = Scheme::visibleTo($request->user())->pluck('id')->all();
        $d = $request->validate([
            'scheme' => ['required', 'integer', Rule::in($allowed)], 'effective_from' => ['required', 'date'], 'reason' => ['required', 'string', 'max:500'],
            'file' => ['required', 'file', 'extensions:xlsx', 'max:8192'],
        ], ['file.extensions' => 'Upload an Excel .xlsx file (use the template).', 'file.max' => 'The file is too large.', 'scheme.in' => 'You can only use schemes that are allocated to you.']);

        $file = $request->file('file');
        $batch = $this->service->stageFile($file->getRealPath(), $file->getClientOriginalName(), Scheme::findOrFail($d['scheme']), Carbon::parse($d['effective_from']), $d['reason'], $request->user());

        return redirect()->route('salary-adjustments.show', $batch)->with('status', 'File checked. Review the result, then confirm.');
    }

    /* ------------------------------ one adjustment ------------------------------ */

    public function show(Request $request, SalaryAdjustmentBatch $batch): View
    {
        $status = in_array($request->query('status'), ['valid', 'error', 'applied'], true) ? $request->query('status') : null;
        $batch->load('scheme');

        return view('salary.adjustments.show', [
            'batch' => $batch, 'status' => $status,
            'rows' => $batch->rows()->when($status, fn ($q, $s) => $q->where('status', $s))->paginate(40)->withQueryString(),
            'warningRows' => $batch->rows()->whereNotNull('warnings')->count(),
            'canAct' => $request->user()->can($this->permission($batch)),
            'canRollback' => $request->user()->can('salary-adjustments.rollback'),
        ]);
    }

    /** Old and new amounts of the rows on the page, on demand, always logged. */
    public function reveal(Request $request, SalaryAdjustmentBatch $batch): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('rows')))->map(fn ($i) => (int) $i)->filter()->take(100)->all();
        $rows = SalaryAdjustmentRow::where('salary_adjustment_batch_id', $batch->id)->whereIn('id', $ids)->get();
        Audit::log('salary_adjustment.revealed', $batch, "Salary adjustment #{$batch->id} amounts viewed", context: ['batch_id' => $batch->id, 'rows' => $rows->count(), 'scheme_id' => $batch->scheme_id]);

        return response()->json([
            'rows' => $rows->mapWithKeys(fn ($r) => [$r->id => ['value' => $r->data['value'] ?? null, 'current' => $r->data['current'] ?? null, 'new' => $r->data['new'] ?? null]]),
            'seconds' => (int) config('salary.reveal_seconds'),
        ])->header('Cache-Control', 'no-store');
    }

    public function apply(Request $request, SalaryAdjustmentBatch $batch): RedirectResponse
    {
        abort_unless($request->user()->can($this->permission($batch)), 403);

        return $this->attempt($batch, function () use ($batch, $request) {
            $r = $this->service->apply($batch, $request->user());

            return "Adjustment applied: {$r['applied']} amount(s) changed for {$r['employees']} employee(s)".($r['unchanged'] ? ", {$r['unchanged']} unchanged." : '.');
        });
    }

    public function discard(Request $request, SalaryAdjustmentBatch $batch): RedirectResponse
    {
        abort_unless($request->user()->can($this->permission($batch)), 403);

        return $this->attempt($batch, function () use ($batch) {
            $this->service->discard($batch);

            return 'Adjustment discarded. Nothing was changed.';
        });
    }

    public function rollback(Request $request, SalaryAdjustmentBatch $batch): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->attempt($batch, function () use ($batch, $d, $request) {
            $n = $this->service->rollback($batch, $d['reason'], $request->user());

            return "Adjustment rolled back: the old amounts were restored for {$n} employee(s).";
        });
    }

    /* ------------------------------ internals ------------------------------ */

    /** Excel adjustments need the import permission, screen ones the manage permission. */
    private function permission(SalaryAdjustmentBatch $batch): string
    {
        return $batch->source === 'import' ? 'salary-adjustments.import' : 'salary-adjustments.manage';
    }

    private function pick(Request $request): array
    {
        $schemes = Scheme::visibleTo($request->user())->orderBy('name')->get();
        $date = $request->query('date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('date')) ? Carbon::parse($request->query('date'))->startOfDay() : today();

        return ['schemes' => $schemes, 'scheme' => $schemes->firstWhere('id', (int) $request->query('scheme')) ?? $schemes->first(), 'date' => $date];
    }

    private function attempt(SalaryAdjustmentBatch $batch, callable $action): RedirectResponse
    {
        try {
            return redirect()->route('salary-adjustments.show', $batch)->with('status', $action());
        } catch (ValidationException $e) {
            return redirect()->route('salary-adjustments.show', $batch)->with('error', (string) collect($e->errors())->flatten()->first());
        }
    }
}
