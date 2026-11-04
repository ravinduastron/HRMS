<?php

namespace App\Http\Controllers;

use App\Models\PayPeriod;
use App\Models\Scheme;
use App\Services\PayPeriodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Pay periods of the schemes the user may see. Generating, closing and reopening each have their own permission. */
class PayPeriodController extends Controller
{
    public function __construct(private PayPeriodService $service) {}

    public function index(Request $request): View
    {
        $schemes = Scheme::visibleTo($request->user())->orderBy('name')->get();
        $scheme = $schemes->firstWhere('id', (int) $request->query('scheme')) ?? $schemes->first();
        $years = $this->service->taxYearOptions();
        $year = array_key_exists((int) $request->query('year'), $years) ? (int) $request->query('year') : \App\Payroll\Statutory\TaxYear::for(today(), PayPeriodService::startMonth())['year'];

        $periods = $scheme
            ? PayPeriod::where('scheme_id', $scheme->id)->where('tax_year_start', $year)->orderBy('year')->orderBy('month')->orderBy('cycle_no')->get()
            : collect();

        return view('payroll.periods.index', [
            'schemes' => $schemes, 'scheme' => $scheme, 'years' => $years, 'year' => $year, 'periods' => $periods,
            'hasSettings' => $scheme ? $scheme->paySettings()->exists() : false,
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $allowed = Scheme::visibleTo($request->user())->pluck('id')->all();
        $d = $request->validate([
            'scheme' => ['required', 'integer', Rule::in($allowed)], 'year' => ['required', 'integer', Rule::in(array_keys($this->service->taxYearOptions()))], 'refresh' => ['nullable', 'boolean'],
        ], ['scheme.in' => 'You can only use schemes that are allocated to you.']);

        $scheme = Scheme::findOrFail($d['scheme']);
        $refresh = $request->boolean('refresh');
        $r = $this->service->generate($scheme, (int) $d['year'], $request->user(), $refresh);

        $parts = ["{$r['created']} created", "{$r['unchanged']} already up to date"];
        if ($refresh) {
            $parts[] = "{$r['updated']} refreshed";
            $parts[] = "{$r['removed']} removed";
        }
        $redirect = redirect()->route('pay-periods.index', ['scheme' => $scheme->id, 'year' => $d['year']]);

        $notes = [];
        if ($r['skipped']) {
            $notes[] = 'No pay settings were in force for '.implode(', ', $r['skipped']).', so no periods were made for those months (add or backdate the scheme pay settings, then generate again).';
        }
        if ($r['kept']) {
            $notes[] = "{$r['kept']} period(s) differ from the current pay settings but were left as they are".($refresh ? ' (closed or locked).' : '. Use "Refresh open periods" to update the open ones.');
        }
        if ($r['extra']) {
            $notes[] = "{$r['extra']} period(s) belong to a cycle the settings no longer have and were kept.";
        }

        return $redirect->with($notes ? 'warning' : 'status', 'Pay periods for '.$scheme->name.': '.implode(', ', $parts).'.'.($notes ? ' '.implode(' ', $notes) : ''));
    }

    public function close(Request $request, PayPeriod $period): RedirectResponse
    {
        $this->service->close($period, $request->user());

        return back()->with('status', "Pay period {$period->label()} closed.");
    }

    public function reopen(Request $request, PayPeriod $period): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->service->reopen($period, $d['reason'], $request->user());

        return back()->with('status', "Pay period {$period->label()} reopened.");
    }
}
