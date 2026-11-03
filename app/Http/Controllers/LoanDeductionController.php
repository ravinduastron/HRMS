<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayComponent;
use App\Models\Scheme;
use App\Payroll\Decimal;
use App\Services\LoanService;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What loans take from pay in a month. Three views of the same figures:
 *   loan       capital and interest of every loan (the detail)
 *   component  one column per pay item (what the paysheet shows component-wise)
 *   group      one column per pay component group (what the paysheet shows group-wise)
 */
class LoanDeductionController extends Controller
{
    public function __construct(private LoanService $loans) {}

    public function index(Request $request): View
    {
        return view('loans.deductions', $this->data($request) + ['schemes' => Scheme::visibleTo($request->user())->orderBy('name')->get()]);
    }

    public function export(Request $request)
    {
        $d = $this->data($request);
        $file = 'loan-deductions-'.$d['by'].'-'.$d['month']->format('Y-m').'.csv';

        if ($d['matrix']) {
            $m = $d['matrix'];
            $head = array_merge(['Employee No', 'Name'], array_column($m['columns'], 'label'), ['Total']);
            $rows = [];
            foreach ($m['rows'] as $r) {
                $rows[] = array_merge([$r['employee']->employee_no, $r['employee']->display_name ?? $r['employee']->full_name],
                    array_map(fn ($k) => $r['amounts'][$k] ?? '0.00', array_keys($m['columns'])), [$r['total']]);
            }
            $rows[] = array_merge(['', 'TOTAL'], array_map(fn ($k) => $m['totals'][$k] ?? '0.00', array_keys($m['columns'])), [$m['grand']]);

            return Csv::download($file, $head, $rows);
        }

        $rows = [];
        foreach ($d['rows'] as $r) {
            $rows[] = [$r['employee']->employee_no, $r['employee']->display_name ?? $r['employee']->full_name, $r['line']['reference'], $r['line']['type'], $r['line']['seq'],
                Carbon::parse($r['line']['due_month'])->format('M Y'), $r['line']['capital'], $r['line']['interest'], Decimal::add($r['line']['capital'], $r['line']['interest']), $r['line']['overdue'] ? 'Overdue' : ''];
        }
        $rows[] = ['', 'TOTAL', '', '', '', '', $d['totals']['capital'], $d['totals']['interest'], $d['totals']['total'], ''];

        return Csv::download($file, ['Employee No', 'Name', 'Loan', 'Type', 'Installment', 'Due', 'Capital', 'Interest', 'Total', 'Note'], $rows);
    }

    /** Until the pay run records deductions itself, this rolls the month's installments forward. */
    public function record(Request $request): RedirectResponse
    {
        $d = $request->validate(['month' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'scheme' => ['nullable', 'integer']]);
        $r = $this->loans->recordMonth(Carbon::createFromFormat('Y-m-d', $d['month'].'-01'), $request->user(), $d['scheme'] ?? null);

        return redirect()->route('loans.deductions', array_filter(['month' => $d['month'], 'scheme' => $d['scheme'] ?? null]))
            ->with('status', "{$r['count']} installment(s) recorded as deducted.");
    }

    private function data(Request $request): array
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::createFromFormat('Y-m-d', $request->query('month').'-01') : today()->startOfMonth();
        $scheme = $request->query('scheme') ? (int) $request->query('scheme') : null;
        $by = in_array($request->query('by'), ['component', 'group'], true) ? $request->query('by') : 'loan';
        $ids = $scheme ? Employee::query()->where('scheme_id', $scheme)->pluck('id')->all() : null;

        $due = $this->loans->dueForPeriod($month, $ids);
        $employees = Employee::query()->whereIn('id', array_keys($due))->get()->keyBy('id')->sortBy('employee_no');

        $rows = [];
        $t = ['capital' => '0', 'interest' => '0'];
        foreach ($employees as $id => $e) {
            foreach ($due[$id]['lines'] as $line) {
                $rows[] = ['employee' => $e, 'line' => $line];
                $t['capital'] = Decimal::add($t['capital'], $line['capital']);
                $t['interest'] = Decimal::add($t['interest'], $line['interest']);
            }
        }

        return [
            'month' => $month, 'scheme' => $scheme, 'by' => $by, 'rows' => $rows, 'overdue' => collect($rows)->where('line.overdue', true)->count(),
            'matrix' => $by === 'loan' ? null : $this->matrix($by, $employees, $due),
            'totals' => ['capital' => Decimal::round($t['capital'], 2), 'interest' => Decimal::round($t['interest'], 2), 'total' => Decimal::round(Decimal::add($t['capital'], $t['interest']), 2)],
            'missingComponents' => collect($rows)->filter(fn ($r) => ! $r['line']['capital_component_id'] || ! $r['line']['interest_component_id'])->count(),
        ];
    }

    /**
     * One row per employee, one column per pay item (by = component) or per pay component group (by = group).
     * Uses the same capital / interest -> pay item split as the payslip and the paysheet (LoanService::componentAmounts()).
     */
    private function matrix(string $by, $employees, array $due): array
    {
        $componentIds = collect($due)->flatMap(fn ($d) => collect($d['lines'])->flatMap(fn ($l) => [$l['capital_component_id'], $l['interest_component_id']]))->filter()->unique()->all();
        $components = PayComponent::with('group')->whereIn('id', $componentIds)->get()->keyBy('id');

        $columns = [];
        $perEmployee = [];
        foreach ($employees as $id => $e) {
            foreach ($this->loans->componentAmounts($due[$id]['lines']) as $componentId => $amount) {
                $c = $components[$componentId] ?? null;
                if (! $c) {
                    continue;
                }
                if ($by === 'component') {
                    $key = $c->id;
                    $columns[$key] ??= ['label' => $c->code, 'sub' => $c->name];
                } else {
                    $key = $c->group?->id ?? 0;
                    $columns[$key] ??= ['label' => $c->group?->name ?? 'No group', 'sub' => $c->group?->combine_on_payslip ? 'one line on the payslip' : null];
                }
                $perEmployee[$id][$key] = Decimal::add($perEmployee[$id][$key] ?? '0', $amount);
            }
        }
        uasort($columns, fn ($a, $b) => strcmp($a['label'], $b['label']));

        $rows = [];
        $totals = [];
        $grand = '0';
        foreach ($employees as $id => $e) {
            if (empty($perEmployee[$id])) {
                continue;
            }
            $sum = '0';
            $amounts = [];
            foreach ($perEmployee[$id] as $k => $v) {
                $amounts[$k] = Decimal::round($v, 2);
                $totals[$k] = Decimal::add($totals[$k] ?? '0', $v);
                $sum = Decimal::add($sum, $v);
            }
            $grand = Decimal::add($grand, $sum);
            $rows[] = ['employee' => $e, 'amounts' => $amounts, 'total' => Decimal::round($sum, 2)];
        }

        return ['columns' => $columns, 'rows' => $rows, 'totals' => array_map(fn ($v) => Decimal::round($v, 2), $totals), 'grand' => Decimal::round($grand, 2)];
    }
}
