<?php

namespace App\Http\Controllers;

use App\Models\LoanMarketRate;
use App\Models\LoanValuation;
use App\Models\LoanValuationLine;
use App\Models\Scheme;
use App\Payroll\Decimal;
use App\Services\LoanValuationService;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fair value of loans. Anyone with loans.valuation can run it for the loans they can see.
 * Market rates and saved snapshots are global, so they also need all-scheme access.
 */
class LoanValuationController extends Controller
{
    public function __construct(private LoanValuationService $valuation) {}

    public function index(Request $request): View
    {
        $asAt = $this->date($request->query('as_at')) ?? today()->endOfMonth()->startOfDay();
        $marketNow = $this->valuation->marketRateAt($asAt);
        $rate = $request->filled('rate') ? str_replace([',', ' ', '%'], '', $request->query('rate')) : ($marketNow ? Decimal::plain((string) $marketNow->rate) : null);
        $scheme = $request->query('scheme') ? (int) $request->query('scheme') : null;

        $result = null;
        $error = null;
        if ($request->boolean('run') && $rate !== null) {
            if (! preg_match('/^\d{1,3}(\.\d{1,4})?$/', $rate) || Decimal::cmp($rate, '100') > 0) {
                $error = 'The market rate must be from 0 to 100.';
            } else {
                $result = $this->valuation->compute($asAt, $rate, $scheme);
            }
        }

        return view('loans.fair-value', [
            'asAt' => $asAt, 'rate' => $rate, 'scheme' => $scheme, 'result' => $result, 'error' => $error, 'marketNow' => $marketNow,
            'marketRates' => LoanMarketRate::orderByDesc('effective_from')->get(),
            'snapshots' => LoanValuation::orderByDesc('as_at')->orderByDesc('id')->limit(24)->get(),
            'schemes' => Scheme::visibleTo($request->user())->orderBy('name')->get(),
            'runUrlParams' => ['as_at' => $asAt->toDateString(), 'rate' => $rate, 'scheme' => $scheme, 'run' => 1],
        ]);
    }

    public function export(Request $request)
    {
        $asAt = $this->date($request->query('as_at')) ?? today();
        $rate = str_replace([',', ' ', '%'], '', (string) $request->query('rate'));
        abort_unless(preg_match('/^\d{1,3}(\.\d{1,4})?$/', $rate), 422, 'Give the market rate.');
        $r = $this->valuation->compute($asAt, $rate, $request->query('scheme') ? (int) $request->query('scheme') : null);

        $rows = array_map(fn ($l) => [$l['employee_no'], $l['employee_name'], $l['reference'], $l['type'], $l['rate'], $l['remaining'], $l['outstanding'], $l['nominal'], $l['fair_value'], $l['difference']], $r['lines']);
        $rows[] = ['', 'TOTAL', '', '', '', '', $r['totals']['outstanding'], $r['totals']['nominal'], $r['totals']['fair_value'], $r['totals']['difference']];

        return Csv::download('loan-fair-value-'.$asAt->format('Y-m-d').'.csv', $this->header(), $rows);
    }

    public function storeMarketRate(Request $request): RedirectResponse
    {
        $d = $request->validate(['rate' => ['required', 'string', 'max:10'], 'effective_from' => ['required', 'date'], 'change_note' => ['required', 'string', 'max:500']]);
        $this->valuation->addMarketRate($d['rate'], Carbon::parse($d['effective_from']), $d['change_note'], $request->user());

        return back()->with('status', 'Market rate saved.');
    }

    public function saveSnapshot(Request $request): RedirectResponse
    {
        $d = $request->validate(['as_at' => ['required', 'date'], 'rate' => ['required', 'regex:/^\d{1,3}(\.\d{1,4})?$/', 'between:0,100'], 'note' => ['nullable', 'string', 'max:255']]);
        $v = $this->valuation->save(Carbon::parse($d['as_at'])->startOfDay(), $d['rate'], $d['note'] ?? null, $request->user());

        return redirect()->route('loans.fair-value.snapshot', $v)->with('status', 'Valuation saved.');
    }

    public function snapshot(LoanValuation $valuation): View
    {
        $lines = LoanValuationLine::where('loan_valuation_id', $valuation->id)->orderBy('employee_no')->orderBy('loan_reference')->get();

        return view('loans.snapshot', ['valuation' => $valuation, 'lines' => $lines, 'totals' => $this->totals($lines), 'partial' => $lines->count() < $valuation->loan_count]);
    }

    public function snapshotExport(LoanValuation $valuation)
    {
        $lines = LoanValuationLine::where('loan_valuation_id', $valuation->id)->orderBy('employee_no')->orderBy('loan_reference')->get();
        $t = $this->totals($lines);
        $rows = $lines->map(fn ($l) => [$l->employee_no, $l->employee_name, $l->loan_reference, $l->loan_type_name, Decimal::plain((string) $l->contractual_rate), $l->remaining_installments,
            (string) $l->outstanding_principal, (string) $l->nominal_remaining, (string) $l->fair_value, Decimal::round(Decimal::sub((string) $l->fair_value, (string) $l->outstanding_principal), 2)])->all();
        $rows[] = ['', 'TOTAL', '', '', '', '', $t['outstanding'], $t['nominal'], $t['fair_value'], $t['difference']];

        return Csv::download('loan-fair-value-snapshot-'.$valuation->as_at->format('Y-m-d').'.csv', $this->header(), $rows);
    }

    private function header(): array
    {
        return ['Employee No', 'Name', 'Loan', 'Type', 'Contract rate %', 'Installments left', 'Outstanding principal', 'To be collected (principal + interest)', 'Fair value', 'Fair value minus outstanding'];
    }

    private function totals($lines): array
    {
        $o = $n = $f = '0';
        foreach ($lines as $l) {
            $o = Decimal::add($o, (string) $l->outstanding_principal);
            $n = Decimal::add($n, (string) $l->nominal_remaining);
            $f = Decimal::add($f, (string) $l->fair_value);
        }

        return ['outstanding' => Decimal::round($o, 2), 'nominal' => Decimal::round($n, 2), 'fair_value' => Decimal::round($f, 2), 'difference' => Decimal::round(Decimal::sub($f, $o), 2)];
    }

    private function date(?string $v): ?Carbon
    {
        return $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? Carbon::parse($v)->startOfDay() : null;
    }
}
