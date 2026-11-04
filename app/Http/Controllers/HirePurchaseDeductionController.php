<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayComponent;
use App\Models\Scheme;
use App\Payroll\Decimal;
use App\Services\HirePurchaseService;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What hire purchases take from pay in a month. Three views of the same figures:
 *   hp         every installment (the detail)
 *   vendor     grouped by vendor, with a total per vendor (what the company pays out at month end)
 *   component  one column per pay item (what the paysheet shows component-wise)
 */
class HirePurchaseDeductionController extends Controller
{
    public function __construct(private HirePurchaseService $service) {}

    public function index(Request $request): View
    {
        return view('hire-purchases.deductions', $this->data($request) + ['schemes' => Scheme::visibleTo($request->user())->orderBy('name')->get()]);
    }

    public function export(Request $request)
    {
        $d = $this->data($request);
        $file = 'hire-purchase-deductions-'.$d['by'].'-'.$d['month']->format('Y-m').'.csv';

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

        $head = ['Vendor Code', 'Vendor', 'Employee No', 'Name', 'Hire purchase', 'Item', 'Installment', 'Due', 'Amount', 'Note'];
        $line = fn ($r) => [$r['line']['vendor_code'], $r['line']['vendor_name'], $r['employee']->employee_no, $r['employee']->display_name ?? $r['employee']->full_name, $r['line']['reference'],
            $r['line']['item'], $r['line']['seq'], Carbon::parse($r['line']['due_month'])->format('M Y'), $r['line']['amount'],
            trim(($r['line']['overdue'] ? 'Overdue ' : '').(HirePurchaseService::hasLeft($r['employee']) ? 'Employee has left' : ''))];

        $rows = [];
        if ($d['by'] === 'vendor') {
            foreach ($d['vendors'] as $v) {
                foreach ($v['rows'] as $r) {
                    $rows[] = $line($r);
                }
                $rows[] = [$v['code'], $v['name'].' TOTAL', '', '', '', '', '', '', $v['total'], ''];
            }
        } else {
            foreach ($d['rows'] as $r) {
                $rows[] = $line($r);
            }
        }
        $rows[] = ['', 'GRAND TOTAL', '', '', '', '', '', '', $d['total'], ''];

        return Csv::download($file, $head, $rows);
    }

    /** Until the pay run records deductions itself, this rolls the month's installments forward. */
    public function record(Request $request): RedirectResponse
    {
        $d = $request->validate(['month' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'scheme' => ['nullable', 'integer']]);
        $r = $this->service->recordMonth(Carbon::createFromFormat('Y-m-d', $d['month'].'-01'), $request->user(), $d['scheme'] ?? null);

        return redirect()->route('hire-purchases.deductions', array_filter(['month' => $d['month'], 'scheme' => $d['scheme'] ?? null]))
            ->with('status', "{$r['count']} installment(s) recorded as deducted.");
    }

    private function data(Request $request): array
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? Carbon::createFromFormat('Y-m-d', $request->query('month').'-01') : today()->startOfMonth();
        $scheme = $request->query('scheme') ? (int) $request->query('scheme') : null;
        $by = in_array($request->query('by'), ['vendor', 'component'], true) ? $request->query('by') : 'hp';
        $ids = $scheme ? Employee::query()->where('scheme_id', $scheme)->pluck('id')->all() : null;

        $due = $this->service->dueForPeriod($month, $ids);
        $employees = Employee::query()->whereIn('id', array_keys($due))->get()->keyBy('id')->sortBy('employee_no');

        $rows = [];
        $total = '0';
        foreach ($employees as $id => $e) {
            foreach ($due[$id]['lines'] as $line) {
                $rows[] = ['employee' => $e, 'line' => $line];
                $total = Decimal::add($total, $line['amount']);
            }
        }

        $vendors = [];
        foreach ($rows as $r) {
            $v = $r['line'];
            $vendors[$v['vendor_id']] ??= ['code' => $v['vendor_code'], 'name' => $v['vendor_name'], 'rows' => [], 'total' => '0', 'employees' => []];
            $vendors[$v['vendor_id']]['rows'][] = $r;
            $vendors[$v['vendor_id']]['total'] = Decimal::add($vendors[$v['vendor_id']]['total'], $v['amount']);
            $vendors[$v['vendor_id']]['employees'][$r['employee']->id] = true;
        }
        uasort($vendors, fn ($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($vendors as $k => $v) {
            $vendors[$k]['total'] = Decimal::round($v['total'], 2);
            $vendors[$k]['employee_count'] = count($v['employees']);
        }

        return [
            'month' => $month, 'scheme' => $scheme, 'by' => $by, 'rows' => $rows, 'vendors' => $vendors,
            'overdue' => collect($rows)->where('line.overdue', true)->count(),
            'left' => collect($rows)->filter(fn ($r) => HirePurchaseService::hasLeft($r['employee']))->count(),
            'matrix' => $by === 'component' ? $this->matrix($employees, $due) : null,
            'total' => Decimal::round($total, 2),
        ];
    }

    /** One row per employee, one column per pay item (the same split the payslip and paysheet use: HirePurchaseService::componentAmounts()). */
    private function matrix($employees, array $due): array
    {
        $components = PayComponent::whereIn('id', collect($due)->flatMap(fn ($d) => collect($d['lines'])->pluck('pay_component_id'))->unique()->all())->get()->keyBy('id');

        $columns = [];
        $perEmployee = [];
        foreach ($employees as $id => $e) {
            foreach ($this->service->componentAmounts($due[$id]['lines']) as $componentId => $amount) {
                $c = $components[$componentId] ?? null;
                if (! $c) {
                    continue;
                }
                $columns[$c->id] ??= ['label' => $c->code, 'sub' => $c->name];
                $perEmployee[$id][$c->id] = Decimal::add($perEmployee[$id][$c->id] ?? '0', $amount);
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
