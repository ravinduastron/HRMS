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
 * Every change adds a NEW immutable version holding the complete list of the employee's components and the employee's
 * pay rules (hours limit, statutory exemption), which are carried forward from the version they build on.
 * Amounts are never written to the audit log. A fixed amount has a rate basis: monthly, daily or hourly.
 */
class EmployeeSalaryService
{
    public const BASES = ['monthly' => 'Monthly', 'daily' => 'Daily', 'hourly' => 'Hourly'];

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
            ->whereDate('effective_from', '<=', $date->toDateString())->orderByDesc('effective_from')->orderByDesc('id')->first(); // several changes can share a date: the last saved wins
    }

    /** A valid Y-m-d date from a query string, otherwise today. */
    public function dateOrToday(?string $value): Carbon
    {
        if ($value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                return Carbon::parse($value)->startOfDay();
            } catch (\Throwable) {
                // fall through
            }
        }

        return today();
    }

    /**
     * The salary as it stands on $date (default today), the full history (newest first, component names only, no amounts)
     * and the next change after $date.
     *
     * @return array{current: ?EmployeeSalaryVersion, versions: Collection, next: ?EmployeeSalaryVersion, date: Carbon}
     */
    public function summary(Employee $employee, ?Carbon $date = null): array
    {
        $date = ($date ?? today())->copy()->startOfDay();

        $versions = EmployeeSalaryVersion::where('employee_id', $employee->id)->where('approval_status', 'approved')
            ->with(['lines' => fn ($q) => $q->select('id', 'employee_salary_version_id', 'pay_component_id', 'component_code', 'component_name')])
            ->orderByDesc('effective_from')->orderByDesc('id')->get();
        $current = $this->inForce($employee, $date);
        $current?->load('lines.component.versions');

        return [
            'current' => $current, 'versions' => $versions, 'date' => $date,
            'next' => $versions->filter(fn ($v) => $v->effective_from->gt($date))->sortBy('effective_from')->first(),
        ];
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

    /** Assign a structure: copies its components (replacing everything the employee had from that date). Pay rules are kept. */
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
            $lines[$c->id] = ['amount' => $amount, 'source' => 'structure', 'basis' => $this->isFixed($c) ? ($l->rate_basis ?: 'monthly') : 'monthly']; // change the basis per employee afterwards
        }

        return $this->store($employee, $structure, $lines, $date, 'assigned', $note, $by, ['structure' => $structure->name, 'components' => $structure->lines->pluck('component.code')->all()]);
    }

    public function addComponent(Employee $employee, PayComponent $component, ?string $amount, ?string $basis, Carbon $date, string $note, User $by): EmployeeSalaryVersion
    {
        $lines = $this->baseLines($employee, $date);
        $component->loadMissing('versions');

        if (! $component->is_active || ! $component->latestVersion()) {
            throw ValidationException::withMessages(['pay_component_id' => "{$component->code} is inactive or has no calculation."]);
        }
        if (isset($lines[$component->id])) {
            throw ValidationException::withMessages(['pay_component_id' => "{$component->code} is already on this employee's salary."]);
        }

        $lines[$component->id] = ['amount' => $this->amountFor($component, $amount), 'source' => 'custom', 'basis' => $this->basisFor($component, $basis, 'monthly')];

        return $this->store($employee, null, $lines, $date, 'changed', $note, $by, ['added' => [$component->code]], $this->baseVersion($employee, $date));
    }

    /** Change the amount of a fixed component, and optionally its rate basis (monthly, daily or hourly). */
    public function setAmount(Employee $employee, PayComponent $component, ?string $amount, ?string $basis, Carbon $date, string $note, User $by): EmployeeSalaryVersion
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
        $lines[$component->id]['basis'] = $this->basisFor($component, $basis, $lines[$component->id]['basis']);

        return $this->store($employee, null, $lines, $date, 'changed', $note, $by, ['amount_changed' => [$component->code], 'basis' => [$component->code => $lines[$component->id]['basis']]], $this->baseVersion($employee, $date));
    }

    /** Several amounts in ONE new version (blank = unchanged). Each line keeps its own rate basis. */
    public function setAmounts(Employee $employee, array $amounts, Carbon $date, string $note, User $by): EmployeeSalaryVersion
    {
        $lines = $this->baseLines($employee, $date);
        $components = PayComponent::with('versions')->whereIn('id', array_keys($amounts))->get()->keyBy('id');
        $changed = [];

        foreach ($amounts as $id => $amount) {
            if ($amount === null || trim((string) $amount) === '') {
                continue;
            }
            $c = $components[$id] ?? null;
            if (! $c || ! isset($lines[$c->id])) {
                throw ValidationException::withMessages(['amount' => 'One of the components is not on this employee\'s salary.']);
            }
            if (! $this->isFixed($c)) {
                throw ValidationException::withMessages(['amount' => "{$c->code} is not a fixed amount component."]);
            }
            $new = $this->amountFor($c, (string) $amount);
            if ($lines[$c->id]['amount'] !== $new) {
                $lines[$c->id]['amount'] = $new;
                $changed[] = $c->code;
            }
        }

        if (! $changed) {
            throw ValidationException::withMessages(['amount' => 'No amounts were changed.']);
        }

        return $this->store($employee, null, $lines, $date, 'changed', $note, $by, ['amount_changed' => $changed], $this->baseVersion($employee, $date));
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

        return $this->store($employee, null, $lines, $date, 'changed', $note, $by, ['removed' => [$component->code]], $this->baseVersion($employee, $date));
    }

    /**
     * Employee pay rules: the most hours paid per month (null = no limit; ALL hours count, overtime included) and whether the
     * employee is exempt from EPF / MSPS / ETF. A new version with the same components.
     */
    public function setRules(Employee $employee, ?string $maxHours, bool $statutoryExempt, Carbon $date, string $note, User $by): EmployeeSalaryVersion
    {
        $lines = $this->baseLines($employee, $date);
        $base = $this->baseVersion($employee, $date);

        $hours = null;
        if ($maxHours !== null && trim($maxHours) !== '') {
            $hours = Decimal::round(Decimal::make(trim($maxHours)), 2);
            if (Decimal::cmp($hours, '0') <= 0 || Decimal::cmp($hours, '744') > 0) {
                throw ValidationException::withMessages(['max_hours' => 'The hours limit must be more than 0 and at most 744 hours a month. Leave it empty for no limit.']);
            }
        }

        $oldHours = $base->max_hours !== null ? Decimal::plain((string) $base->max_hours) : null;
        $newHours = $hours !== null ? Decimal::plain($hours) : null;
        if ($oldHours === $newHours && (bool) $base->statutory_exempt === $statutoryExempt) {
            throw ValidationException::withMessages(['max_hours' => 'Nothing was changed.']);
        }

        return $this->store($employee, null, $lines, $date, 'changed', $note, $by,
            ['rules_changed' => ['max_hours' => ['from' => $oldHours, 'to' => $newHours], 'statutory_exempt' => ['from' => (bool) $base->statutory_exempt, 'to' => $statutoryExempt]]],
            $base, ['max_hours' => $hours, 'statutory_exempt' => $statutoryExempt]);
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

    /** Rate basis of a line. Only fixed components have one; the others are always stored as monthly. */
    private function basisFor(PayComponent $component, ?string $basis, string $fallback): string
    {
        if (! $this->isFixed($component)) {
            return 'monthly';
        }
        if ($basis === null || $basis === '') {
            return $fallback;
        }
        if (! array_key_exists($basis, self::BASES)) {
            throw ValidationException::withMessages(['rate_basis' => 'Choose monthly, daily or hourly.']);
        }

        return $basis;
    }

    /** The employee's lines as they stand on $date: [componentId => ['amount' => ?string, 'source' => string, 'basis' => string]] */
    private function baseLines(Employee $employee, Carbon $date): array
    {
        $base = $this->inForce($employee, $date);
        if (! $base) {
            throw ValidationException::withMessages(['effective_from' => 'This employee has no salary in force on that date. Assign a structure first.']);
        }

        return $base->lines->mapWithKeys(fn ($l) => [$l->pay_component_id => ['amount' => $l->amount, 'source' => $l->source, 'basis' => $l->rate_basis ?: 'monthly']])->all();
    }

    /** The version a change builds on (the one in force on that date). */
    private function baseVersion(Employee $employee, Carbon $date): ?EmployeeSalaryVersion
    {
        return $this->inForce($employee, $date);
    }

    private function store(Employee $employee, ?SalaryStructure $structure, array $lines, Carbon $date, string $type, string $note, User $by, array $audit, ?EmployeeSalaryVersion $from = null, ?array $rules = null): EmployeeSalaryVersion
    {
        return DB::transaction(function () use ($employee, $structure, $lines, $date, $type, $note, $by, $audit, $from, $rules) {
            // Serialise changes per employee
            Employee::withoutGlobalScopes()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $problems = $this->missingDependencies(array_keys($lines));
            if ($problems) {
                throw ValidationException::withMessages(['pay_component_id' => 'Not saved. '.implode(' ', $problems).' Add the missing component(s), or remove the one that uses them.']);
            }

            // Pay rules (hours limit, statutory exemption) are carried forward from the version this change builds on.
            $base = $from ?? $this->inForce($employee, $date);
            $rules ??= ['max_hours' => $base?->max_hours, 'statutory_exempt' => (bool) ($base?->statutory_exempt)];

            $version = new EmployeeSalaryVersion;
            $version->forceFill([
                'employee_id' => $employee->id, 'effective_from' => $date->toDateString(),
                'salary_structure_id' => $structure?->id ?? $from?->salary_structure_id,
                'structure_name' => $structure?->name ?? $from?->structure_name,
                'change_type' => $type, 'change_note' => mb_substr(trim($note), 0, 500),
                'max_hours' => $rules['max_hours'], 'statutory_exempt' => $rules['statutory_exempt'],
                'approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), // approval engine hooks in here later
                'created_by' => $by->id, 'created_by_name' => $by->name,
            ])->save();

            $components = PayComponent::whereIn('id', array_keys($lines))->get()->keyBy('id');
            foreach ($lines as $componentId => $l) {
                $c = $components[$componentId];
                (new EmployeeSalaryLine)->forceFill([
                    'employee_salary_version_id' => $version->id, 'pay_component_id' => $c->id,
                    'component_code' => $c->code, 'component_name' => $c->name,
                    'amount' => $l['amount'], 'rate_basis' => $l['basis'] ?? 'monthly', 'source' => $l['source'],
                ])->save();
            }

            // No amounts in the audit trail: only which components changed (and the pay rules, which are not amounts).
            Audit::log('employee_salary.'.$type, $employee, 'Salary '.$type.' effective '.$date->format('d M Y'),
                context: $audit + ['version_id' => $version->id, 'effective_from' => $date->toDateString(), 'reason' => $version->change_note]);

            return $version;
        });
    }
}
