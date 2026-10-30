<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Scheme;
use App\Payroll\Decimal;
use App\Services\LoanService;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** What loans take from pay in a month, capital and interest apart (the same split the payslip and paysheet show). */
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
        $rows = [];
        foreach ($d['rows'] as $r) {
            $rows[] = [$r['employee']->employee_no, $r['employee']->display_name ?? $r['employee']->full_name, $r['line']['reference'], $r['line']['type'], $r['line']['seq'],
                Carbon::parse($r['line']['due_month'])->format('M Y'), $r['line']['capital'], $r['line']['interest'], Decimal::add($r['line']['capital'], $r['line']['interest']), $r['line']['overdue'] ? 'Overdue' : ''];
        }
        $rows[] = ['', 'TOTAL', '', '', '', '', $d['totals']['capital'], $d['totals']['interest'], $d['totals']['total'], ''];

        return Csv::download('loan-deductions-'.$d['month']->format('Y-m').'.csv', ['Employee No', 'Name', 'Loan', 'Type', 'Installment', 'Due', 'Capital', 'Interest', 'Total', 'Note'], $rows);
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
            'month' => $month, 'scheme' => $scheme, 'rows' => $rows, 'overdue' => collect($rows)->where('line.overdue', true)->count(),
            'totals' => ['capital' => Decimal::round($t['capital'], 2), 'interest' => Decimal::round($t['interest'], 2), 'total' => Decimal::round(Decimal::add($t['capital'], $t['interest']), 2)],
            'missingComponents' => collect($rows)->filter(fn ($r) => ! $r['line']['capital_component_id'] || ! $r['line']['interest_component_id'])->count(),
        ];
    }
}
