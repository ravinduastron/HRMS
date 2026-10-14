<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeSalaryLine;
use App\Models\EmployeeSalaryVersion;
use App\Models\PayComponent;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Payroll\ComponentCalculator;
use App\Payroll\Decimal;
use App\Payroll\FormulaException;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All employee-salary rules live here (a future approval engine hooks into store()).
 * Every change adds a NEW immutable version holding the complete list of the employee's components.
 * Amounts are monthly equivalents and are never written to the audit log.
 */
class EmployeeSalaryService
{
    public function __construct(private PayComponentService $components, private ComponentCalculator $calculator) {}

    /** Only components calculated as "fixed" carry an amount per employee. */
    public function isFixed(PayComponent $c): bool
    {
        return $c->latestVersion()?->calc_method === 'fixed';
    }

    public function inForce(Employee $employee, ?Carbon $date = null): ?EmployeeSalaryVersion
    {
        $date = ($date ?? today())->copy()->startOfDay();

        return EmployeeSalaryVersion::with('lines')->where('employee_id', $employee->id)->where('approval_status', 'approved')
            ->whereDate('effective_from', '<=', $date->toDateString())->orderByDesc('effective_from')->first();
    }

    /** @return array{current: ?EmployeeSalaryVersion, versions: Collection, next: ?EmployeeSalaryVersion} */
    public function summary(Employee $employee): array
    {
        $versions = EmployeeSalaryVersion::where('employee_id', $employee->id)->where('approval_status', 'approved')
            ->withCount('lines')->orderByDesc('effective_from')->get();
        $current = $this->inForce($employee);
        $current?->load('lines.component.versions');

        return ['current' => $current, 'versions' => $versions, 'next' => $versions->first(fn ($v) => $v->effective_from->gt(today()))];
    }

