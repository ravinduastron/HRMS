<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeSalaryLine;
use App\Models\EmployeeSalaryVersion;
use App\Models\PayComponent;
use App\Models\PayInput;
use App\Models\PayInputAddition;
use App\Models\PayInputHistory;
use App\Models\PayPeriod;
use App\Models\Scheme;
use App\Models\User;
use App\Payroll\Decimal;
use App\Payroll\PayInputSheet;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pay inputs: the amounts entered for a pay period (bonuses, incentives, variable allowances...). One value per period, employee and component.
 * Replacing a value keeps the old one in the history. Changes are possible only while the pay period is Open.
 * A pay run reads the values with amountsForRun(). No amounts are written to the audit log (the encrypted history holds them).
 */
class PayInputService
{
    /** Calculation methods whose amount is entered per period. */
    public const METHODS = ['input', 'variable'];

    /* ------------------------------ what can be entered ------------------------------ */

    /** Active components entered each period ("input") or as a variable amount ("variable"). */
    public function components(): Collection
    {
        return PayComponent::with('versions')->where('is_active', true)->orderBy('code')->get()->filter(fn ($c) => $this->isInputComponent($c))->values();
    }

    public function isInputComponent(PayComponent $c): bool
    {
        $c->loadMissing('versions');

        return $c->is_active && in_array($c->latestVersion()?->calc_method, self::METHODS, true);
    }

    /**
     * Which components each employee has for this period: those on their salary as it stands on the last day of the period,
     * plus any added for this period only (pay_input_additions).
     *
     * @return array<int, array<int, true>> employee id => [component id => true]
     */
    public function salaryMap(PayPeriod $period, array $employeeIds): array
    {
        if (! $employeeIds) {
            return [];
        }
        $versions = EmployeeSalaryVersion::withoutGlobalScopes()->whereIn('employee_id', $employeeIds)->where('approval_status', 'approved')
            ->whereDate('effective_from', '<=', $period->period_end->toDateString())->orderByDesc('effective_from')->orderByDesc('id')
            ->get(['id', 'employee_id'])->unique('employee_id');

        $owner = $versions->pluck('employee_id', 'id'); // version id => employee id
        $map = [];
        foreach ($owner->keys()->chunk(500) as $ids) {
            EmployeeSalaryLine::whereIn('employee_salary_version_id', $ids->all())->get(['employee_salary_version_id', 'pay_component_id'])
                ->each(function ($l) use ($owner, &$map) {
                    $map[$owner[$l->employee_salary_version_id]][$l->pay_component_id] = true;
                });
        }

        // Employees added to a component for THIS period only count as having it (the salary itself is not changed)
        PayInputAddition::withoutGlobalScopes()->where('pay_period_id', $period->id)->whereIn('employee_id', $employeeIds)->get(['employee_id', 'pay_component_id'])
            ->each(function ($a) use (&$map) {
                $map[$a->employee_id][$a->pay_component_id] = true;
            });

        return $map;
    }

    /** Payroll-active employees of the period's scheme the user may see; with a component, only those who have it on their salary. */
    public function eligibleEmployees(PayPeriod $period, ?PayComponent $component = null): Collection
    {
        $employees = Employee::query()->payrollEligible()->where('scheme_id', $period->scheme_id)->orderBy('employee_no')->get();
        if (! $component) {
            return $employees;
        }
        $map = $this->salaryMap($period, $employees->pluck('id')->all());

        return $employees->filter(fn ($e) => isset($map[$e->id][$component->id]))->values();
    }

    /** The scheme / period selection shared by the pay input screens. */
    public function picker(User $user, ?int $schemeId, ?int $periodId): array
    {
        $schemes = Scheme::visibleTo($user)->orderBy('name')->get();
        $scheme = $schemes->firstWhere('id', $schemeId) ?? $schemes->first();
        $periods = $scheme ? PayPeriod::where('scheme_id', $scheme->id)->orderByDesc('period_start')->orderByDesc('cycle_no')->limit(60)->get() : collect();

        $today = today();
        $period = $periods->firstWhere('id', $periodId)
            ?? $periods->first(fn ($p) => $p->isOpen() && $p->period_start->lte($today) && $p->period_end->gte($today))
            ?? $periods->first(fn ($p) => $p->isOpen() && $p->period_start->lte($today))
            ?? $periods->first(fn ($p) => $p->isOpen())
            ?? $periods->first();

        return ['schemes' => $schemes, 'scheme' => $scheme, 'periods' => $periods, 'period' => $period];
    }

