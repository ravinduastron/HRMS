<?php

namespace App\Http\Controllers;

use App\Models\Scheme;
use App\Payroll\PayCalendar;
use App\Services\ProvidentFundService;
use App\Services\SchemePayService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Pay settings of a scheme (the pay group). All-scheme administrators only (routes also require schemes.all). */
class SchemePaySettingsController extends Controller
{
    public function __construct(private SchemePayService $service, private ProvidentFundService $funds) {}

    public function index(Request $request, Scheme $scheme): View
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : today()->format('Y-m');
        $monthStart = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

        $versions = $scheme->paySettings()->get();
        $current = $scheme->paySettingsAt();
        $inForce = $scheme->paySettingsAt($monthStart); // the version that applies to the month being previewed
        $latest = $versions->first();
        $form = $latest ? $latest->toCalendar() : PayCalendar::defaults();

        return view('schemes.pay.index', [
            'scheme' => $scheme->loadCount('employees'),
            'versions' => $versions, 'current' => $current, 'latest' => $latest,
            'month' => $month, 'monthStart' => $monthStart, 'previewVersion' => $inForce,
            'periods' => $inForce ? PayCalendar::periods($inForce->toCalendar(), $monthStart->year, $monthStart->month) : [],
            'form' => old('cycles') !== null ? PayCalendar::normalize(request()->only(['cycles', 'windows', 'pay_date'])) : $form,
            'timing' => old('statutory_timing', $latest?->statutory_timing ?? 'every_cycle'),
            'disbursement' => old('disbursement', $latest?->disbursement ?? 'bank'),
            'earliest' => $latest ? $latest->effective_from->copy()->addDay()->toDateString() : today()->toDateString(),
        ]);
    }

    public function store(Request $request, Scheme $scheme): RedirectResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'change_note' => ['required', 'string', 'max:500'],
            'statutory_timing' => ['required', Rule::in(array_keys(PayCalendar::TIMINGS))],
            'disbursement' => ['required', 'in:bank,cash'],
            'cycles' => ['required', 'array', 'min:1', 'max:4'],
            'windows' => ['required', 'array'],
            'pay_date' => ['required', 'array'],
        ]);

        $this->service->save(
            $scheme, PayCalendar::normalize($request->only(['cycles', 'windows', 'pay_date'])),
            $data['statutory_timing'], $data['disbursement'], Carbon::parse($data['effective_from']), $data['change_note'], $request->user(),
        );

        return redirect()->route('schemes.pay.index', $scheme)->with('status', 'Pay settings saved.');
    }

    /** Default provident fund. Applied at once to every employee of the scheme who was not set by hand. */
    public function updateFund(Request $request, Scheme $scheme): RedirectResponse
    {
        $fund = $request->validate(['default_fund' => ['required', 'in:epf,msps']])['default_fund'];

        if ($scheme->default_fund === $fund) {
            return back();
        }

        $scheme->update(['default_fund' => $fund]); // Auditable records old and new
        $changed = $this->funds->reconcile($scheme->id);
        $kept = $scheme->employees()->withoutGlobalScopes()->where('provident_fund_override', true)->count();
        Audit::log('scheme.default_fund_changed', $scheme, "Default provident fund of {$scheme->name} set to ".strtoupper($fund), context: ['employees_changed' => $changed, 'manual_settings_kept' => $kept]);

        return back()->with('status', 'Default fund set to '.strtoupper($fund).". {$changed} employee(s) updated".($kept ? "; {$kept} set by hand were left as they are." : '.'));
    }
}
