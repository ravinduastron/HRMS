<?php

namespace App\Http\Controllers;

use App\Models\TaxTable;
use App\Payroll\FormulaException;
use App\Payroll\Statutory\ApitLumpSum;
use App\Payroll\Statutory\Format;
use App\Payroll\Statutory\SlabTax;
use App\Payroll\Statutory\TaxYear;
use App\Services\StatutoryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Tax tables (monthly and lump sum). Viewing needs statutory.view; changing needs statutory.manage plus schemes.all (the tables are global). */
class TaxTableController extends Controller
{
    private const MONEY = '/^\d{1,13}(\.\d{1,2})?$/';

    public function __construct(private StatutoryService $statutory) {}

    public function index(Request $request): View
    {
        $panels = [];
        foreach (TaxTable::orderByRaw("kind = 'monthly' desc")->orderBy('id')->get() as $t) {
            $versions = $t->versions()->get();
            $inForce = $this->statutory->taxTableVersionAt($t->code);
            $key = $t->kind === 'monthly' ? 'monthly' : 'lump';
            $selected = $versions->firstWhere('id', (int) $request->query($key)) ?? $versions->firstWhere('id', $inForce?->id) ?? $versions->first();
            $slabs = $selected?->toSlabs() ?? [];

            $panels[$t->kind] = [
                'table' => $t, 'versions' => $versions, 'inForceId' => $inForce?->id, 'version' => $selected, 'key' => $key,
                'slabs' => $slabs, 'maxGap' => $slabs ? SlabTax::maxGap($slabs) : '0', 'problems' => $slabs ? SlabTax::validate($slabs) : [], 'cross' => null,
            ];
        }

        // "annual = 12 x monthly" cross-check, only between versions that start on the same date
        $m = $panels['monthly']['version'] ?? null;
        $l = $panels['lump_sum']['version'] ?? null;
        if ($m && $l && $m->effective_from->equalTo($l->effective_from)) {
            $panels['lump_sum']['cross'] = SlabTax::annualisationProblems($panels['monthly']['slabs'], $panels['lump_sum']['slabs']);
        }

        return view('statutory.tax-tables.index', ['panels' => array_values($panels), 'taxYear' => TaxYear::for(today(), (int) config('statutory.tax_year_start_month', 4))]);
    }

    public function createVersion(TaxTable $table): View
    {
        $latest = $table->versions()->with('slabs')->first();
        $from = $latest ? $latest->effective_from->copy()->addYear() : today();
        $label = TaxYear::for($from, (int) config('statutory.tax_year_start_month', 4))['label'];

        $rows = old('slabs') !== null
            ? collect(old('slabs'))->map(fn ($r) => ['up_to' => (string) ($r['up_to'] ?? ''), 'rate' => (string) ($r['rate'] ?? ''), 'fixed' => (string) ($r['fixed'] ?? '')])->values()->all()
            : ($latest ? $latest->toSlabs() : [['up_to' => '', 'rate' => '', 'fixed' => '0']]);
        $rows = collect($rows)->map(fn ($r) => ['up_to' => (string) ($r['up_to'] ?? ''), 'rate' => (string) $r['rate'], 'fixed' => (string) ($r['fixed'] ?? '')])->values()->all();

        return view('statutory.tax-tables.version', ['table' => $table, 'latest' => $latest, 'rows' => $rows, 'label' => $label, 'effective' => $from->toDateString()]);
    }

    public function storeVersion(Request $request, TaxTable $table): RedirectResponse
    {
        $request->merge(['slabs' => collect($request->input('slabs', []))->map(fn ($r) => is_array($r) ? array_map(fn ($v) => is_string($v) ? str_replace([',', ' '], '', $v) : $v, $r) : $r)->all()]);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:50'],
            'effective_from' => ['required', 'date'],
            'source_note' => ['nullable', 'string', 'max:255'],
            'change_note' => ['required', 'string', 'max:500'],
            'slabs' => ['required', 'array', 'min:1', 'max:12'],
            'slabs.*.up_to' => ['nullable', 'regex:'.self::MONEY],
            'slabs.*.rate' => ['required', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'slabs.*.fixed' => ['nullable', 'regex:'.self::MONEY],
        ], [
            'slabs.*.up_to.regex' => 'Band limits must be numbers with up to 2 decimals.',
            'slabs.*.rate.regex' => 'Rates must be numbers with up to 4 decimals.',
            'slabs.*.rate.required' => 'Every band needs a rate.',
            'slabs.*.fixed.regex' => 'Fixed amounts must be numbers with up to 2 decimals.',
        ]);