    /* ------------------------------ changing values ------------------------------ */

    /** Sets one value. $salaryMap (from salaryMap()) and $periodChecked let a bulk caller avoid repeating queries. @return string created | replaced | unchanged */
    public function save(PayPeriod $period, Employee $employee, PayComponent $component, string $amount, string $source, ?int $batchId, User $by, ?array $salaryMap = null, bool $periodChecked = false): string
    {
        $amount = $this->amount($amount);
        $this->check($period, $employee, $component, $salaryMap, $periodChecked);

        return DB::transaction(function () use ($period, $employee, $component, $amount, $source, $batchId, $by) {
            $existing = PayInput::withoutGlobalScopes()->where('pay_period_id', $period->id)->where('employee_id', $employee->id)
                ->where('pay_component_id', $component->id)->lockForUpdate()->first();

            if ($existing && Decimal::cmp((string) $existing->amount, $amount) === 0) {
                return 'unchanged';
            }
            if ($existing) {
                $old = Decimal::round((string) $existing->amount, 2);
                $existing->forceFill(['amount' => $amount, 'source' => $source, 'import_batch_id' => $batchId, 'entered_by' => $by->id, 'entered_by_name' => $by->name])->save();
            } else {
                $old = null;
                (new PayInput)->forceFill([
                    'pay_period_id' => $period->id, 'employee_id' => $employee->id, 'pay_component_id' => $component->id, 'amount' => $amount,
                    'source' => $source, 'import_batch_id' => $batchId, 'approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), // approval engine hooks in here later
                    'entered_by' => $by->id, 'entered_by_name' => $by->name,
                ])->save();
            }
            $this->history($period->id, $employee->id, $component->id, $old === null ? 'created' : 'replaced', $old, $amount, $source, $batchId, $by);

            return $old === null ? 'created' : 'replaced';
        });
    }

    /**
     * Several values of one component from the screen ($amounts: employee id => amount; blank = leave as it is). One transaction.
     *
     * @return array{created: int, replaced: int, unchanged: int}
     */
    public function saveMany(PayPeriod $period, PayComponent $component, array $amounts, User $by): array
    {
        PayPeriodService::assertOpen($period);
        $r = ['created' => 0, 'replaced' => 0, 'unchanged' => 0];

        DB::transaction(function () use ($period, $component, $amounts, $by, &$r) {
            $employees = Employee::query()->whereIn('id', array_keys($amounts))->get()->keyBy('id'); // the scheme scope applies
            $map = $this->salaryMap($period, $employees->keys()->all());
            foreach ($amounts as $id => $amount) {
                if ($amount === null || trim((string) $amount) === '') {
                    continue;
                }
                $employee = $employees->get((int) $id);
                if (! $employee) {
                    throw ValidationException::withMessages(['amounts' => 'An employee in the list was not found, or is not in your schemes.']);
                }
                $r[$this->save($period, $employee, $component, (string) $amount, 'screen', null, $by, $map, true)]++;
            }
            if ($r['created'] + $r['replaced'] > 0) {
                Audit::log('pay_input.saved', $period, "Pay inputs {$component->code} entered for {$period->label()}", context: $r + ['component' => $component->code, 'scheme_id' => $period->scheme_id, 'source' => 'screen']);
            }
        });

        return $r;
    }

