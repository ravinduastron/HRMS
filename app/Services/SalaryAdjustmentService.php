<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeSalaryLine;
use App\Models\EmployeeSalaryVersion;
use App\Models\PayComponent;
use App\Models\SalaryAdjustmentBatch;
use App\Models\SalaryAdjustmentLine;
use App\Models\SalaryAdjustmentRow;
use App\Models\Scheme;
use App\Models\User;
use App\Payroll\Decimal;
use App\Payroll\FormulaException;
use App\Payroll\SalaryAdjustment;
use App\Payroll\SalaryAdjustmentSheet;
use App\Support\Audit;
use App\Support\Xlsx\XlsxReader;
use App\Support\Xlsx\XlsxWriter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Salary adjustments (increments etc.) for the employees of ONE scheme. Rows are staged and checked first; nothing changes until the
 * batch is applied. Applying adds ONE new immutable salary version per employee through EmployeeSalaryService::setAmounts(), so history,
 * encryption, scheme scope and audit stay as they are. A rollback adds new versions that restore the old amounts.
 * No amounts are written to the audit log (they are in the encrypted rows and lines).
 */
class SalaryAdjustmentService
{
    public const MAX_ROWS = 20000;
    public const SHEET_MODES = 'Increment amount,Increment portion,New salary';

    public function __construct(private EmployeeSalaryService $salary) {}

    /** Active components that carry an amount (fixed or variable): the ones that can be adjusted. */
    public function components(): Collection
    {
        return PayComponent::with('versions')->where('is_active', true)->orderBy('code')->get()->filter(fn ($c) => $this->salary->isFixed($c))->values();
    }

    /**
     * The salary of each employee as it stands on $date, and the first LATER salary change.
     *
     * @return array<int, array{lines: array<int, array{amount: ?string}>, later: ?string}> employee id => ...
     */
    public function salaryAt(array $employeeIds, Carbon $date): array
    {
        $out = [];
        if (! $employeeIds) {
            return $out;
        }
        $d = $date->toDateString();

        $later = EmployeeSalaryVersion::withoutGlobalScopes()->whereIn('employee_id', $employeeIds)->where('approval_status', 'approved')
            ->whereDate('effective_from', '>', $d)->selectRaw('employee_id, min(effective_from) as d')->groupBy('employee_id')->pluck('d', 'employee_id');
        foreach ($employeeIds as $id) {
            $out[$id] = ['lines' => [], 'later' => isset($later[$id]) ? Carbon::parse($later[$id])->toDateString() : null];
        }

        $versions = EmployeeSalaryVersion::withoutGlobalScopes()->whereIn('employee_id', $employeeIds)->where('approval_status', 'approved')
            ->whereDate('effective_from', '<=', $d)->orderByDesc('effective_from')->orderByDesc('id')->get(['id', 'employee_id'])->unique('employee_id');
        $owner = $versions->pluck('employee_id', 'id');
        foreach ($owner->keys()->chunk(500) as $ids) {
            EmployeeSalaryLine::whereIn('employee_salary_version_id', $ids->all())->get()->each(function ($l) use ($owner, &$out) {
                $out[$owner[$l->employee_salary_version_id]]['lines'][$l->pay_component_id] = ['amount' => $l->amount !== null ? Decimal::round((string) $l->amount, 2) : null];
            });
        }

        return $out;
    }

    /* ------------------------------ staging ------------------------------ */