        $result = $this->statutory->addTaxTableVersion($table, $data['slabs'], $data['label'], $data['source_note'] ?? null,
            Carbon::parse($data['effective_from']), $data['change_note'], $request->user());

        $redirect = redirect()->route('statutory.tax-tables', [$table->kind === 'monthly' ? 'monthly' : 'lump' => $result['version']->id]);

        return $result['warnings']
            ? $redirect->with('warning', 'Saved, with points to check: '.implode(' ', $result['warnings']))
            : $redirect->with('status', 'Tax table version saved.');
    }

    /** The "Try it" panels. Nothing is saved. */
    public function tryIt(Request $request): JsonResponse
    {
        $request->merge(collect($request->all())->map(fn ($v) => is_string($v) ? str_replace([',', ' '], '', $v) : $v)->all());

        $d = $request->validate([
            'table' => ['required', 'in:apit_monthly,apit_lump_sum'], 'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'income' => ['nullable', 'regex:'.self::MONEY],
            'a' => ['nullable', 'regex:'.self::MONEY], 'b' => ['nullable', 'regex:'.self::MONEY], 'c' => ['nullable', 'regex:'.self::MONEY],
            'tax_on_a' => ['nullable', 'regex:'.self::MONEY], 'tax_on_b' => ['nullable', 'regex:'.self::MONEY], 'prior' => ['nullable', 'regex:'.self::MONEY],
        ], ['*.regex' => 'Enter numbers only (up to 2 decimals).']);

        try {
            $date = Carbon::createFromFormat('Y-m-d', $d['month'].'-01')->startOfDay();
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'message' => 'Choose a valid month.']);
        }

        $version = $this->statutory->taxTableVersionAt($d['table'], $date);
        if (! $version) {
            return response()->json(['ok' => false, 'message' => 'No version of this tax table is in force in '.$date->format('F Y').'.']);
        }

        [$dp, $mode] = StatutoryService::rounding();
        $slabs = $version->toSlabs();
        $v = fn (string $k) => ($d[$k] ?? '') === '' ? '0' : $d[$k];
        $band = fn (array $r) => 'Band '.($r['band'] + 1).': over '.Format::money($r['over'], 0).($r['up_to'] === null ? ', no upper limit' : ' up to '.Format::money($r['up_to'], 0)).', rate '.$r['rate'].'%, less '.Format::money($r['fixed']);

        try {
            if ($d['table'] === 'apit_monthly') {
                $r = SlabTax::tax($slabs, $v('income'), $dp, $mode);
                $lines = [$version->label.' ('.$version->taxTable->name.')', $band($r),
                    Format::money($v('income')).' × '.$r['rate'].'% = '.Format::money($r['gross']).', less '.Format::money($r['fixed']).' = '.Format::money($r['tax'])];
            } else {
                $r = ApitLumpSum::tax($slabs, $v('a'), $v('b'), $v('c'), $v('tax_on_a'), $v('tax_on_b'), $v('prior'), $dp, $mode);
                $lines = [$version->label.' ('.$version->taxTable->name.')', 'D = A + B + C = '.Format::money($r['d']).'. '.$band($r),
                    'D × '.$r['rate'].'% = '.Format::money($r['gross']),
                    'Less fixed amount and tax already deducted or deductible = '.Format::money($r['credits']),
                    $r['raw'][0] === '-' ? 'The result is below zero, so the tax is nil.' : 'Tax on this lump sum = '.Format::money($r['tax'])];
            }
        } catch (FormulaException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'tax' => Format::money($r['tax']), 'lines' => $lines]);
    }
}
