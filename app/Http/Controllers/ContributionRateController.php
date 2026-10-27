<?php

namespace App\Http\Controllers;

use App\Models\StatutoryRateVersion;
use App\Services\StatutoryService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** EPF / MSPS / ETF default rates (versioned). Changing them needs statutory.manage plus schemes.all (the rates are global). */
class ContributionRateController extends Controller
{
    public function __construct(private StatutoryService $statutory) {}

    public function index(): View
    {
        return view('statutory.rates.index', [
            'versions' => StatutoryRateVersion::orderByDesc('effective_from')->get(),
            'inForce' => $this->statutory->ratesAt(),
            'latest' => StatutoryRateVersion::orderByDesc('effective_from')->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rule = ['required', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(\.\d{1,4})?$/'];
        $data = $request->validate([
            'effective_from' => ['required', 'date'], 'change_note' => ['required', 'string', 'max:500'],
            'epf_employee_rate' => $rule, 'epf_employer_rate' => $rule, 'msps_employee_rate' => $rule, 'msps_employer_rate' => $rule, 'etf_employer_rate' => $rule,
        ], ['*.regex' => 'Use a number with up to 4 decimals, for example 8 or 2.5.', '*.between' => 'A rate must be between 0 and 100.']);

        $this->statutory->addRateVersion(
            collect(StatutoryRateVersion::KEYS)->mapWithKeys(fn ($k) => [$k => (string) $data[$k.'_rate']])->all(),
            Carbon::parse($data['effective_from']), $data['change_note'], $request->user(),
        );

        return redirect()->route('statutory.rates')->with('status', 'Contribution rates saved.');
    }
}