    /**
     * @param  array<int, array{row?: ?int, employee_no: string, component: string, mode_raw: string, value_raw: string}>  $entries
     * @param  bool  $skipMissing  screen entry: combinations where the employee has no such component are left out (and counted) instead of being errors
     */
    public function stage(Scheme $scheme, Carbon $date, string $reason, string $source, ?string $fileName, array $entries, User $by, bool $skipMissing = false, array $notes = []): SalaryAdjustmentBatch
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the adjustment.']);
        }
        if (! $entries) {
            throw ValidationException::withMessages(['rows' => 'There is nothing to adjust.']);
        }
        if (count($entries) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['rows' => 'More than '.self::MAX_ROWS.' rows. Split it into smaller adjustments.']);
        }
        $date = $date->copy()->startOfDay();
        $ctx = $this->context($scheme, $date, $entries);

        $seen = [];
        $rows = [];
        $skipped = 0;
        foreach (array_values($entries) as $i => $e) {
            [$data, $errors, $warnings, $skip] = $this->validateEntry($e, $ctx, $scheme, $skipMissing, $seen);
            if ($skip) {
                $skipped++;
                continue;
            }
            $rows[] = [$e['row'] ?? ($i + 1), $data, $errors, $warnings];
        }
        if (! $rows) {
            throw ValidationException::withMessages(['rows' => 'None of the selected employees has the chosen components with an amount on the effective date.']);
        }
        if ($skipped) {
            $notes[] = "{$skipped} combination(s) were left out: the employee does not have that component on their salary on the effective date.";
        }
        if ($date->lt(today())) {
            $notes[] = 'The effective date is in the past (back-dated). Arrears are not worked out here: they belong to the pay run.';
        }

        return DB::transaction(function () use ($scheme, $date, $reason, $source, $fileName, $rows, $notes, $by) {
            $valid = $errors = 0;
            $batch = SalaryAdjustmentBatch::create([
                'scheme_id' => $scheme->id, 'effective_from' => $date->toDateString(), 'reason' => mb_substr($reason, 0, 500), 'source' => $source,
                'file_name' => $fileName ? mb_substr($fileName, 0, 255) : null, 'status' => 'staged',
                'notes' => $notes ? implode("\n", $notes) : null, 'created_by' => $by->id, 'created_by_name' => $by->name,
            ]);
            foreach ($rows as [$no, $data, $errs, $warns]) {
                $status = $errs ? 'error' : 'valid';
                $status === 'valid' ? $valid++ : $errors++;
                SalaryAdjustmentRow::create(['salary_adjustment_batch_id' => $batch->id, 'row_no' => $no, 'data' => $data, 'errors' => $errs ?: null, 'warnings' => $warns ?: null, 'status' => $status]);
            }
            $batch->update(['total_rows' => $valid + $errors, 'valid_rows' => $valid, 'error_rows' => $errors]);
            Audit::log('salary_adjustment.staged', $batch, "Salary adjustment #{$batch->id} prepared for {$scheme->name}: {$valid} valid, {$errors} with errors",
                context: ['batch_id' => $batch->id, 'scheme_id' => $scheme->id, 'effective_from' => $date->toDateString(), 'source' => $source, 'reason' => $reason]);

            return $batch;
        });
    }

    /** Reads an uploaded sheet and stages it. The sheet belongs to ONE scheme (an "Info" sheet inside says which). */
    public function stageFile(string $path, string $fileName, Scheme $scheme, Carbon $date, string $reason, User $by): SalaryAdjustmentBatch
    {
        try {
            $names = XlsxReader::sheetNames($path);
            $info = collect($names)->first(fn ($n) => strcasecmp($n, 'Info') === 0);
            $sheet = collect($names)->first(fn ($n) => strcasecmp($n, 'Adjustments') === 0);
            if (! $info || ! $sheet) {
                throw ValidationException::withMessages(['file' => 'This is not a salary adjustment template (the "Adjustments" and "Info" sheets are missing). Download the template again.']);
            }
            $infoRows = XlsxReader::read($path, $info);
            $rows = XlsxReader::read($path, $sheet);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $fileScheme = $fileDate = null;
        foreach ($infoRows as $r) {
            $k = strtolower(trim($r[1] ?? ''));
            if ($k === 'scheme_id') {
                $fileScheme = trim($r[2] ?? '');
            } elseif ($k === 'effective_from') {
                $fileDate = trim($r[2] ?? '');
            }
        }
        if ($fileScheme === null || (string) $fileScheme !== (string) $scheme->id) {
            throw ValidationException::withMessages(['file' => "This file was made for another scheme. Download the template for {$scheme->name} and use that."]);
        }

        $parsed = SalaryAdjustmentSheet::parse($rows);
        if ($parsed['missing']) {
            throw ValidationException::withMessages(['file' => 'These columns are missing: '.implode(', ', $parsed['missing']).'. Download the template again.']);
        }
        if (! $parsed['entries']) {
            throw ValidationException::withMessages(['file' => 'No row has a Mode and a Value. Fill in at least one row.']);
        }

        $notes = [];
        if ($fileDate && $fileDate !== $date->toDateString()) {
            $notes[] = "The sheet was prepared for {$fileDate}, so the current amounts printed in it may differ. The new amounts are always worked out from the salary on the effective date you chose.";
        }

        return $this->stage($scheme, $date, $reason, 'import', $fileName, $parsed['entries'], $by, false, $notes);
    }

    private function context(Scheme $scheme, Carbon $date, array $entries): array
    {
        $numbers = collect($entries)->pluck('employee_no')->filter()->map(fn ($n) => mb_strtolower($n))->unique()->all();
        // Excel drops leading zeros from a number typed into a General cell (00123 becomes 123): those are matched too, if only one employee fits
        $digits = collect($numbers)->filter(fn ($n) => ctype_digit($n))->map(fn ($n) => ltrim($n, '0'))->filter()->unique()->all();

        $found = Employee::query()->where(fn ($q) => $q->whereIn(DB::raw('lower(employee_no)'), $numbers)
            ->when($digits, fn ($q) => $q->orWhereIn(DB::raw("trim(leading '0' from employee_no)"), $digits)))->get();
        $byDigits = $found->filter(fn ($e) => ctype_digit($e->employee_no))->groupBy(fn ($e) => ltrim($e->employee_no, '0'))->filter(fn ($g) => $g->count() === 1)->map->first();

        return [
            'employees' => $found->keyBy(fn ($e) => mb_strtolower($e->employee_no)), 'byDigits' => $byDigits,
            'salary' => $this->salaryAt($found->pluck('id')->all(), $date),
            'components' => PayComponent::with('versions')->get()->keyBy(fn ($c) => strtoupper($c->code)),
        ];
    }

    /** @return array{0: array, 1: string[], 2: string[], 3: bool} data, errors, warnings, skip. Messages never contain amounts. */
    private function validateEntry(array $e, array $ctx, Scheme $scheme, bool $skipMissing, array &$seen): array
    {
        $errors = $warnings = [];
        $no = (string) ($e['employee_no'] ?? '');

        $employee = $no !== '' ? $ctx['employees']->get(mb_strtolower($no)) : null;
        if (! $employee && ctype_digit($no) && ltrim($no, '0') !== '') {
            $employee = $ctx['byDigits']->get(ltrim($no, '0'));
            if ($employee) {
                $warnings[] = "Employee No was matched to {$employee->employee_no} (Excel had removed the leading zeros). Format the column as Text.";
            }
        }
        $code = strtoupper((string) ($e['component'] ?? ''));
        $component = $code !== '' ? ($ctx['components'][$code] ?? null) : null;

        if ($skipMissing && $employee && $component && ! isset($ctx['salary'][$employee->id]['lines'][$component->id])) {
            return [[], [], [], true];
        }

        if ($no === '') {
            $errors[] = 'Employee No is empty.';
        } elseif (! $employee) {
            $errors[] = 'No employee has this number, or the employee is not in your schemes.';
        } else {
            if ((int) $employee->scheme_id !== (int) $scheme->id) {
                $errors[] = 'The employee is not in the scheme of this adjustment.';
            }
            if (! $employee->is_active || ! $employee->is_payroll_active) {
                $errors[] = 'The employee is inactive or excluded from payroll.';
            }
        }

        if ($code === '') {
            $errors[] = 'Component is empty.';
        } elseif (! $component || ! $this->salary->isFixed($component)) {
            $errors[] = "{$code} is not a pay component that carries an amount (fixed or variable).";
        }

        $mode = SalaryAdjustment::mode((string) ($e['mode_raw'] ?? ''));
        $value = null;
        if ($mode === null) {
            $errors[] = 'Mode must be Increment amount, Increment portion or New salary.';
        } else {
            $value = SalaryAdjustment::value($mode, (string) ($e['value_raw'] ?? ''));
            if ($value === null) {
                $errors[] = SalaryAdjustment::valueHelp($mode);
            }
        }

        $current = $new = null;
        if ($employee && $component) {
            $key = $employee->id.'|'.$component->id;
            if (isset($seen[$key])) {
                $errors[] = 'The same employee and component appear earlier in this adjustment.';
            }
            $seen[$key] = true;

            $line = $ctx['salary'][$employee->id]['lines'][$component->id] ?? null;
            if ($line === null) {
                $errors[] = "The employee does not have {$component->code} on their salary on the effective date.";
            } elseif ($line['amount'] === null) {
                $errors[] = "{$component->code} has no amount on the employee's salary, so it cannot be adjusted (set an amount first).";
            } else {
                $current = $line['amount'];
            }

            if ($later = $ctx['salary'][$employee->id]['later'] ?? null) {
                $warnings[] = 'A later salary change exists from '.Carbon::parse($later)->format('d M Y').' and will not include this adjustment.';
            }
        }

        if ($mode && $value !== null && $current !== null) {
            try {
                $new = SalaryAdjustment::calculate($mode, $current, $value);
                if (Decimal::cmp($new, $current) === 0) {
                    $warnings[] = 'No change: the new amount equals the current amount.';
                }
            } catch (FormulaException $ex) {
                $errors[] = $ex->getMessage();
            }
        }

        $data = [
            'employee_id' => $employee?->id, 'employee_no' => $no, 'employee_name' => $employee ? ($employee->display_name ?? $employee->full_name) : null,
            'component_id' => $component?->id, 'component_code' => $component?->code ?? $code, 'mode' => $mode, 'value' => $value, 'current' => $current, 'new' => $errors ? null : $new,
        ];

        return [$data, array_values(array_unique($errors)), $warnings, false];
    }

    /* ------------------------------ apply / rollback / discard ------------------------------ */

    /** Applies every valid row, all together: if anything fails, nothing changes. @return array{applied: int, unchanged: int, employees: int} */
    public function apply(SalaryAdjustmentBatch $batch, User $by): array
    {
        return DB::transaction(function () use ($batch, $by) {
            $batch = SalaryAdjustmentBatch::withoutGlobalScopes()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'staged') {
                throw ValidationException::withMessages(['batch' => 'This adjustment was already applied or discarded.']);
            }
            $rows = $batch->rows()->where('status', 'valid')->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['batch' => 'There are no valid rows to apply. Fix the errors and prepare it again.']);
            }
            $date = $batch->effective_from->copy();

            $byEmployee = $rows->groupBy(fn ($r) => $r->data['employee_id']);
            $salary = $this->salaryAt($byEmployee->keys()->map(fn ($i) => (int) $i)->all(), $date);
            $employees = Employee::query()->whereIn('id', $byEmployee->keys())->get()->keyBy('id'); // the scheme scope applies

            // Check everything first: a salary that changed since the preview would make the preview wrong
            $stale = [];
            foreach ($byEmployee as $empId => $group) {
                $e = $employees->get((int) $empId);
                if (! $e || ! $e->is_active || ! $e->is_payroll_active) {
                    $stale[] = $group->first()->data['employee_no'];
                    continue;
                }
                foreach ($group as $row) {
                    $now = $salary[(int) $empId]['lines'][$row->data['component_id']]['amount'] ?? null;
                    if ($now === null || Decimal::cmp($now, (string) $row->data['current']) !== 0) {
                        $stale[] = $row->data['employee_no'];
                        break;
                    }
                }
            }
            if ($stale) {
                throw ValidationException::withMessages(['batch' => count($stale).' employee(s) changed since this was prepared, or are no longer in payroll ('.implode(', ', array_slice($stale, 0, 5)).'). Nothing was applied. Discard it and prepare it again.']);
            }

            $applied = $unchanged = $changedEmployees = 0;
            foreach ($byEmployee as $empId => $group) {
                $amounts = $meta = [];
                foreach ($group as $row) {
                    $d = $row->data;
                    if (Decimal::cmp((string) $d['new'], (string) $d['current']) === 0) {
                        $unchanged++;
                        continue;
                    }
                    $amounts[$d['component_id']] = (string) $d['new'];
                    $meta[$d['component_id']] = $d;
                }
                if (! $amounts) {
                    continue;
                }

                $version = $this->salary->setAmounts($employees[(int) $empId], $amounts, $date, "Adjustment #{$batch->id}: {$batch->reason}", $by);
                foreach ($meta as $cid => $d) {
                    (new SalaryAdjustmentLine)->forceFill([
                        'salary_adjustment_batch_id' => $batch->id, 'employee_id' => (int) $empId, 'pay_component_id' => $cid,
                        'old_amount' => (string) $d['current'], 'new_amount' => (string) $d['new'], 'applied_version_id' => $version->id,
                    ])->save();
                    $applied++;
                }
                $changedEmployees++;
            }

            SalaryAdjustmentRow::where('salary_adjustment_batch_id', $batch->id)->where('status', 'valid')->toBase()->update(['status' => 'applied', 'updated_at' => now()]);
            $batch->update(['status' => 'applied', 'applied_rows' => $applied, 'unchanged_rows' => $unchanged, 'employees_changed' => $changedEmployees,
                'applied_at' => now(), 'applied_by_name' => $by->name]);
            Audit::log('salary_adjustment.applied', $batch, "Salary adjustment #{$batch->id} applied: {$applied} amount(s) for {$changedEmployees} employee(s)",
                context: ['batch_id' => $batch->id, 'scheme_id' => $batch->scheme_id, 'effective_from' => $date->toDateString(), 'amounts_changed' => $applied, 'unchanged' => $unchanged, 'employees' => $changedEmployees]);

            return ['applied' => $applied, 'unchanged' => $unchanged, 'employees' => $changedEmployees];
        });
    }

    /**
     * Restores every amount the adjustment changed, by adding new salary versions (history is kept). All or nothing: refused if the salary
     * of ANY employee in it was changed afterwards.
     */
    public function rollback(SalaryAdjustmentBatch $batch, string $reason, User $by): int
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for rolling back the adjustment.']);
        }

        return DB::transaction(function () use ($batch, $reason, $by) {
            $batch = SalaryAdjustmentBatch::withoutGlobalScopes()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'applied') {
                throw ValidationException::withMessages(['reason' => 'Only an applied adjustment can be rolled back.']);
            }
            $byEmployee = SalaryAdjustmentLine::withoutGlobalScopes()->where('salary_adjustment_batch_id', $batch->id)->get()->groupBy('employee_id');
            if ($byEmployee->isEmpty()) {
                throw ValidationException::withMessages(['reason' => 'This adjustment changed no amounts, so there is nothing to roll back.']);
            }

            $blocked = [];
            foreach ($byEmployee as $empId => $group) {
                $latest = EmployeeSalaryVersion::withoutGlobalScopes()->where('employee_id', $empId)->max('id');
                if ((int) $latest !== (int) $group->first()->applied_version_id) {
                    $blocked[] = (int) $empId;
                }
            }
            if ($blocked) {
                $nos = Employee::withoutGlobalScopes()->whereIn('id', array_slice($blocked, 0, 5))->pluck('employee_no')->all();
                throw ValidationException::withMessages(['reason' => count($blocked).' employee(s) had their salary changed after this adjustment ('.implode(', ', $nos).'), so it cannot be rolled back. Nothing was changed.']);
            }

            $employees = Employee::query()->whereIn('id', $byEmployee->keys())->get()->keyBy('id'); // the scheme scope applies
            $date = $batch->effective_from->copy();
            foreach ($byEmployee as $empId => $group) {
                $employee = $employees->get((int) $empId);
                if (! $employee) {
                    throw ValidationException::withMessages(['reason' => 'An employee of this adjustment was not found, or is not in your schemes.']);
                }
                $amounts = $group->mapWithKeys(fn ($l) => [$l->pay_component_id => Decimal::round((string) $l->old_amount, 2)])->all();
                $version = $this->salary->setAmounts($employee, $amounts, $date, "Rollback of adjustment #{$batch->id}: ".trim($reason), $by);
                foreach ($group as $l) {
                    $l->forceFill(['rollback_version_id' => $version->id])->save();
                }
            }

            $batch->update(['status' => 'rolled_back', 'rolled_back_at' => now(), 'rolled_back_by_name' => $by->name, 'rolled_back_reason' => mb_substr(trim($reason), 0, 500)]);
            Audit::log('salary_adjustment.rolled_back', $batch, "Salary adjustment #{$batch->id} rolled back ({$byEmployee->count()} employees)",
                context: ['batch_id' => $batch->id, 'scheme_id' => $batch->scheme_id, 'reason' => trim($reason), 'employees' => $byEmployee->count()]);

            return $byEmployee->count();
        });
    }

    public function discard(SalaryAdjustmentBatch $batch): void
    {
        if ($batch->status !== 'staged') {
            throw ValidationException::withMessages(['batch' => 'Only an adjustment awaiting confirmation can be discarded.']);
        }
        $batch->update(['status' => 'discarded']);
        Audit::log('salary_adjustment.discarded', $batch, "Salary adjustment #{$batch->id} discarded");
    }

    /* ------------------------------ Excel template ------------------------------ */

    /** One long sheet: one row per employee and component that has an amount on the date. @param int[] $componentIds empty = all adjustable components */
    public function template(Scheme $scheme, Carbon $date, array $componentIds, bool $showCurrent): string
    {
        $components = $this->components()->when($componentIds, fn ($c) => $c->filter(fn ($x) => in_array($x->id, $componentIds, true)))->values();
        if ($components->isEmpty()) {
            throw ValidationException::withMessages(['components' => 'Choose at least one component.']);
        }
        $employees = Employee::query()->payrollEligible()->where('scheme_id', $scheme->id)->orderBy('employee_no')->get();
        $salary = $this->salaryAt($employees->pluck('id')->all(), $date);

        $rows = [[['Employee No', XlsxWriter::STYLE_HEADER], ['Name', XlsxWriter::STYLE_HEADER], ['Component', XlsxWriter::STYLE_HEADER],
            ['Current amount', XlsxWriter::STYLE_HEADER], ['Mode', XlsxWriter::STYLE_HEADER], ['Value', XlsxWriter::STYLE_HEADER]]];
        foreach ($employees as $e) {
            foreach ($components as $c) {
                $amount = $salary[$e->id]['lines'][$c->id]['amount'] ?? null;
                if ($amount === null) {
                    continue;
                }
                $rows[] = [$e->employee_no, $e->display_name ?? $e->full_name, $c->code, $showCurrent ? (float) $amount : null, null, null];
            }
        }
        $n = count($rows) - 1;
        if ($n === 0) {
            throw ValidationException::withMessages(['components' => 'No employee of this scheme has the chosen components with an amount on that date.']);
        }
        if ($n > self::MAX_ROWS) {
            throw ValidationException::withMessages(['components' => 'The sheet would have more than '.self::MAX_ROWS.' rows. Choose fewer components.']);
        }

        $help = [
            [['Salary adjustment for '.$scheme->name.' (amounts as on '.$date->format('d M Y').')', XlsxWriter::STYLE_BOLD]],
            ['Fill Mode and Value on the "Adjustments" sheet for the rows you want to change, then upload the file under Compensation > Salary adjustments > Import from Excel. The file is checked first; nothing changes until you confirm.'],
            [''],
            [['Modes', XlsxWriter::STYLE_BOLD]],
            ['Increment amount: Value is added to the current amount. Use a minus sign to reduce (for example 2500 or -1000).'],
            ['Increment portion: Value is a percentage of the current amount, added to it (for example 10 for +10%, -5 for -5%).'],
            ['New salary: Value becomes the new amount (zero or more).'],
            ['You can use a different mode on every row. A row with Mode and Value both empty is ignored. Amounts have up to 2 decimals, half up. The result may not be below 0.'],
            [''],
            ['Do not rename or add columns, do not change Employee No or Component. The "Current amount" and "Name" columns are for your information only. Keep the "Info" sheet: the file only works for this scheme.'],
        ];
        $info = [['scheme_id', $scheme->id], ['effective_from', $date->toDateString()], ['Scheme', $scheme->name]];

        return (new XlsxWriter)
            ->addSheet('Instructions', $help, ['widths' => [1 => 150]])
            ->addSheet('Adjustments', $rows, [
                'widths' => [1 => 14, 2 => 32, 3 => 18, 4 => 16, 5 => 20, 6 => 16], 'textColumns' => [1, 3], 'freezeHeader' => true,
                'validations' => [['sqref' => 'E2:E'.($n + 1), 'list' => self::SHEET_MODES, 'message' => 'Choose Increment amount, Increment portion or New salary.']],
            ])
            ->addSheet('Info', $info, ['widths' => [1 => 16, 2 => 30]])
            ->toBinary();
    }
}
