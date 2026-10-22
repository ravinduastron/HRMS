<?php

namespace App\Http\Controllers;

use App\Models\EmployeeSalaryVersion;
use App\Models\PayComponent;
use App\Models\SalaryStructure;
use App\Models\Scheme;
use App\Payroll\Decimal;
use App\Services\EmployeeSalaryService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Structures are scoped by scheme: a user manages only the structures of the schemes allocated to them. */
class SalaryStructureController extends Controller
{
    public function __construct(private EmployeeSalaryService $salary) {}

    public function index(Request $request): View
    {
        $structures = SalaryStructure::with('scheme')->withCount('lines')
            ->when($request->query('scheme'), fn ($q, $s) => $q->where('scheme_id', $s))
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')->get();

        $assigned = EmployeeSalaryVersion::withoutGlobalScopes()->whereIn('salary_structure_id', $structures->pluck('id'))
            ->selectRaw('salary_structure_id, count(distinct employee_id) as total')->groupBy('salary_structure_id')->pluck('total', 'salary_structure_id');

        return view('salary.structures.index', [
            'structures' => $structures, 'assigned' => $assigned,
            'schemes' => Scheme::visibleTo($request->user())->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('salary.structures.create', $this->formData($request, new SalaryStructure(['is_active' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $structure = DB::transaction(function () use ($data) {
            $s = SalaryStructure::create([
                'scheme_id' => $data['scheme_id'], 'name' => trim($data['name']), 'description' => $data['description'] ?? null, 'is_active' => true,
            ]);
            $this->saveLines($s, $data['lines']);

            return $s;
        });

        return redirect()->route('salary-structures.show', $structure)->with('status', 'Salary structure created.');
    }

    public function show(SalaryStructure $structure): View
    {
        return view('salary.structures.show', [
            'structure' => $structure->load(['scheme', 'lines.component.versions']),
            'assigned' => $structure->employeeVersions()->distinct()->count('employee_id'),
        ]);
    }

    public function edit(Request $request, SalaryStructure $structure): View
    {
        return view('salary.structures.edit', $this->formData($request, $structure->load('lines')));
    }

    public function update(Request $request, SalaryStructure $structure): RedirectResponse
    {
        $data = $this->validated($request, $structure);

        DB::transaction(function () use ($data, $structure) {
            // Editing a structure never touches employees already assigned: their lines were copied.
            $structure->update(['scheme_id' => $data['scheme_id'], 'name' => trim($data['name']), 'description' => $data['description'] ?? null]);
            $this->saveLines($structure, $data['lines']);
        });

        return redirect()->route('salary-structures.show', $structure)
            ->with('status', 'Salary structure updated. Employees already assigned keep their own components.');
    }

    public function toggle(SalaryStructure $structure): RedirectResponse
    {
        $structure->update(['is_active' => ! $structure->is_active]);

        return back()->with('status', $structure->is_active ? 'Structure activated.' : 'Structure deactivated. Employees already assigned are not affected.');
    }

    /* ------------------------------------------------------------------ */

    private function validated(Request $request, ?SalaryStructure $structure = null): array
    {
        $allowed = Scheme::visibleTo($request->user())->pluck('id')->all();
        $used = $structure && $structure->employeeVersions()->exists(); // the scheme of a structure in use is fixed
        $schemeId = $used ? $structure->scheme_id : (int) $request->input('scheme_id');

        $data = $request->validate([
            'scheme_id' => $used ? ['nullable'] : ['required', 'integer', Rule::in($allowed)],
            'name' => ['required', 'string', 'max:100', Rule::unique('salary_structures', 'name')->where(fn ($q) => $q->where('scheme_id', $schemeId))->ignore($structure)],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.component_id' => ['required', 'integer', 'distinct', Rule::exists('pay_components', 'id')->where('is_active', true)],
            'lines.*.default_amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'lines.*.rate_basis' => ['nullable', 'in:monthly,daily,hourly'],
        ], [
            'scheme_id.in' => 'You can only use schemes that are allocated to you.',
            'name.unique' => 'This scheme already has a structure with that name.',
            'lines.required' => 'Add at least one pay component.',
            'lines.min' => 'Add at least one pay component.',
            'lines.*.component_id.exists' => 'A selected component is inactive or does not exist. Remove it from the list.',
            'lines.*.default_amount.regex' => 'Default amounts must be numbers with up to 2 decimals.',
            'lines.*.rate_basis.in' => 'Choose monthly, daily or hourly as the rate basis.',
        ]);

        $data['scheme_id'] = $schemeId;

        return $data;
    }

    private function saveLines(SalaryStructure $structure, array $lines): void
    {
        $components = PayComponent::with('versions')->whereIn('id', collect($lines)->pluck('component_id'))->get()->keyBy('id');

        foreach ($components as $c) {
            if (! $c->latestVersion()) {
                throw ValidationException::withMessages(['lines' => "{$c->code} has no calculation yet."]);
            }
        }
        $problems = $this->salary->missingDependencies($components->keys()->all());
        if ($problems) {
            throw ValidationException::withMessages(['lines' => implode(' ', $problems).' Add the missing component(s), or remove the one that uses them.']);
        }

        $snapshot = fn () => $structure->lines()->with('component')->get()
            ->mapWithKeys(fn ($l) => [$l->component->code => ['amount' => $l->default_amount, 'basis' => $l->rate_basis]])->all();
        $before = $snapshot();

        $structure->lines()->delete();
        foreach (array_values($lines) as $i => $l) {
            $c = $components[$l['component_id']];
            $fixed = $this->salary->isFixed($c);
            $amount = $fixed && ($l['default_amount'] ?? '') !== '' ? Decimal::round(Decimal::make($l['default_amount']), 2) : null;
            // Only fixed-amount lines have a rate basis; every other line is stored as monthly.
            $basis = $fixed ? (($l['rate_basis'] ?? '') ?: 'monthly') : 'monthly';
            $structure->lines()->create(['pay_component_id' => $c->id, 'default_amount' => $amount, 'rate_basis' => $basis, 'sort' => $i]);
        }

        $after = $snapshot();
        if ($before !== $after) {
            Audit::log('salary_structure.lines_changed', $structure, 'Components, default amounts or rate bases changed', ['lines' => $before], ['lines' => $after]);
        }
    }

    private function formData(Request $request, SalaryStructure $structure): array
    {
        $current = $structure->exists ? $structure->lines->pluck('pay_component_id')->all() : [];

        $all = PayComponent::with('versions')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $current))->orderBy('code')->get()
            ->filter(fn ($c) => $c->latestVersion())
            ->map(fn ($c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'type' => PayComponent::TYPES[$c->type],
                'fixed' => $this->salary->isFixed($c), 'inactive' => ! $c->is_active,
                'default' => $c->latestVersion()->default_amount !== null ? (string) $c->latestVersion()->default_amount : null,
            ])->values();

        $initial = old('lines') !== null
            ? collect(old('lines'))->map(fn ($l) => ['id' => (int) ($l['component_id'] ?? 0), 'amount' => (string) ($l['default_amount'] ?? ''), 'basis' => ($l['rate_basis'] ?? '') ?: 'monthly'])->values()
            : ($structure->exists ? $structure->lines->map(fn ($l) => ['id' => $l->pay_component_id, 'amount' => $l->default_amount !== null ? (string) $l->default_amount : '', 'basis' => $l->rate_basis ?: 'monthly'])->values() : collect());

        return [
            'structure' => $structure, 'components' => $all, 'initialLines' => $initial,
            'schemes' => Scheme::visibleTo($request->user())->orderBy('name')->get(),
            'schemeLocked' => $structure->exists && $structure->employeeVersions()->exists(),
        ];
    }
}
