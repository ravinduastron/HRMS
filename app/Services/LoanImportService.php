<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanImportBatch;
use App\Models\LoanImportRow;
use App\Models\LoanType;
use App\Models\User;
use App\Payroll\Decimal;
use App\Payroll\Loans\LoanSchedule;
use App\Support\Audit;
use App\Support\Xlsx\XlsxReader;
use App\Support\Xlsx\XlsxWriter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Excel import of EXISTING loans. Upload is staged and checked row by row; nothing is created until the user confirms.
 * Only valid rows are created, in one transaction. A batch can be reversed while none of its loans has had a deduction.
 */
class LoanImportService
{
    /** field => [column title in the template, what a column title must start with once lower-cased and stripped of symbols] */
    public const COLUMNS = [
        'employee_no' => ['Employee No', 'employeeno'], 'loan_type' => ['Loan Type', 'loantype'], 'reference' => ['Loan Reference', 'loanreference'],
        'original_amount' => ['Original Amount', 'originalamount'], 'disbursed_on' => ['Disbursed On (YYYY-MM-DD)', 'disbursedon'],
        'interest_method' => ['Interest Method', 'interestmethod'], 'interest_rate' => ['Interest Rate % (yearly)', 'interestrate'],
        'term_months' => ['Original Term (months)', 'originalterm'], 'outstanding' => ['Outstanding Principal', 'outstandingprincipal'],
        'remaining' => ['Remaining Installments', 'remaininginstallments'], 'next_month' => ['Next Deduction Month (YYYY-MM)', 'nextdeductionmonth'],
        'installment' => ['Current Installment (optional)', 'currentinstallment'], 'notes' => ['Notes', 'notes'],
    ];

    private const REQUIRED = ['employee_no', 'loan_type', 'reference', 'original_amount', 'disbursed_on', 'term_months', 'outstanding', 'remaining', 'next_month'];

    /* ------------------------------ template ------------------------------ */

    public function template(): string
    {
        $types = LoanType::where('is_active', true)->orderBy('code')->get();
        $header = array_map(fn ($c) => [$c[0], XlsxWriter::STYLE_HEADER], array_values(self::COLUMNS));

        $help = [
            [['Loading existing loans', XlsxWriter::STYLE_BOLD]],
            ['Fill the "Loans" sheet, one row per loan, and upload it under Loans > Import. The file is checked first; nothing is created until you confirm.'],
            [''],
            [['Columns marked * must be filled', XlsxWriter::STYLE_BOLD]],
            ['Employee No *: as in the employee list (keep leading zeros).'],
            ['Loan Type *: choose from the list (the codes are on the "Lists" sheet).'],
            ['Loan Reference *: your own reference. It must be unique for the employee; uploading the same reference again is refused.'],
            ['Original Amount *: the amount first lent.   Disbursed On *: the date it was paid out, as YYYY-MM-DD.'],
            ['Interest Method: Straight line, Declining or EMI. Leave empty to use the loan type\'s method.'],
            ['Interest Rate % (yearly): leave empty to use the loan type\'s default. 0 = no interest.'],
            ['Original Term (months) *: the term the loan started with.'],
            ['Outstanding Principal *: what is still owed (capital only) today.'],
            ['Remaining Installments *: how many installments are left.'],
            ['Next Deduction Month *: the month of the next installment, as YYYY-MM (for example 2026-11).'],
            ['Current Installment (optional): if given, it is compared with the installment the system calculates and you are warned about a difference.'],
            [''],
            [['How the schedule is built', XlsxWriter::STYLE_BOLD]],
            ['The remaining installments are calculated from the outstanding principal, the rate and the method. Straight line keeps the flat interest on the original amount.'],
            ['Columns must keep their titles. Do not add or rename columns. Up to '.config('loans.import_max_rows').' rows.'],
        ];

        $lists = [[['Code', XlsxWriter::STYLE_HEADER], ['Name', XlsxWriter::STYLE_HEADER], ['Interest method', XlsxWriter::STYLE_HEADER], ['Default rate %', XlsxWriter::STYLE_HEADER]]];
        foreach ($types as $t) {
            $lists[] = [$t->code, $t->name, LoanSchedule::METHODS[$t->interest_method] ?? $t->interest_method, (string) Decimal::plain((string) $t->default_rate)];
        }

        return (new XlsxWriter)
            ->addSheet('Instructions', $help, ['widths' => [1 => 130]])
            ->addSheet('Loans', [$header], [
                'widths' => [1 => 14, 2 => 14, 3 => 18, 4 => 16, 5 => 26, 6 => 18, 7 => 22, 8 => 22, 9 => 22, 10 => 24, 11 => 30, 12 => 26, 13 => 30],
                'textColumns' => [1, 2, 3, 5, 6, 11, 13], 'freezeHeader' => true,
                'validations' => [
                    ['sqref' => 'B2:B1001', 'formula' => 'Lists!$A$2:$A$200', 'message' => 'Choose a loan type code from the list.'],
                    ['sqref' => 'F2:F1001', 'list' => 'Straight line,Declining,EMI', 'message' => 'Choose Straight line, Declining or EMI.'],
                ],
            ])
            ->addSheet('Lists', $lists, ['widths' => [1 => 16, 2 => 32, 3 => 26, 4 => 16]])
            ->toBinary();
    }

