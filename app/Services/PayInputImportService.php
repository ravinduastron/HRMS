<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayComponent;
use App\Models\PayInput;
use App\Models\PayInputBatch;
use App\Models\PayInputHistory;
use App\Models\PayInputRow;
use App\Models\PayPeriod;
use App\Models\User;
use App\Payroll\Decimal;
use App\Payroll\PayInputSheet;
use App\Support\Audit;
use App\Support\Xlsx\XlsxReader;
use App\Support\Xlsx\XlsxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Excel upload of pay inputs for one pay period. The file is staged and checked row by row; nothing changes until the user confirms.
 * A value in the file REPLACES the value in force (the old one stays in the history); an empty cell changes nothing.
 * An applied upload can be reversed while the period is Open and none of its values was changed since.
 */
class PayInputImportService
{
    public const MAX_ROWS = 20000;

    public function __construct(private PayInputService $inputs) {}

    /* ------------------------------ template ------------------------------ */

    /** @param int[] $componentIds the components to put in the sheet (empty = all that can be entered) */
    public function template(PayPeriod $period, array $componentIds = []): string
    {
        $components = $this->inputs->components()->when($componentIds, fn ($c) => $c->filter(fn ($x) => in_array($x->id, $componentIds, true)))->values();
        if ($components->isEmpty()) {
            throw ValidationException::withMessages(['components' => 'Choose at least one component.']);
        }

        $employees = $this->inputs->eligibleEmployees($period);
        $map = $this->inputs->salaryMap($period, $employees->pluck('id')->all());
        $employees = $employees->filter(fn ($e) => $components->contains(fn ($c) => isset($map[$e->id][$c->id])))->values(); // only people who have at least one of them
        $current = PayInput::whereIn('pay_period_id', [$period->id])->whereIn('employee_id', $employees->pluck('id'))->get()->groupBy('employee_id')
            ->map(fn ($g) => $g->pluck('amount', 'pay_component_id'));

        $header = array_merge([['Employee No', XlsxWriter::STYLE_HEADER], ['Name', XlsxWriter::STYLE_HEADER]],
            $components->map(fn ($c) => [$c->code, XlsxWriter::STYLE_HEADER])->all());
        $rows = [$header];
        foreach ($employees as $e) {
            $row = [$e->employee_no, $e->display_name ?? $e->full_name];
            foreach ($components as $c) {
                $has = isset($map[$e->id][$c->id]);
                $v = $current[$e->id][$c->id] ?? null;
                $row[] = $has && $v !== null ? (float) Decimal::round((string) $v, 2) : null; // existing values are filled in so they can be edited
            }
            $rows[] = $row;
        }

        $help = [
            [['Pay inputs for '.$period->label().' ('.$period->period_start->format('d M Y').' to '.$period->period_end->format('d M Y').')', XlsxWriter::STYLE_BOLD]],
            ['Fill the amounts on the "Inputs" sheet and upload the file under Payroll > Pay inputs > Import from Excel. The file is checked first; nothing changes until you confirm.'],
            [''],
            ['One row per employee, one column per component. Do not rename or add columns, and do not change the Employee No.'],
            ['An amount replaces the value now in force for that employee and component (the old value is kept in the history). Existing values are already filled in.'],
            ['An EMPTY cell changes nothing. To remove a value use the screen (Pay inputs > Remove).'],
            ['Amounts are numbers with up to 2 decimals, zero or more. Only employees who have the component on their salary are in the sheet.'],
            ['This file belongs to this pay period only: uploading it for another period is refused. Keep the "Info" sheet.'],
        ];
        $info = [['pay_period_id', $period->id], ['scheme_id', $period->scheme_id], ['Period', $period->label()]];

        return (new XlsxWriter)
            ->addSheet('Instructions', $help, ['widths' => [1 => 140]])
            ->addSheet('Inputs', $rows, ['widths' => [1 => 14, 2 => 32] + array_combine(range(3, 2 + $components->count()), array_fill(0, $components->count(), 18)), 'textColumns' => [1], 'freezeHeader' => true])
            ->addSheet('Info', $info, ['widths' => [1 => 16, 2 => 30]])
            ->toBinary();
    }

    /* ------------------------------ staging ------------------------------ */