    public function remove(PayInput $input, User $by): void
    {
        DB::transaction(function () use ($input, $by) {
            $input = PayInput::withoutGlobalScopes()->whereKey($input->id)->lockForUpdate()->firstOrFail();
            $period = PayPeriod::withoutGlobalScopes()->findOrFail($input->pay_period_id);
            PayPeriodService::assertOpen($period);

            $component = PayComponent::findOrFail($input->pay_component_id);
            $this->history($period->id, $input->employee_id, $input->pay_component_id, 'removed', Decimal::round((string) $input->amount, 2), null, $input->source, null, $by);
            $input->delete();

            Audit::log('pay_input.removed', $period, "Pay input {$component->code} removed from {$period->label()}", context: ['component' => $component->code, 'employee_id' => $input->employee_id, 'scheme_id' => $period->scheme_id]);
        });
    }

    /**
     * For the pay run: the values of a period. Includes a value for a variable component, which then replaces the amount on the employee's salary for this run.
     *
     * @return array<int, array<int, string>> employee id => [component id => amount]
     */
    public function amountsForRun(PayPeriod $period, array $employeeIds): array
    {
        $out = [];
        PayInput::withoutGlobalScopes()->where('pay_period_id', $period->id)->where('approval_status', 'approved')->whereIn('employee_id', $employeeIds)->get()
            ->each(function ($i) use (&$out) {
                $out[$i->employee_id][$i->pay_component_id] = Decimal::round((string) $i->amount, 2);
            });

        return $out;
    }

    /* ------------------------------ new employees ------------------------------ */

    /**
     * Employees who joined after this date are "new" for the period. There is no pay run yet, so this stands in for "not in the previous pay run":
     * the end of the scheme's previous pay period (or, when there is none, the day before this period starts).
     */
    public function newCutoff(PayPeriod $period): string
    {
        $prev = PayPeriod::withoutGlobalScopes()->where('scheme_id', $period->scheme_id)->where('period_end', '<', $period->period_start->toDateString())
            ->orderByDesc('period_end')->first();

        return $prev ? $prev->period_end->toDateString() : $period->period_start->copy()->subDay()->toDateString();
    }

    public function isNew(Employee $employee, string $cutoff): bool
    {
        return $employee->date_joined !== null && $employee->date_joined->toDateString() > $cutoff;
    }

    /* ------------------------------ add employees to a pay item for one period ------------------------------ */

    /** Payroll-active employees of the period's scheme the user may see who do NOT have the component (on salary or already added). */
    public function addableEmployees(PayPeriod $period, PayComponent $component): Collection
    {
        $employees = Employee::query()->payrollEligible()->where('scheme_id', $period->scheme_id)->orderBy('employee_no')->get();
        $map = $this->salaryMap($period, $employees->pluck('id')->all());

        return $employees->reject(fn ($e) => isset($map[$e->id][$component->id]))->values();
    }

    /**
     * Adds employees to a pay item for this pay period only (the salary is not touched). All or none.
     *
     * @return Collection<int, Employee> the employees added
     */
    public function addEmployees(PayPeriod $period, PayComponent $component, array $employeeIds, User $by): Collection
    {
        PayPeriodService::assertOpen($period);
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        if (! $ids) {
            throw ValidationException::withMessages(['employee_ids' => 'Choose at least one employee.']);
        }
        if (! $this->isInputComponent($component)) {
            throw ValidationException::withMessages(['employee_ids' => "{$component->code} cannot be entered per period (it must be active and set to \"entered each period\" or \"variable amount\")."]);
        }

        return DB::transaction(function () use ($period, $component, $ids, $by) {
            $employees = Employee::query()->whereIn('id', $ids)->orderBy('employee_no')->get(); // the scheme scope applies
            if ($employees->count() !== count($ids)) {
                throw ValidationException::withMessages(['employee_ids' => 'An employee was not found, or is not in your schemes.']);
            }
            $map = $this->salaryMap($period, $ids);
            foreach ($employees as $e) {
                if ((int) $e->scheme_id !== (int) $period->scheme_id) {
                    throw ValidationException::withMessages(['employee_ids' => "{$e->employee_no} is not in the scheme of this pay period."]);
                }
                if (! $e->is_active || ! $e->is_payroll_active) {
                    throw ValidationException::withMessages(['employee_ids' => "{$e->employee_no} is inactive or excluded from payroll."]);
                }
                if (isset($map[$e->id][$component->id])) {
                    throw ValidationException::withMessages(['employee_ids' => "{$e->employee_no} already has {$component->code} for this period."]);
                }
            }
            foreach ($employees as $e) {
                (new PayInputAddition)->forceFill([
                    'pay_period_id' => $period->id, 'employee_id' => $e->id, 'pay_component_id' => $component->id,
                    'added_by' => $by->id, 'added_by_name' => $by->name,
                ])->save();
            }

            Audit::log('pay_input.employees_added', $period, "{$employees->count()} employee(s) added to {$component->code} for {$period->label()} only",
                context: ['component' => $component->code, 'count' => $employees->count(), 'employee_ids' => $ids, 'scheme_id' => $period->scheme_id]);

            return $employees;
        });
    }

