<?php

namespace App\Http\Controllers;

use App\Models\PayComponent;
use App\Models\PayComponentVersion;
use App\Payroll\ComponentCalculator;
use App\Payroll\Decimal;
use App\Payroll\Formula\Parser;
use App\Payroll\FormulaException;
use App\Payroll\SystemVariables;
use App\Services\PayComponentService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PayComponentController extends Controller
{
    private const FORMULA_METHODS = ['formula', 'percentage'];

    public function __construct(private PayComponentService $service, private ComponentCalculator $calculator) {}

    public function index(Request $request): View
    {
        $components = PayComponent::with('versions')
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('payslip_order')->orderBy('code')->get();

        return view('payroll.components.index', compact('components'));
    }

    public function create(): View
    {
        return view('payroll.components.create', [
            'v' => $this->defaults(), 'others' => PayComponent::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->headerRules() + $this->versionRules());
        $this->authorizeFormula($request, $data['calc_method']);

        $component = $this->service->create([
            'code' => strtoupper($data['code']), 'name' => trim($data['name']), 'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'show_on_payslip' => $request->boolean('show_on_payslip'), 'payslip_order' => $data['payslip_order'] ?? 100,
        ], $this->versionData($data, $request), $request->user());

        return redirect()->route('pay-components.show', $component)->with('status', 'Pay component created.');
    }

    public function show(PayComponent $component): View
    {
        return view('payroll.components.show', ['payComponent' => $component->load('versions')]);
    }

    public function createVersion(PayComponent $component): View
    {
        $latest = $component->latestVersion();
        $v = $latest ? array_merge($this->defaults(), $latest->only([
            'calc_method', 'default_amount', 'percentage_rate', 'percentage_of', 'formula', 'rounding_mode', 'rounding_dp',
            'epf_applicable', 'etf_applicable', 'tax_applicable', 'ot_base', 'proration', 'payment_timing', 'pay_cycle_rule',
        ])) : $this->defaults();
        $v['effective_from'] = max(today(), $latest?->effective_from->copy()->addDay() ?? today())->toDateString();
        $v['change_note'] = '';

        return view('payroll.components.version', [
            'payComponent' => $component, 'v' => $v, 'latest' => $latest,
            'others' => PayComponent::where('is_active', true)->where('id', '!=', $component->id)->orderBy('code')->get(),
        ]);
    }

    public function storeVersion(Request $request, PayComponent $component): RedirectResponse
    {
        $data = $request->validate($this->versionRules() + ['change_note' => ['required', 'string', 'max:500']]);
        $this->authorizeFormula($request, $data['calc_method'], $component->latestVersion()?->calc_method);

        $this->service->addVersion($component, $this->versionData($data, $request), $request->user());

        return redirect()->route('pay-components.show', $component)->with('status', 'New version saved.');
    }

    public function update(Request $request, PayComponent $component): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:255'],
            'payslip_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);
        $component->update($data + ['show_on_payslip' => $request->boolean('show_on_payslip')]);

        return back()->with('status', 'Details updated.');
    }

    public function toggle(PayComponent $component): RedirectResponse
    {
        if ($component->is_active) {
            // Do not deactivate while another active component still uses it
            $users = collect($this->service->latestDefinitions())->filter(fn ($d, $code) => $d['active'] && $code !== $component->code
                && in_array($component->code, $this->calculator->references([$code => $d])[$code], true))->keys();
            if ($users->isNotEmpty()) {
                return back()->with('error', "{$component->code} is used by ".$users->implode(', ').' and cannot be deactivated yet.');
            }
        }

        $component->update(['is_active' => ! $component->is_active]);

        return back()->with('status', $component->is_active ? 'Component activated.' : 'Component deactivated. It stays in past payslips and history.');
    }

    /** "Check formula" button: validates without saving. */
    public function check(Request $request): JsonResponse
    {
        $d = $request->validate([
            'calc_method' => ['required', Rule::in(array_keys(PayComponentVersion::METHODS))],
            'formula' => ['nullable', 'string', 'max:2000'], 'percentage_rate' => ['nullable', 'numeric'],
            'percentage_of' => ['nullable', 'array'], 'percentage_of.*' => ['string'], 'code' => ['nullable', 'string', 'max:30'],
        ]);

        try {
            $result = $this->service->check(strtoupper($d['code'] ?? '') ?: '__NEW__', [
                'calc_method' => $d['calc_method'], 'formula' => $d['formula'] ?? null,
                'percentage_rate' => $d['percentage_rate'] ?? null, 'percentage_of' => $d['percentage_of'] ?? [],
            ]);
        } catch (FormulaException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'message' => 'The formula is valid.', 'references' => $result['references']]);
    }

    /** Test panel: run a component (and everything it depends on) with sample values. */
    public function test(Request $request, PayComponent $component): View
    {
        $date = $request->filled('date') ? Carbon::parse($request->input('date')) : today();
        $defs = $this->service->definitionsAt($date);
        $error = null;
        $results = null;
        $needs = ['leaves' => [], 'variables' => [], 'order' => []];

        if (! isset($defs[$component->code])) {
            $error = 'This component has no version in force on '.$date->format('d M Y').'.';
        } else {
            try {
                $needs = $this->calculator->needs($defs, [$component->code]);
            } catch (FormulaException $e) {
                $error = $e->getMessage();
            }
        }

        $sysDefaults = collect(SystemVariables::all())->map(fn ($v) => $v[2]);
        $values = [];
        foreach ($needs['variables'] as $name) {
            $values[$name] = $sysDefaults[$name] ?? '0';
        }
        foreach ($needs['leaves'] as $code) {
            $values[$code] = $defs[$code]['method'] === 'fixed' ? Decimal::plain($defs[$code]['amount'] ?? '0') : '0';
        }

        if ($request->isMethod('post') && ! $error) {
            $values = array_merge($values, array_filter((array) $request->input('values', []), fn ($v) => $v !== null && $v !== ''));
            try {
                foreach ($values as $name => $value) {
                    if (! preg_match('/^-?\d+(\.\d+)?$/', (string) $value)) {
                        throw new FormulaException("{$name}: \"{$value}\" is not a number.");
                    }
                }
                $results = $this->calculator->calculate($defs, $values, [$component->code]);
            } catch (FormulaException $e) {
                $error = $e->getMessage();
            }
        }

        return view('payroll.components.test', [
            'payComponent' => $component, 'date' => $date, 'needs' => $needs, 'values' => $values,
            'results' => $results, 'error' => $error, 'defs' => $defs, 'sysLabels' => SystemVariables::all(),
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function authorizeFormula(Request $request, string $method, ?string $currentMethod = null): void
    {
        $touchesFormulas = in_array($method, self::FORMULA_METHODS, true) || in_array($currentMethod, self::FORMULA_METHODS, true);
        if ($touchesFormulas && ! $request->user()->can('pay-components.formulas')) {
            throw ValidationException::withMessages(['calc_method' => 'You do not have permission to create or change formula-based components.']);
        }
    }

    private function headerRules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_]{1,29}$/', 'unique:pay_components,code',
                function ($attr, $value, $fail) {
                    $up = strtoupper($value);
                    if (in_array($up, Parser::RESERVED, true) || in_array($up, SystemVariables::names(), true)) {
                        $fail("{$up} is a reserved word and cannot be used as a component code.");
                    }
                }],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(array_keys(PayComponent::TYPES))],
            'description' => ['nullable', 'string', 'max:255'],
            'payslip_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    private function versionRules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'calc_method' => ['required', Rule::in(array_keys(PayComponentVersion::METHODS))],
            'default_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'percentage_rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'percentage_of' => ['nullable', 'array'], 'percentage_of.*' => ['string', 'exists:pay_components,code'],
            'formula' => ['nullable', 'string', 'max:2000'],
            'rounding_mode' => ['required', Rule::in(['half_up', 'up', 'down'])],
            'rounding_dp' => ['required', 'integer', 'min:0', 'max:6'],
            'proration' => ['required', Rule::in(['none', 'calendar_days', 'working_days'])],
            'payment_timing' => ['required', Rule::in(['regular', 'separate'])],
            'pay_cycle_rule' => ['required', Rule::in(['every_period', 'first_period', 'last_period'])],
            'change_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function versionData(array $d, Request $request): array
    {
        $m = $d['calc_method'];

        return [
            'effective_from' => Carbon::parse($d['effective_from'])->toDateString(),
            'calc_method' => $m,
            'default_amount' => $m === 'fixed' ? ($d['default_amount'] ?? null) : null,
            'percentage_rate' => $m === 'percentage' ? ($d['percentage_rate'] ?? null) : null,
            'percentage_of' => $m === 'percentage' ? array_values($d['percentage_of'] ?? []) : null,
            'formula' => $m === 'formula' ? trim($d['formula'] ?? '') : null,
            'rounding_mode' => $d['rounding_mode'], 'rounding_dp' => $d['rounding_dp'],
            'epf_applicable' => $request->boolean('epf_applicable'), 'etf_applicable' => $request->boolean('etf_applicable'),
            'tax_applicable' => $request->boolean('tax_applicable'), 'ot_base' => $request->boolean('ot_base'),
            'proration' => $d['proration'], 'payment_timing' => $d['payment_timing'], 'pay_cycle_rule' => $d['pay_cycle_rule'],
            'change_note' => $d['change_note'] ?? null,
        ];
    }

    private function defaults(): array
    {
        return [
            'effective_from' => today()->toDateString(), 'calc_method' => 'fixed', 'default_amount' => null, 'percentage_rate' => null,
            'percentage_of' => [], 'formula' => '', 'rounding_mode' => 'half_up', 'rounding_dp' => 2,
            'epf_applicable' => false, 'etf_applicable' => false, 'tax_applicable' => false, 'ot_base' => false,
            'proration' => 'none', 'payment_timing' => 'regular', 'pay_cycle_rule' => 'every_period', 'change_note' => '',
        ];
    }
}