    public function stage(string $path, string $fileName, PayPeriod $period, User $by): PayInputBatch
    {
        PayPeriodService::assertOpen($period);

        try {
            $names = XlsxReader::sheetNames($path);
            $infoName = collect($names)->first(fn ($n) => strcasecmp($n, 'Info') === 0);
            $sheet = collect($names)->first(fn ($n) => strcasecmp($n, 'Inputs') === 0);
            if (! $infoName || ! $sheet) {
                throw ValidationException::withMessages(['file' => 'This is not a pay input template (the "Inputs" and "Info" sheets are missing). Download the template again.']);
            }
            $info = XlsxReader::read($path, $infoName);
            $rows = XlsxReader::read($path, $sheet);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $fileId = null;
        foreach ($info as $r) {
            if (strtolower(trim($r[1] ?? '')) === 'pay_period_id') {
                $fileId = trim($r[2] ?? '');
            }
        }
        if ($fileId === null || (string) $fileId !== (string) $period->id) {
            throw ValidationException::withMessages(['file' => "This file was made for another pay period. Download the template for {$period->label()} and use that."]);
        }

        $components = $this->inputs->components()->keyBy(fn ($c) => strtoupper($c->code));
        $sheetData = PayInputSheet::parse($rows, $components->keys()->all());
        if ($sheetData['employee_col'] === null) {
            throw ValidationException::withMessages(['file' => 'The "Employee No" column is missing. Download the template again.']);
        }
        if ($sheetData['unknown']) {
            throw ValidationException::withMessages(['file' => 'These columns are not components that can be entered: '.implode(', ', array_unique($sheetData['unknown'])).'. Download the template again.']);
        }
        if (! $sheetData['columns']) {
            throw ValidationException::withMessages(['file' => 'The sheet has no component columns.']);
        }
        if (! $sheetData['entries']) {
            throw ValidationException::withMessages(['file' => 'The sheet has no amounts. Fill in at least one cell.']);
        }
        if (count($sheetData['entries']) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has more than '.self::MAX_ROWS.' amounts. Split it into smaller files.']);
        }

        $ctx = $this->context($period, $sheetData['entries']);
        $seen = [];

        return DB::transaction(function () use ($sheetData, $components, $ctx, $period, $fileName, $by, &$seen) {
            $batch = PayInputBatch::create([
                'pay_period_id' => $period->id, 'scheme_id' => $period->scheme_id, 'file_name' => mb_substr($fileName, 0, 255), 'status' => 'staged',
                'uploaded_by' => $by->id, 'uploaded_by_name' => $by->name,
            ]);
            $valid = $errors = 0;
            foreach ($sheetData['entries'] as $entry) {
                [$data, $errs, $warns] = $this->validateEntry($entry, $components[$entry['code']], $period, $ctx, $seen);
                $status = $errs ? 'error' : 'valid';
                $status === 'valid' ? $valid++ : $errors++;
                PayInputRow::create(['pay_input_batch_id' => $batch->id, 'row_no' => $entry['row'], 'data' => $data, 'errors' => $errs ?: null, 'warnings' => $warns ?: null, 'status' => $status]);
            }
            $batch->update(['total_rows' => $valid + $errors, 'valid_rows' => $valid, 'error_rows' => $errors]);
            Audit::log('pay_input.import_staged', $batch, "Pay input file {$batch->file_name} uploaded for {$period->label()}: {$valid} valid, {$errors} with errors", context: ['batch_id' => $batch->id, 'scheme_id' => $period->scheme_id]);

            return $batch;
        });
    }

    /** Lookups for the whole file at once. */
    private function context(PayPeriod $period, array $entries): array
    {
        $numbers = collect($entries)->pluck('employee_no')->filter()->map(fn ($n) => mb_strtolower($n))->unique()->all();
        // Excel drops leading zeros from a number typed into a General cell (00123 becomes 123): those are matched too, if only one employee fits
        $digits = collect($numbers)->filter(fn ($n) => ctype_digit($n))->map(fn ($n) => ltrim($n, '0'))->filter()->unique()->all();

        $found = Employee::query()->where(fn ($q) => $q->whereIn(DB::raw('lower(employee_no)'), $numbers)
            ->when($digits, fn ($q) => $q->orWhereIn(DB::raw("trim(leading '0' from employee_no)"), $digits)))->get();
        $byDigits = $found->filter(fn ($e) => ctype_digit($e->employee_no))->groupBy(fn ($e) => ltrim($e->employee_no, '0'))->filter(fn ($g) => $g->count() === 1)->map->first();

        return [
            'employees' => $found->keyBy(fn ($e) => mb_strtolower($e->employee_no)), 'byDigits' => $byDigits,
            'salary' => $this->inputs->salaryMap($period, $found->pluck('id')->all()),
            'existing' => PayInput::where('pay_period_id', $period->id)->whereIn('employee_id', $found->pluck('id'))->get()->mapWithKeys(fn ($i) => [$i->employee_id.'|'.$i->pay_component_id => $i->amount]),
        ];
    }

    /** @return array{0: array, 1: string[], 2: string[]} cleaned data, errors, warnings. Messages never contain amounts. */
    private function validateEntry(array $entry, PayComponent $component, PayPeriod $period, array $ctx, array &$seen): array
    {
        $errors = $warnings = [];
        $no = $entry['employee_no'];

        $employee = $no !== '' ? $ctx['employees']->get(mb_strtolower($no)) : null;
        if (! $employee && ctype_digit($no) && ltrim($no, '0') !== '') {
            $employee = $ctx['byDigits']->get(ltrim($no, '0'));
            if ($employee) {
                $warnings[] = "Employee No was matched to {$employee->employee_no} (Excel had removed the leading zeros). Format the column as Text.";
            }
        }

        if ($no === '') {
            $errors[] = 'Employee No is empty.';
        } elseif (! $employee) {
            $errors[] = 'No employee has this number, or the employee is not in your schemes.';
        } else {
            if ((int) $employee->scheme_id !== (int) $period->scheme_id) {
                $errors[] = 'The employee is not in the scheme of this pay period.';
            }
            if (! $employee->is_active || ! $employee->is_payroll_active) {
                $errors[] = 'The employee is inactive or excluded from payroll.';
            }
            if (! isset($ctx['salary'][$employee->id][$component->id])) {
                $errors[] = "{$component->code} is not on the employee's salary at the end of this period.";
            }
            $key = $employee->id.'|'.$component->id;
            if (isset($seen[$key])) {
                $errors[] = 'The same employee and component appear earlier in this file.';
            }
            $seen[$key] = true;
        }

        $amount = PayInputSheet::amount($entry['raw']);
        if ($amount === null) {
            $errors[] = 'The amount must be a number with up to 2 decimals (zero or more).';
        } elseif ($employee && isset($ctx['existing'][$employee->id.'|'.$component->id])) {
            $now = $ctx['existing'][$employee->id.'|'.$component->id];
            $warnings[] = Decimal::cmp((string) $now, $amount) === 0 ? 'Same as the value already in force: nothing will change.' : 'Replaces the value now in force (the old value stays in the history).';
        }

        $data = [
            'employee_id' => $employee?->id, 'employee_no' => $no, 'employee_name' => $employee ? ($employee->display_name ?? $employee->full_name) : null,
            'component_id' => $component->id, 'component_code' => $component->code, 'amount' => $amount,
        ];

        return [$data, array_values(array_unique($errors)), $warnings];
    }

    /* ------------------------------ apply / reverse / discard ------------------------------ */

    /** Only the valid rows are applied, all together: if anything fails, nothing changes. @return array{applied: int, unchanged: int} */
    public function apply(PayInputBatch $batch, User $by): array
    {
        return DB::transaction(function () use ($batch, $by) {
            $batch = PayInputBatch::withoutGlobalScopes()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'staged') {
                throw ValidationException::withMessages(['batch' => 'This upload was already applied or discarded.']);
            }
            $period = PayPeriod::withoutGlobalScopes()->findOrFail($batch->pay_period_id);
            PayPeriodService::assertOpen($period);

            $rows = $batch->rows()->where('status', 'valid')->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['batch' => 'There are no valid rows to apply. Fix the errors and upload the file again.']);
            }

            $applied = $unchanged = 0;
            $map = $this->inputs->salaryMap($period, $rows->map(fn ($r) => $r->data['employee_id'])->unique()->all());
            foreach ($rows as $row) {
                $d = $row->data;
                $employee = Employee::withoutGlobalScopes()->findOrFail($d['employee_id']);
                $component = PayComponent::findOrFail($d['component_id']);
                $result = $this->inputs->save($period, $employee, $component, (string) $d['amount'], 'import', $batch->id, $by, $map, true); // checks everything again
                $result === 'unchanged' ? $unchanged++ : $applied++;
                $row->update(['status' => 'applied']);
            }

            $batch->update(['status' => 'applied', 'applied_rows' => $applied, 'unchanged_rows' => $unchanged, 'applied_at' => now()]);
            Audit::log('pay_input.import_applied', $batch, "Pay input upload #{$batch->id} applied to {$period->label()}: {$applied} changed, {$unchanged} unchanged",
                context: ['batch_id' => $batch->id, 'scheme_id' => $period->scheme_id, 'skipped_error_rows' => $batch->error_rows]);

            return ['applied' => $applied, 'unchanged' => $unchanged];
        });
    }

    /** Puts every value of the upload back as it was. Refused if the period is not Open or any value was changed since the upload. */
    public function reverse(PayInputBatch $batch, string $reason, User $by): int
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for reversing the upload.']);
        }

        return DB::transaction(function () use ($batch, $reason, $by) {
            $batch = PayInputBatch::withoutGlobalScopes()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'applied') {
                throw ValidationException::withMessages(['reason' => 'Only an applied upload can be reversed.']);
            }
            $period = PayPeriod::withoutGlobalScopes()->findOrFail($batch->pay_period_id);
            PayPeriodService::assertOpen($period);

            $entries = PayInputHistory::withoutGlobalScopes()->where('import_batch_id', $batch->id)->whereIn('action', ['created', 'replaced'])->get();

            $changed = [];
            foreach ($entries as $h) {
                $latest = PayInputHistory::withoutGlobalScopes()->where('pay_period_id', $h->pay_period_id)->where('employee_id', $h->employee_id)
                    ->where('pay_component_id', $h->pay_component_id)->orderByDesc('id')->value('id');
                if ($latest !== $h->id) {
                    $changed[] = Employee::withoutGlobalScopes()->whereKey($h->employee_id)->value('employee_no');
                }
            }
            if ($changed) {
                throw ValidationException::withMessages(['reason' => count($changed).' value(s) of this upload were changed afterwards ('.implode(', ', array_slice(array_unique($changed), 0, 5)).'), so it cannot be reversed. Correct them on the screen instead.']);
            }

            foreach ($entries as $h) {
                $input = PayInput::withoutGlobalScopes()->where('pay_period_id', $h->pay_period_id)->where('employee_id', $h->employee_id)->where('pay_component_id', $h->pay_component_id)->lockForUpdate()->firstOrFail();
                $now = Decimal::round((string) $input->amount, 2);
                if ($h->old_amount === null) {
                    $input->delete();
                    $this->inputs->history($h->pay_period_id, $h->employee_id, $h->pay_component_id, 'reversed', $now, null, 'import', $batch->id, $by);
                } else {
                    $old = Decimal::round((string) $h->old_amount, 2);
                    $input->forceFill(['amount' => $old, 'source' => 'restored', 'import_batch_id' => null, 'entered_by' => $by->id, 'entered_by_name' => $by->name])->save();
                    $this->inputs->history($h->pay_period_id, $h->employee_id, $h->pay_component_id, 'reversed', $now, $old, 'restored', $batch->id, $by);
                }
            }

            $batch->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_reason' => mb_substr(trim($reason), 0, 500)]);
            Audit::log('pay_input.import_reversed', $batch, "Pay input upload #{$batch->id} reversed ({$entries->count()} values restored)", context: ['reason' => trim($reason), 'scheme_id' => $period->scheme_id]);

            return $entries->count();
        });
    }

    public function discard(PayInputBatch $batch): void
    {
        if ($batch->status !== 'staged') {
            throw ValidationException::withMessages(['batch' => 'Only a staged upload can be discarded.']);
        }
        $batch->update(['status' => 'discarded']);
        Audit::log('pay_input.import_discarded', $batch, "Pay input upload #{$batch->id} discarded");
    }
}