    /** Takes an employee back out of a pay item for this period. Only while the period is Open and no amount was entered for it. */
    public function removeAddition(PayInputAddition $addition, User $by): void
    {
        DB::transaction(function () use ($addition, $by) {
            $a = PayInputAddition::withoutGlobalScopes()->whereKey($addition->id)->lockForUpdate()->firstOrFail();
            $period = PayPeriod::withoutGlobalScopes()->findOrFail($a->pay_period_id);
            PayPeriodService::assertOpen($period);

            $hasAmount = PayInput::withoutGlobalScopes()->where('pay_period_id', $a->pay_period_id)->where('employee_id', $a->employee_id)
                ->where('pay_component_id', $a->pay_component_id)->exists();
            if ($hasAmount) {
                throw ValidationException::withMessages(['addition' => 'An amount is entered for this employee. Remove the amount first (Pay inputs > Remove), then take the employee out.']);
            }

            $component = PayComponent::findOrFail($a->pay_component_id);
            $a->delete();
            Audit::log('pay_input.employee_removed', $period, "Employee removed from {$component->code} for {$period->label()}",
                context: ['component' => $component->code, 'employee_id' => $a->employee_id, 'scheme_id' => $period->scheme_id]);
        });
    }

    /* ------------------------------ internals ------------------------------ */

    /** Rules shared by the screen and the Excel upload. */
    public function check(PayPeriod $period, Employee $employee, PayComponent $component, ?array $salaryMap = null, bool $periodChecked = false): void
    {
        if (! $periodChecked) {
            PayPeriodService::assertOpen($period);
        }
        if ((int) $employee->scheme_id !== (int) $period->scheme_id) {
            throw ValidationException::withMessages(['amounts' => "{$employee->employee_no} is not in the scheme of this pay period."]);
        }
        if (! $employee->is_active || ! $employee->is_payroll_active) {
            throw ValidationException::withMessages(['amounts' => "{$employee->employee_no} is inactive or excluded from payroll."]);
        }
        if (! $this->isInputComponent($component)) {
            throw ValidationException::withMessages(['amounts' => "{$component->code} cannot be entered per period (it must be active and set to \"entered each period\" or \"variable amount\")."]);
        }
        $map = $salaryMap ?? $this->salaryMap($period, [$employee->id]);
        if (! isset($map[$employee->id][$component->id])) {
            throw ValidationException::withMessages(['amounts' => "{$component->code} is not on the salary of {$employee->employee_no} at the end of this period, and was not added for this period."]);
        }
    }

    public function amount(string $raw): string
    {
        $a = PayInputSheet::amount($raw);
        if ($a === null) {
            throw ValidationException::withMessages(['amounts' => 'Amounts must be numbers with up to 2 decimals (zero or more).']);
        }

        return $a;
    }

    public function history(int $periodId, int $employeeId, int $componentId, string $action, ?string $old, ?string $new, string $source, ?int $batchId, ?User $by): PayInputHistory
    {
        $h = new PayInputHistory;
        $h->forceFill([
            'pay_period_id' => $periodId, 'employee_id' => $employeeId, 'pay_component_id' => $componentId, 'action' => $action,
            'old_amount' => $old, 'new_amount' => $new, 'source' => $source, 'import_batch_id' => $batchId,
            'created_by' => $by?->id, 'created_by_name' => $by?->name,
        ])->save();

        return $h;
    }
}