    /* ------------------------------ staging ------------------------------ */

    public function stage(string $path, string $fileName, User $by): LoanImportBatch
    {
        try {
            $names = XlsxReader::sheetNames($path);
            $sheet = collect($names)->first(fn ($n) => strcasecmp($n, 'Loans') === 0) ?? $names[0] ?? null;
            $rows = XlsxReader::read($path, $sheet);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'The file has no rows.']);
        }

        $headerRow = array_key_first($rows);
        $map = [];
        foreach ($rows[$headerRow] as $col => $title) {
            $norm = preg_replace('/[^a-z0-9]/', '', mb_strtolower($title));
            foreach (self::COLUMNS as $field => [, $prefix]) {
                if (! isset($map[$field]) && str_starts_with($norm, $prefix)) {
                    $map[$field] = $col;
                }
            }
        }
        $missing = array_filter(self::REQUIRED, fn ($f) => ! isset($map[$f]));
        if ($missing) {
            throw ValidationException::withMessages(['file' => 'These columns are missing: '.implode(', ', array_map(fn ($f) => self::COLUMNS[$f][0], $missing)).'. Download the template again.']);
        }

        $data = array_slice($rows, 1, null, true);
        $limit = (int) config('loans.import_max_rows', 2000);
        if (count($data) > $limit) {
            throw ValidationException::withMessages(['file' => "The file has more than {$limit} rows. Split it into smaller files."]);
        }
        if (! $data) {
            throw ValidationException::withMessages(['file' => 'The file has a header but no loans.']);
        }

        $raw = [];
        foreach ($data as $rowNo => $cells) {
            $r = [];
            foreach ($map as $field => $col) {
                $r[$field] = trim((string) ($cells[$col] ?? ''));
            }
            $raw[$rowNo] = $r;
        }

        $ctx = $this->context($raw);
        $seen = [];

        return DB::transaction(function () use ($raw, $ctx, $fileName, $by, &$seen) {
            $batch = LoanImportBatch::create(['file_name' => mb_substr($fileName, 0, 255), 'status' => 'staged', 'uploaded_by' => $by->id, 'uploaded_by_name' => $by->name]);
            $valid = $errors = 0;
            foreach ($raw as $rowNo => $r) {
                [$data, $errs, $warns] = $this->validateRow($r, $ctx, $seen);
                $status = $errs ? 'error' : 'valid';
                $status === 'valid' ? $valid++ : $errors++;
                LoanImportRow::create(['loan_import_batch_id' => $batch->id, 'row_no' => $rowNo, 'data' => $data, 'errors' => $errs ?: null, 'warnings' => $warns ?: null, 'status' => $status]);
            }
            $batch->update(['total_rows' => count($raw), 'valid_rows' => $valid, 'error_rows' => $errors]);
            Audit::log('loan.import_staged', $batch, "Loan file {$batch->file_name} uploaded: {$valid} valid, {$errors} with errors", context: ['batch_id' => $batch->id]);

            return $batch;
        });
    }

    /** Lookups for the whole file at once. */
    private function context(array $raw): array
    {
        $numbers = collect($raw)->pluck('employee_no')->filter()->map(fn ($n) => mb_strtolower($n))->unique()->all();
        // Excel drops leading zeros from a number typed into a General cell (00123 becomes 123): those are matched too, if only one employee fits
        $digits = collect($numbers)->filter(fn ($n) => ctype_digit($n))->map(fn ($n) => ltrim($n, '0'))->filter()->unique()->all();

        $found = Employee::query()->where(fn ($q) => $q->whereIn(DB::raw('lower(employee_no)'), $numbers)
            ->when($digits, fn ($q) => $q->orWhereIn(DB::raw("trim(leading '0' from employee_no)"), $digits)))->get();
        $byDigits = $found->filter(fn ($e) => ctype_digit($e->employee_no))->groupBy(fn ($e) => ltrim($e->employee_no, '0'))->filter(fn ($g) => $g->count() === 1)->map->first();

        $existing = Loan::withoutGlobalScopes()->whereIn('employee_id', $found->pluck('id'))->where('status', '!=', 'cancelled')->get(['employee_id', 'reference'])
            ->mapWithKeys(fn ($l) => [$l->employee_id.'|'.mb_strtolower($l->reference) => true])->all();

        return ['employees' => $found->keyBy(fn ($e) => mb_strtolower($e->employee_no)), 'byDigits' => $byDigits, 'types' => LoanType::all()->keyBy(fn ($t) => mb_strtoupper($t->code)), 'existing' => $existing];
    }

    /** @return array{0: array, 1: string[], 2: string[]} the cleaned data, errors, warnings. Messages never contain amounts. */
    private function validateRow(array $r, array $ctx, array &$seen): array
    {
        $errors = $warnings = [];
        [$dp, $mode] = LoanService::rounding();

        foreach (self::REQUIRED as $f) {
            if (($r[$f] ?? '') === '') {
                $errors[] = self::COLUMNS[$f][0].' is empty.';
            }
        }

        $employee = ($r['employee_no'] ?? '') !== '' ? $ctx['employees']->get(mb_strtolower($r['employee_no'])) : null;
        if (! $employee && ctype_digit($r['employee_no'] ?? '') && ltrim($r['employee_no'], '0') !== '') {
            $employee = $ctx['byDigits']->get(ltrim($r['employee_no'], '0'));
            if ($employee) {
                $warnings[] = "Employee No was matched to {$employee->employee_no} (Excel had removed the leading zeros). Format the column as Text.";
            }
        }
        if (($r['employee_no'] ?? '') !== '' && ! $employee) {
            $errors[] = 'No employee has this number, or the employee is not in your schemes.';
        }
        if ($employee && (! $employee->is_active || ! $employee->is_payroll_active)) {
            $warnings[] = 'The employee is inactive or excluded from payroll.';
        }

        $type = ($r['loan_type'] ?? '') !== '' ? ($ctx['types'][mb_strtoupper($r['loan_type'])] ?? null) : null;
        if (($r['loan_type'] ?? '') !== '' && ! $type) {
            $errors[] = 'Unknown loan type code.';
        }
        if ($type && ! $type->is_active) {
            $errors[] = 'This loan type is inactive.';
        }

        $ref = $r['reference'] ?? '';
        if ($ref !== '' && mb_strlen($ref) > 40) {
            $errors[] = 'The loan reference is longer than 40 characters.';
        }
        if ($employee && $ref !== '') {
            $key = $employee->id.'|'.mb_strtolower($ref);
            if (isset($ctx['existing'][$key])) {
                $errors[] = 'This employee already has a loan with that reference.';
            } elseif (isset($seen[$key])) {
                $errors[] = 'The same employee and reference appear earlier in this file.';
            }
            $seen[$key] = true;
        }

        $original = $this->amount($r['original_amount'] ?? '', 'Original Amount', $errors);
        $outstanding = $this->amount($r['outstanding'] ?? '', 'Outstanding Principal', $errors);
        if ($original !== null && Decimal::cmp($original, '0') <= 0) {
            $errors[] = 'Original Amount must be more than 0.';
        }
        if ($original !== null && $outstanding !== null) {
            if (Decimal::cmp($outstanding, '0') <= 0) {
                $errors[] = 'Outstanding Principal is 0: a settled loan is not imported.';
            } elseif (Decimal::cmp($outstanding, $original) > 0) {
                $errors[] = 'Outstanding Principal is more than the Original Amount.';
            }
        }

        $disbursed = $this->date($r['disbursed_on'] ?? '', 'Disbursed On', $errors);
        $next = $this->month($r['next_month'] ?? '', 'Next Deduction Month', $errors);
        $term = $this->whole($r['term_months'] ?? '', 'Original Term', 600, $errors);
        $remaining = $this->whole($r['remaining'] ?? '', 'Remaining Installments', 600, $errors);
        if ($term !== null && $remaining !== null && $remaining > $term) {
            $warnings[] = 'Remaining Installments is more than the Original Term.';
        }
        if ($next && $disbursed && $next->lt($disbursed->copy()->startOfMonth())) {
            $errors[] = 'Next Deduction Month is before the loan was paid out.';
        }
        if ($next && $next->lt(today()->startOfMonth())) {
            $warnings[] = 'Next Deduction Month is in the past, so those installments will show as overdue.';
        }

        $method = $type?->interest_method;
        if (($r['interest_method'] ?? '') !== '') {
            $m = preg_replace('/[^a-z]/', '', mb_strtolower($r['interest_method']));
            $method = match (true) {
                in_array($m, ['straightline', 'flat', 'straight_line'], true) => 'straight_line',
                in_array($m, ['declining', 'decliningbalance', 'reducing', 'reducingbalance', 'decline'], true) => 'declining',
                $m === 'emi' => 'emi',
                default => null,
            };
            if ($method === null) {
                $errors[] = 'Interest Method must be Straight line, Declining or EMI.';
            }
        }

        $rate = $type ? Decimal::plain((string) $type->default_rate) : null;
        if (($r['interest_rate'] ?? '') !== '') {
            $s = str_replace([',', ' ', '%'], '', $r['interest_rate']);
            if (! preg_match('/^\d{1,3}(\.\d{1,4})?$/', $s) || Decimal::cmp($s, '100') > 0) {
                $errors[] = 'Interest Rate must be from 0 to 100.';
                $rate = null;
            } else {
                $rate = Decimal::make($s);
            }
        }

        if ($type && $original !== null && $type->max_amount !== null && Decimal::cmp($original, (string) $type->max_amount) > 0) {
            $warnings[] = 'The original amount is above the usual maximum of this loan type.';
        }
        if ($type && $term !== null && $type->max_term_months !== null && $term > $type->max_term_months) {
            $warnings[] = 'The original term is longer than the usual maximum of this loan type.';
        }

        // Compare the installment the sheet gives with the one the system would calculate
        $given = ($r['installment'] ?? '') !== '' ? $this->amount($r['installment'], 'Current Installment', $errors) : null;
        if (! $errors && $given !== null && $outstanding && $remaining && $method && $rate !== null) {
            $calc = LoanSchedule::build($method, $outstanding, $rate, $remaining, $dp, $mode, $method === 'straight_line' ? $original : null)[0]['total'];
            $tolerance = max(1.0, 0.01 * (float) $calc);
            if (abs((float) Decimal::sub($calc, $given)) > $tolerance) {
                $warnings[] = 'The Current Installment differs from the installment the system calculates from the balance, rate and method. Check the rate and method.';
            }
        }

        $data = [
            'employee_id' => $employee?->id, 'employee_no' => $r['employee_no'] ?? '', 'employee_name' => $employee ? ($employee->display_name ?? $employee->full_name) : null,
            'loan_type_id' => $type?->id, 'loan_type' => $type?->code, 'reference' => $ref, 'original_amount' => $original, 'disbursed_on' => $disbursed?->toDateString(),
            'method' => $method, 'rate' => $rate, 'term' => $term, 'outstanding' => $outstanding, 'remaining' => $remaining, 'next_month' => $next?->toDateString(),
            'installment' => $given, 'notes' => ($r['notes'] ?? '') !== '' ? mb_substr($r['notes'], 0, 255) : null,
        ];

        return [$data, array_values(array_unique($errors)), $warnings];
    }

    /* ------------------------------ apply / reverse / discard ------------------------------ */

    public function apply(LoanImportBatch $batch, User $by): int
    {
        return DB::transaction(function () use ($batch, $by) {
            $batch = LoanImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'staged') {
                throw ValidationException::withMessages(['batch' => 'This upload was already applied or discarded.']);
            }
            $rows = $batch->rows()->where('status', 'valid')->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['batch' => 'There are no valid rows to create. Fix the errors and upload the file again.']);
            }

            $types = LoanType::all()->keyBy('id');
            foreach ($rows as $row) {
                $d = $row->data;
                $employee = Employee::query()->findOrFail($d['employee_id']);
                $loan = app(LoanService::class)->createOpening($employee, $types[$d['loan_type_id']], $d, $by, $batch->id);
                $row->update(['status' => 'created', 'loan_id' => $loan->id]);
            }

            $batch->update(['status' => 'applied', 'loans_created' => $rows->count(), 'applied_at' => now()]);
            Audit::log('loan.import_applied', $batch, "Loan import #{$batch->id}: {$rows->count()} loans created", context: ['batch_id' => $batch->id, 'skipped_error_rows' => $batch->error_rows]);

            return $rows->count();
        });
    }

    public function reverse(LoanImportBatch $batch, string $reason, User $by): int
    {
        return DB::transaction(function () use ($batch, $reason, $by) {
            $batch = LoanImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'applied') {
                throw ValidationException::withMessages(['reason' => 'Only an applied upload can be reversed.']);
            }
            $loans = Loan::withoutGlobalScopes()->where('import_batch_id', $batch->id)->get();
            $used = $loans->filter(fn ($l) => $l->status !== 'active' || $l->installments()->where('status', 'deducted')->exists()
                || $l->ledger()->withoutGlobalScopes()->whereNotIn('entry_type', ['opening'])->exists());
            if ($used->isNotEmpty()) {
                throw ValidationException::withMessages(['reason' => $used->count().' of these loans already have deductions, a rate change or a settlement ('.$used->take(5)->pluck('reference')->implode(', ').'), so the upload cannot be reversed.']);
            }

            foreach ($loans as $l) {
                $l->installments()->whereIn('status', ['pending', 'superseded'])->get()->each(fn ($i) => $i->update(['status' => 'cancelled']));
                $l->forceFill(['status' => 'cancelled', 'closed_reason' => mb_substr("Import #{$batch->id} reversed: ".trim($reason), 0, 500)])->save();
            }
            $batch->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_reason' => mb_substr(trim($reason), 0, 500)]);
            Audit::log('loan.import_reversed', $batch, "Loan import #{$batch->id} reversed ({$loans->count()} loans cancelled)", context: ['reason' => $reason]);

            return $loans->count();
        });
    }

    public function discard(LoanImportBatch $batch): void
    {
        if ($batch->status !== 'staged') {
            throw ValidationException::withMessages(['batch' => 'Only a staged upload can be discarded.']);
        }
        $batch->update(['status' => 'discarded']);
        Audit::log('loan.import_discarded', $batch, "Loan import #{$batch->id} discarded");
    }

    /* ------------------------------ parsing ------------------------------ */

    private function amount(string $v, string $label, array &$errors): ?string
    {
        if ($v === '') {
            return null;
        }
        $s = str_replace([',', ' '], '', $v);
        if (! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $s)) {
            $errors[] = "{$label} must be a number with up to 2 decimals.";

            return null;
        }

        return Decimal::make($s);
    }

    private function whole(string $v, string $label, int $max, array &$errors): ?int
    {
        if ($v === '') {
            return null;
        }
        if (! preg_match('/^\d+(\.0+)?$/', $v) || (int) $v < 1 || (int) $v > $max) {
            $errors[] = "{$label} must be a whole number from 1 to {$max}.";

            return null;
        }

        return (int) $v;
    }

    private function date(string $v, string $label, array &$errors): ?Carbon
    {
        if ($v === '') {
            return null;
        }
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1990) {
            $errors[] = "{$label} must be a date written as YYYY-MM-DD.";

            return null;
        }

        return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
    }

    private function month(string $v, string $label, array &$errors): ?Carbon
    {
        if ($v === '') {
            return null;
        }
        if (! preg_match('/^(\d{4})-(\d{2})(-\d{2})?$/', $v, $m) || (int) $m[2] < 1 || (int) $m[2] > 12 || (int) $m[1] < 1990) {
            $errors[] = "{$label} must be written as YYYY-MM, for example 2026-11.";

            return null;
        }

        return Carbon::create((int) $m[1], (int) $m[2], 1)->startOfDay();
    }
}