    /** Active components (with an approved version) the employee does not have yet, as combobox options. */
    public function addable(?EmployeeSalaryVersion $current): Collection
    {
        $have = $current ? $current->lines->pluck('pay_component_id')->all() : [];

        return PayComponent::where('is_active', true)->whereNotIn('id', $have)->with('versions')->orderBy('code')->get()
            ->filter(fn ($c) => $c->latestVersion())
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->code.' · '.$c->name])->values();
    }

    public function hasLaterVersion(Employee $employee, Carbon $date): bool
    {
        return EmployeeSalaryVersion::where('employee_id', $employee->id)->whereDate('effective_from', '>', $date->toDateString())->exists();
    }

    /** Messages for components whose formulas use components that are not in the given set (empty = consistent). */
    public function missingDependencies(array $componentIds): array
    {
        $codes = PayComponent::whereIn('id', $componentIds)->pluck('code')->all();
        $defs = $this->components->latestDefinitions();
        $problems = [];

        foreach ($codes as $code) {
            if (! isset($defs[$code])) {
                continue;
            }
            try {
                $refs = $this->calculator->references([$code => $defs[$code]])[$code];
            } catch (FormulaException) {
                continue;
            }
            $missing = array_values(array_filter($refs, fn ($r) => isset($defs[$r]) && ! in_array($r, $codes, true)));
            if ($missing) {
                $problems[] = "{$code} needs ".implode(', ', $missing).'.';
            }
        }

        return $problems;
    }

    /* ------------------------------ changes ------------------------------ */

    /** Assign a structure: copies its components (replacing everything the employee had from that date). */
    public function assign(Employee $employee, SalaryStructure $structure, Carbon $date, string $note, User $by): EmployeeSalaryVersion
    {
        if (! $structure->is_active) {
            throw ValidationException::withMessages(['structure_id' => 'That structure is inactive.']);
        }
        if (! $employee->scheme_id || $employee->scheme_id !== $structure->scheme_id) {
            throw ValidationException::withMessages(['structure_id' => 'Choose a structure that belongs to this employee\'s scheme.']);
        }

        $structure->load('lines.component.versions');
        if ($structure->lines->isEmpty()) {
            throw ValidationException::withMessages(['structure_id' => 'That structure has no components.']);
        }

        $lines = [];
        foreach ($structure->lines as $l) {
            $c = $l->component;
            if (! $c->is_active || ! $c->latestVersion()) {
                throw ValidationException::withMessages(['structure_id' => "{$c->code} is inactive or has no calculation. Update the structure first."]);
            }
            $amount = null;
            if ($this->isFixed($c)) {
                $default = $l->default_amount ?? $c->latestVersion()->default_amount;
                $amount = $default !== null ? Decimal::round(Decimal::make((string) $default), 2) : null;
            }
            $lines[$c->id] = ['amount' => $amount, 'source' => 'structure'];
        }

        return $this->store($employee, $structure, $lines, $date, 'assigned', $note, $by, ['structure' => $structure->name, 'components' => $structure->lines->pluck('component.code')->all()]);
    }

    public function addComponent(Employee $employee, PayComponent $component, ?string $amount, Carbon $date, string $note, User $by): EmployeeSalaryVersion
    {
        $lines = $this->baseLines($employee, $date);
        $component->loadMissing('versions');

        if (! $component->is_active || ! $component->latestVersion()) {
            throw ValidationException::withMessages(['pay_component_id' => "{$component->code} is inactive or has no calculation."]);
        }
        if (isset($lines[$component->id])) {
            throw ValidationException::withMessages(['pay_component_id' => "{$component->code} is already on this employee's salary."]);
        }

        $lines[$component->id] = ['amount' => $this->amountFor($component, $amount), 'source' => 'custom'];

        return $this->store($employee, null, $lines, $date, 'changed', $note, $by, ['added' => [$component->code]], $this->baseStructure($employee, $date));
    }

    public function setAmount(Employee $employee, PayComponent $component, ?string $amount, Carbon $date, string $note, User $by): EmployeeSalaryVersion
    {
        $lines = $this->baseLines($employee, $date);
        $component->loadMissing('versions');

        if (! isset($lines[$component->id])) {
            throw ValidationException::withMessages(['pay_component_id' => "{$component->code} is not on this employee's salary."]);
        }
        if (! $this->isFixed($component)) {
            throw ValidationException::withMessages(['amount' => "{$component->code} is not a fixed amount component."]);
        }

        $lines[$component->id]['amount'] = $this->amountFor($component, $amount);

        return $this->store($employee, null, $lines, $date, 'changed', $note, $by, ['amount_changed' => [$component->code]], $this->baseStructure($employee, $date));
    }

    public function removeComponent(Employee $employee, PayComponent $component, Carbon $date, string $note, User $by): EmployeeSalaryVersion
    {
        $lines = $this->baseLines($employee, $date);

        if (! isset($lines[$component->id])) {
            throw ValidationException::withMessages(['pay_component_id' => "{$component->code} is not on this employee's salary."]);
        }
        unset($lines[$component->id]);

        if (! $lines) {
            throw ValidationException::withMessages(['pay_component_id' => 'An employee needs at least one component. Assign a different structure instead.']);
        }

        return $this->store($employee, null, $lines, $date, 'changed', $note, $by, ['removed' => [$component->code]], $this->baseStructure($employee, $date));
    }

    /* ------------------------------ internals ------------------------------ */

    private function amountFor(PayComponent $component, ?string $amount): ?string
    {
        if (! $this->isFixed($component)) {
            return null; // formula / percentage / input / system components carry no stored amount
        }
        if ($amount === null || trim($amount) === '') {
            throw ValidationException::withMessages(['amount' => "Enter an amount for {$component->code}."]);
        }

        return Decimal::round(Decimal::make(trim($amount)), 2);
    }

    /** The employee's lines as they stand on $date: [componentId => ['amount' => ?string, 'source' => string]] */
    private function baseLines(Employee $employee, Carbon $date): array
    {
        $base = $this->inForce($employee, $date);
        if (! $base) {
            throw ValidationException::withMessages(['effective_from' => 'This employee has no salary in force on that date. Assign a structure first.']);
        }

        return $base->lines->mapWithKeys(fn ($l) => [$l->pay_component_id => ['amount' => $l->amount, 'source' => $l->source]])->all();
    }

    /** Custom changes keep pointing at the structure the employee started from. */
    private function baseStructure(Employee $employee, Carbon $date): ?EmployeeSalaryVersion
    {
        return $this->inForce($employee, $date);
    }

    private function store(Employee $employee, ?SalaryStructure $structure, array $lines, Carbon $date, string $type, string $note, User $by, array $audit, ?EmployeeSalaryVersion $from = null): EmployeeSalaryVersion
    {
        return DB::transaction(function () use ($employee, $structure, $lines, $date, $type, $note, $by, $audit, $from) {
            // Serialise changes per employee
            Employee::withoutGlobalScopes()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if (EmployeeSalaryVersion::withoutGlobalScopes()->where('employee_id', $employee->id)->whereDate('effective_from', $date->toDateString())->exists()) {
                throw ValidationException::withMessages(['effective_from' => 'A salary change already starts on '.$date->format('d M Y').'. Choose another date.']);
            }

            $problems = $this->missingDependencies(array_keys($lines));
            if ($problems) {
                throw ValidationException::withMessages(['pay_component_id' => 'Not saved. '.implode(' ', $problems).' Add the missing component(s), or remove the one that uses them.']);
            }

            $version = new EmployeeSalaryVersion;
            $version->forceFill([
                'employee_id' => $employee->id, 'effective_from' => $date->toDateString(),
                'salary_structure_id' => $structure?->id ?? $from?->salary_structure_id,
                'structure_name' => $structure?->name ?? $from?->structure_name,
                'change_type' => $type, 'change_note' => mb_substr(trim($note), 0, 500),
                'approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), // approval engine hooks in here later
                'created_by' => $by->id, 'created_by_name' => $by->name,
            ])->save();

            $components = PayComponent::whereIn('id', array_keys($lines))->get()->keyBy('id');
            foreach ($lines as $componentId => $l) {
                $c = $components[$componentId];
                (new EmployeeSalaryLine)->forceFill([
                    'employee_salary_version_id' => $version->id, 'pay_component_id' => $c->id,
                    'component_code' => $c->code, 'component_name' => $c->name,
                    'amount' => $l['amount'], 'source' => $l['source'],
                ])->save();
            }

            // No amounts in the audit trail: only which components changed.
            Audit::log('employee_salary.'.$type, $employee, 'Salary '.$type.' effective '.$date->format('d M Y'),
                context: $audit + ['version_id' => $version->id, 'effective_from' => $date->toDateString(), 'reason' => $version->change_note]);

            return $version;
        });
    }
}
