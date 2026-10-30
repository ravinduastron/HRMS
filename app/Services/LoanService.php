<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanLedgerEntry;
use App\Models\LoanRateChange;
use App\Models\LoanType;
use App\Models\User;
use App\Payroll\Decimal;
use App\Payroll\Loans\LoanSchedule;
use App\Payroll\Loans\LoanSettlement;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * All loan rules live here (a future approval engine hooks into create()). Balances come from the ledger, never from a stored figure.
 * Installments are never edited: a rate change supersedes the unpaid ones and adds new ones. Payroll calls recordDeduction() when a run locks.
 */
class LoanService
{
    /** @return array{0: int, 1: string} */
    public static function rounding(): array
    {
        return [(int) config('loans.rounding_dp', 2), (string) config('loans.rounding_mode', 'half_up')];
    }

    /* ------------------------------ reading ------------------------------ */

    public function outstanding(Loan $loan): string
    {
        return $this->outstandingFor(collect([$loan]))[$loan->id] ?? '0';
    }

    /** @return array<int, string> loan id => outstanding principal (one query) */
    public function outstandingFor(Collection $loans): array
    {
        $sums = [];
        foreach ($loans as $l) {
            $sums[$l->id] = '0';
        }
        LoanLedgerEntry::withoutGlobalScopes()->whereIn('loan_id', $loans->pluck('id'))->get(['loan_id', 'principal'])
            ->each(function ($e) use (&$sums) {
                $sums[$e->loan_id] = Decimal::add($sums[$e->loan_id], (string) $e->principal);
            });

        return array_map(fn ($v) => Decimal::round($v, ...self::rounding()), $sums);
    }

    public function pending(Loan $loan): Collection
    {
        return $loan->installments()->where('status', 'pending')->get();
    }

    public function remainingInterest(Collection $installments): string
    {
        return Decimal::round($installments->reduce(fn ($c, $i) => Decimal::add($c, (string) $i->interest_amount), '0'), ...self::rounding());
    }

    /** The first-of-month dates of the months a schedule covers, from $first. */
    private function months(Carbon $first, int $n): array
    {
        return collect(range(0, $n - 1))->map(fn ($k) => $first->copy()->startOfMonth()->addMonthsNoOverflow($k))->all();
    }

    /* ------------------------------ creating ------------------------------ */

    /** A new loan paid out now: the schedule runs from the first deduction month. */
    public function create(Employee $employee, LoanType $type, array $d, User $by): Loan
    {
        [$dp, $mode] = self::rounding();
        $amount = $this->money($d['amount'] ?? null, 'amount');
        $rate = $this->rate($d['rate'] ?? null, (string) $type->default_rate);
        $term = (int) ($d['term'] ?? 0);
        $disbursed = Carbon::parse($d['disbursed_on'])->startOfDay();
        $first = Carbon::parse($d['first_due_month'])->startOfMonth();

        if (! $type->is_active) {
            throw ValidationException::withMessages(['loan_type_id' => 'This loan type is inactive.']);
        }
        if (Decimal::cmp($amount, '0') <= 0) {
            throw ValidationException::withMessages(['amount' => 'The loan amount must be more than 0.']);
        }
        if ($type->max_amount !== null && Decimal::cmp($amount, (string) $type->max_amount) > 0) {
            throw ValidationException::withMessages(['amount' => 'The amount is above the maximum for this loan type.']);
        }
        if ($term < 1 || $term > 600 || ($type->max_term_months !== null && $term > $type->max_term_months)) {
            throw ValidationException::withMessages(['term' => 'The term must be from 1 month up to '.($type->max_term_months ?? 600).' months for this loan type.']);
        }
        if ($first->lt($disbursed->copy()->startOfMonth())) {
            throw ValidationException::withMessages(['first_due_month' => 'The first deduction cannot be before the month the loan is paid out.']);
        }

        $rows = LoanSchedule::build($type->interest_method, $amount, $rate, $term, $dp, $mode);

        return DB::transaction(function () use ($employee, $type, $d, $by, $amount, $rate, $term, $disbursed, $first, $rows) {
            Employee::withoutGlobalScopes()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $reference = $this->reference($employee, $d['reference'] ?? null);

            $loan = new Loan;
            $loan->forceFill([
                'employee_id' => $employee->id, 'loan_type_id' => $type->id, 'reference' => $reference,
                'interest_method' => $type->interest_method, 'settlement_interest_rule' => $type->settlement_interest_rule,
                'principal' => $amount, 'current_rate' => $rate, 'term_months' => $term, 'disbursed_on' => $disbursed->toDateString(), 'first_due_month' => $first->toDateString(),
                'status' => 'active', 'source' => 'manual', 'notes' => $this->note($d['notes'] ?? null),
                'approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), // approval engine hooks in here later
                'created_by' => $by->id, 'created_by_name' => $by->name,
            ])->save();
            if (trim((string) ($d['reference'] ?? '')) === '') { // no reference given: use LN-000123
                $loan->forceFill(['reference' => 'LN-'.str_pad((string) $loan->id, 6, '0', STR_PAD_LEFT)])->save();
            }

            $this->insertSchedule($loan, $rows, $first, 1, 1);
            $this->ledger($loan, 'disbursement', $disbursed, $amount, '0', null, 'Loan paid out', $by);

            Audit::log('loan.created', $loan, "Loan {$loan->reference} created ({$type->name})", context: [
                'employee_id' => $employee->id, 'type' => $type->code, 'method' => $type->interest_method, 'rate' => $rate, 'term' => $term, 'first_due_month' => $first->format('Y-m'),
            ]);

            return $loan;
        });
    }

    /**
     * A loan that already exists (Excel import): it starts from the outstanding principal and the remaining installments.
     * $d: reference, original_amount, disbursed_on, method, rate, term, outstanding, remaining, next_month, notes
     */
    public function createOpening(Employee $employee, LoanType $type, array $d, User $by, ?int $batchId = null): Loan
    {
        [$dp, $mode] = self::rounding();
        $original = $this->money($d['original_amount'] ?? null, 'original_amount');
        $outstanding = $this->money($d['outstanding'] ?? null, 'outstanding');
        $rate = $this->rate($d['rate'] ?? null, (string) $type->default_rate);
        $remaining = (int) $d['remaining'];
        $term = (int) $d['term'];
        $method = $d['method'] ?? $type->interest_method;
        $next = Carbon::parse($d['next_month'])->startOfMonth();
        $disbursed = Carbon::parse($d['disbursed_on'])->startOfDay();

        if (Decimal::cmp($outstanding, '0') <= 0 || $remaining < 1) {
            throw ValidationException::withMessages(['outstanding' => 'A loan to import needs an outstanding balance and remaining installments.']);
        }

        // Straight line: the flat interest stays what the loan terms say (charged on the original amount); the others re-price the balance
        $rows = LoanSchedule::build($method, $outstanding, $rate, $remaining, $dp, $mode, $method === 'straight_line' ? $original : null);

        return DB::transaction(function () use ($employee, $type, $d, $by, $batchId, $original, $outstanding, $rate, $remaining, $term, $method, $next, $disbursed, $rows) {
            Employee::withoutGlobalScopes()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $reference = $this->reference($employee, $d['reference'] ?? null);

            $loan = new Loan;
            $loan->forceFill([
                'employee_id' => $employee->id, 'loan_type_id' => $type->id, 'reference' => $reference,
                'interest_method' => $method, 'settlement_interest_rule' => $type->settlement_interest_rule,
                'principal' => $original, 'current_rate' => $rate, 'term_months' => $term, 'disbursed_on' => $disbursed->toDateString(), 'first_due_month' => $next->toDateString(),
                'status' => 'active', 'source' => 'import', 'import_batch_id' => $batchId, 'notes' => $this->note($d['notes'] ?? null),
                'approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(),
                'created_by' => $by->id, 'created_by_name' => $by->name,
            ])->save();

            $this->insertSchedule($loan, $rows, $next, 1, max(1, $term - $remaining + 1));
            $this->ledger($loan, 'opening', now(), $outstanding, '0', null, 'Opening balance'.($batchId ? " (import #{$batchId})" : ''), $by);

            Audit::log('loan.imported', $loan, "Existing loan {$loan->reference} loaded ({$type->name})", context: [
                'employee_id' => $employee->id, 'type' => $type->code, 'method' => $method, 'rate' => $rate, 'remaining_installments' => $remaining, 'import_batch' => $batchId,
            ]);

            return $loan;
        });
    }

    /** Preview of a new loan's schedule (nothing saved). */
    public function preview(LoanType $type, string $amount, ?string $rate, int $term): array
    {
        [$dp, $mode] = self::rounding();
        $rows = LoanSchedule::build($type->interest_method, $this->money($amount, 'amount'), $this->rate($rate, (string) $type->default_rate), $term, $dp, $mode);

        return ['rows' => $rows, 'totals' => LoanSchedule::totals($rows), 'first' => $rows[0], 'last' => $rows[count($rows) - 1]];
    }

    /* ------------------------------ interest-rate change ------------------------------ */

    /**
     * What changing the rate from a month would do (nothing saved): the unpaid installments from that month are re-priced on the
     * outstanding balance with the same number of installments left.
     *
     * @return array{month: Carbon, rate: string, outstanding: string, count: int, affected: Collection, new: array, old_installment: string, new_installment: string, old_interest: string, new_interest: string, old_total: string, new_total: string}
     */
    public function rateChangePlan(Loan $loan, Carbon $effectiveMonth, string $newRate): array
    {
        [$dp, $mode] = self::rounding();
        $month = $effectiveMonth->copy()->startOfMonth();
        $rate = $this->rate($newRate, null);

        if ($loan->status !== 'active') {
            throw ValidationException::withMessages(['new_rate' => 'Only an active loan can be re-priced.']);
        }
        if (Decimal::cmp($rate, Decimal::plain((string) $loan->current_rate)) === 0) {
            throw ValidationException::withMessages(['new_rate' => 'The new rate is the same as the current rate.']);
        }
        if ($loan->installments()->where('status', 'deducted')->whereDate('due_month', '>=', $month->toDateString())->exists()) {
            throw ValidationException::withMessages(['effective_month' => 'Installments from that month were already deducted. Choose a later month.']);
        }

        $affected = $this->pending($loan)->filter(fn ($i) => $i->due_month->gte($month))->values();
        if ($affected->isEmpty()) {
            throw ValidationException::withMessages(['effective_month' => 'There are no unpaid installments from that month.']);
        }

        $outstanding = $affected->reduce(fn ($c, $i) => Decimal::add($c, (string) $i->principal_amount), '0');
        $new = LoanSchedule::build($loan->interest_method, $outstanding, $rate, $affected->count(), $dp, $mode);
        $newTotals = LoanSchedule::totals($new);
        $oldInterest = $this->remainingInterest($affected);

        return [
            'month' => $month, 'rate' => $rate, 'outstanding' => Decimal::round($outstanding, $dp, $mode), 'count' => $affected->count(), 'affected' => $affected, 'new' => $new,
            'old_installment' => Decimal::round($affected[0]->total(), $dp, $mode), 'new_installment' => $new[0]['total'],
            'old_interest' => $oldInterest, 'new_interest' => $newTotals['interest'],
            'old_total' => Decimal::round(Decimal::add($outstanding, $oldInterest), $dp, $mode), 'new_total' => $newTotals['total'],
        ];
    }

    public function changeRate(Loan $loan, Carbon $effectiveMonth, string $newRate, string $note, User $by): LoanRateChange
    {
        return DB::transaction(function () use ($loan, $effectiveMonth, $newRate, $note, $by) {
            $loan = Loan::withoutGlobalScopes()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $plan = $this->rateChangePlan($loan, $effectiveMonth, $newRate);
            $previous = Decimal::plain((string) $loan->current_rate);
            $version = ((int) $loan->installments()->reorder()->max('schedule_version')) + 1;

            $plan['affected']->each(fn ($i) => $i->update(['status' => 'superseded']));
            foreach ($plan['new'] as $k => $r) {
                $old = $plan['affected'][$k];
                $this->newInstallment($loan, $version, $old->seq, $old->due_month, $r['principal'], $r['interest']);
            }

            $change = new LoanRateChange;
            $change->forceFill([
                'loan_id' => $loan->id, 'effective_month' => $plan['month']->toDateString(), 'previous_rate' => $previous, 'new_rate' => $plan['rate'],
                'outstanding_principal' => $plan['outstanding'], 'remaining_installments' => $plan['count'],
                'old_installment' => $plan['old_installment'], 'new_installment' => $plan['new_installment'],
                'old_remaining_interest' => $plan['old_interest'], 'new_remaining_interest' => $plan['new_interest'],
                'schedule_version' => $version, 'change_note' => mb_substr(trim($note), 0, 500), 'created_by' => $by->id, 'created_by_name' => $by->name,
            ])->save();

            $loan->forceFill(['current_rate' => $plan['rate']])->save();
            $this->ledger($loan, 'rate_change', now(), '0', '0', null, "Rate {$previous}% to {$plan['rate']}% from ".$plan['month']->format('M Y'), $by);

            Audit::log('loan.rate_changed', $loan, "Interest rate of {$loan->reference} changed from {$previous}% to {$plan['rate']}% (from ".$plan['month']->format('M Y').')',
                old: ['rate' => $previous], new: ['rate' => $plan['rate']], context: ['effective_month' => $plan['month']->format('Y-m'), 'reason' => $change->change_note, 'schedule_version' => $version]);

            return $change;
        });
    }

    /* ------------------------------ early settlement ------------------------------ */

    public function quote(Loan $loan, Carbon $date, bool $ignoreInterest = false): array
    {
        [$dp, $mode] = self::rounding();
        $pending = $this->pending($loan);
        if ($pending->isEmpty()) {
            throw ValidationException::withMessages(['date' => 'This loan has nothing left to settle.']);
        }
        if ($date->lt($loan->disbursed_on)) {
            throw ValidationException::withMessages(['date' => 'The settlement date cannot be before the loan was paid out.']);
        }

        $lastDeducted = $loan->installments()->where('status', 'deducted')->reorder('due_month', 'desc')->first();
        $periodStart = $lastDeducted ? $lastDeducted->due_month->copy()->endOfMonth()->toDateString() : $loan->disbursed_on->toDateString();
        $rows = $pending->map(fn ($i) => ['due' => $i->due_month->copy()->endOfMonth()->toDateString(), 'principal' => (string) $i->principal_amount, 'interest' => (string) $i->interest_amount])->all();

        return LoanSettlement::quote($rows, $loan->settlement_interest_rule, $date->toDateString(), $periodStart, $ignoreInterest, $dp, $mode);
    }

    /** Settles the loan: the money is received in cash and recorded here. Ignoring the interest needs a reason (the controller checks the permission). */
    public function settle(Loan $loan, Carbon $date, bool $ignoreInterest, ?string $reason, Carbon $receivedOn, ?string $receipt, User $by): array
    {
        if ($ignoreInterest && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for ignoring the interest.']);
        }

        return DB::transaction(function () use ($loan, $date, $ignoreInterest, $reason, $receivedOn, $receipt, $by) {
            $loan = Loan::withoutGlobalScopes()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if ($loan->status !== 'active') {
                throw ValidationException::withMessages(['date' => 'This loan is not active.']);
            }
            $q = $this->quote($loan, $date, $ignoreInterest);
            $pending = $this->pending($loan);

            $cash = 'Cash received on '.$receivedOn->format('d M Y').($receipt ? " (receipt {$receipt})" : '');
            $this->ledger($loan, 'settlement', $date, Decimal::neg($q['principal']), $q['interest'], null, $cash, $by);
            if (Decimal::cmp($q['waived'], '0') > 0) {
                $this->ledger($loan, 'interest_waived', $date, '0', $q['waived'], null, 'Interest ignored: '.trim((string) $reason), $by);
            }
            $pending->each(fn ($i) => $i->update(['status' => 'settled']));
            $loan->forceFill(['status' => 'settled', 'settled_on' => $date->toDateString(), 'closed_reason' => mb_substr(trim((string) $reason) ?: 'Settled early', 0, 500)])->save();

            Audit::log('loan.settled', $loan, "Loan {$loan->reference} settled early".($ignoreInterest ? ' (interest ignored)' : ''), context: [
                'date' => $date->toDateString(), 'principal' => $q['principal'], 'interest_charged' => $q['interest'], 'interest_ignored' => $q['waived'],
                'rule' => $q['rule'], 'received_on' => $receivedOn->toDateString(), 'receipt' => $receipt, 'reason' => $reason,
            ]);

            return $q;
        });
    }

    public function cancel(Loan $loan, string $reason, User $by): void
    {
        DB::transaction(function () use ($loan, $reason, $by) {
            $loan = Loan::withoutGlobalScopes()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if ($loan->status !== 'active') {
                throw ValidationException::withMessages(['reason' => 'Only an active loan can be cancelled.']);
            }
            $moved = LoanLedgerEntry::withoutGlobalScopes()->where('loan_id', $loan->id)->whereNotIn('entry_type', ['disbursement', 'opening', 'rate_change'])->exists();
            if ($moved) {
                throw ValidationException::withMessages(['reason' => 'This loan already has deductions or a settlement, so it cannot be cancelled. Settle it instead.']);
            }
            $loan->installments()->whereIn('status', ['pending', 'superseded'])->get()->each(fn ($i) => $i->update(['status' => 'cancelled']));
            $loan->forceFill(['status' => 'cancelled', 'closed_reason' => mb_substr(trim($reason), 0, 500)])->save();

            Audit::log('loan.cancelled', $loan, "Loan {$loan->reference} cancelled", context: ['reason' => $reason]);
        });
    }

    /* ------------------------------ payroll ------------------------------ */

    /**
     * What payroll deducts for a month, per employee, capital and interest apart. Includes unpaid installments of EARLIER months (flagged
     * overdue) so a run can stop and ask. Lines carry the pay component of each part, for the payslip and the paysheet.
     *
     * @return array<int, array{capital: string, interest: string, total: string, overdue: bool, lines: array}> keyed by employee id
     */
    public function dueForPeriod(Carbon $month, ?array $employeeIds = null, bool $includeOverdue = true): array
    {
        [$dp, $mode] = self::rounding();
        $first = $month->copy()->startOfMonth();

        $installments = LoanInstallment::with(['loan.type'])->where('status', 'pending')
            ->whereDate('due_month', $includeOverdue ? '<=' : '=', $first->toDateString())
            ->whereHas('loan', fn ($l) => $l->where('status', 'active')->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds)))
            ->get();

        $out = [];
        foreach ($installments as $i) {
            $e = $i->loan->employee_id;
            $out[$e] ??= ['capital' => '0', 'interest' => '0', 'total' => '0', 'overdue' => false, 'lines' => []];
            $overdue = $i->due_month->lt($first);
            $out[$e]['capital'] = Decimal::add($out[$e]['capital'], (string) $i->principal_amount);
            $out[$e]['interest'] = Decimal::add($out[$e]['interest'], (string) $i->interest_amount);
            $out[$e]['overdue'] = $out[$e]['overdue'] || $overdue;
            $out[$e]['lines'][] = [
                'installment_id' => $i->id, 'loan_id' => $i->loan_id, 'reference' => $i->loan->reference, 'type' => $i->loan->type->name, 'seq' => $i->seq,
                'due_month' => $i->due_month->toDateString(), 'overdue' => $overdue,
                'capital' => Decimal::round((string) $i->principal_amount, $dp, $mode), 'interest' => Decimal::round((string) $i->interest_amount, $dp, $mode),
                'capital_component_id' => $i->loan->type->capital_component_id, 'interest_component_id' => $i->loan->type->interest_component_id,
            ];
        }
        foreach ($out as $e => $row) {
            $out[$e]['capital'] = Decimal::round($row['capital'], $dp, $mode);
            $out[$e]['interest'] = Decimal::round($row['interest'], $dp, $mode);
            $out[$e]['total'] = Decimal::round(Decimal::add($row['capital'], $row['interest']), $dp, $mode);
        }

        return $out;
    }

    /** Amount per pay component for one employee's lines: capital and interest go to their own components. @return array<int, string> */
    public function componentAmounts(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            foreach ([['capital_component_id', 'capital'], ['interest_component_id', 'interest']] as [$comp, $part]) {
                if ($l[$comp]) {
                    $out[$l[$comp]] = Decimal::add($out[$l[$comp]] ?? '0', $l[$part]);
                }
            }
        }

        return array_map(fn ($v) => Decimal::round($v, ...self::rounding()), $out);
    }

    /** Marks one installment as deducted (call inside a transaction). A pay run calls this when it locks. */
    public function recordDeduction(LoanInstallment $installment, Carbon $on, ?User $by = null): void
    {
        $loan = Loan::withoutGlobalScopes()->whereKey($installment->loan_id)->lockForUpdate()->firstOrFail();
        $installment = LoanInstallment::withoutGlobalScopes()->whereKey($installment->id)->lockForUpdate()->firstOrFail();
        if ($installment->status !== 'pending' || $loan->status !== 'active') {
            throw ValidationException::withMessages(['month' => "Installment {$installment->seq} of {$loan->reference} is not due for deduction."]);
        }

        $entry = $this->ledger($loan, 'installment', $on, Decimal::neg((string) $installment->principal_amount), (string) $installment->interest_amount, $installment->id,
            'Installment '.$installment->seq.' for '.$installment->due_month->format('M Y'), $by);
        $installment->update(['status' => 'deducted', 'deducted_on' => $on->toDateString(), 'ledger_entry_id' => $entry->id]);

        if (! $loan->installments()->where('status', 'pending')->exists()) {
            $loan->forceFill(['status' => 'settled', 'settled_on' => $on->toDateString(), 'closed_reason' => 'Fully repaid'])->save();
        }
    }

    /** Records every pending installment of a month as deducted (until payroll does it). @return array{count: int, capital: string, interest: string} */
    public function recordMonth(Carbon $month, User $by, ?int $schemeId = null): array
    {
        $first = $month->copy()->startOfMonth();
        $on = $first->copy()->endOfMonth();

        return DB::transaction(function () use ($first, $on, $by, $schemeId) {
            $list = LoanInstallment::with('loan')->where('status', 'pending')->whereDate('due_month', $first->toDateString())
                ->whereHas('loan', fn ($l) => $l->where('status', 'active')->when($schemeId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('scheme_id', $schemeId))))->get();
            $capital = $interest = '0';
            foreach ($list as $i) {
                $capital = Decimal::add($capital, (string) $i->principal_amount);
                $interest = Decimal::add($interest, (string) $i->interest_amount);
                $this->recordDeduction($i, $on, $by);
            }
            $r = ['count' => $list->count(), 'capital' => Decimal::round($capital, ...self::rounding()), 'interest' => Decimal::round($interest, ...self::rounding())];
            Audit::log('loan.deductions_recorded', null, "Loan installments of {$first->format('M Y')} recorded as deducted ({$r['count']})", context: $r + ['month' => $first->format('Y-m'), 'scheme_id' => $schemeId]);

            return $r;
        });
    }

    /* ------------------------------ internals ------------------------------ */

    private function insertSchedule(Loan $loan, array $rows, Carbon $firstMonth, int $version, int $seqStart): void
    {
        foreach ($this->months($firstMonth, count($rows)) as $k => $m) {
            $this->newInstallment($loan, $version, $seqStart + $k, $m, $rows[$k]['principal'], $rows[$k]['interest']);
        }
    }

    private function newInstallment(Loan $loan, int $version, int $seq, Carbon $dueMonth, string $principal, string $interest): LoanInstallment
    {
        $i = new LoanInstallment;
        $i->forceFill(['loan_id' => $loan->id, 'schedule_version' => $version, 'seq' => $seq, 'due_month' => $dueMonth->copy()->startOfMonth()->toDateString(),
            'principal_amount' => $principal, 'interest_amount' => $interest, 'status' => 'pending'])->save();

        return $i;
    }

    private function ledger(Loan $loan, string $type, Carbon $on, string $principal, string $interest, ?int $installmentId, ?string $note, ?User $by): LoanLedgerEntry
    {
        $e = new LoanLedgerEntry;
        $e->forceFill(['loan_id' => $loan->id, 'entry_type' => $type, 'effective_date' => $on->toDateString(), 'principal' => $principal, 'interest' => $interest,
            'installment_id' => $installmentId, 'note' => $note ? mb_substr($note, 0, 500) : null, 'created_by' => $by?->id, 'created_by_name' => $by?->name])->save();

        return $e;
    }

    /** The loan reference, checked for duplicates among the employee's loans that are not cancelled. A blank one gets a temporary value, replaced by LN-000123. */
    private function reference(Employee $employee, ?string $reference): string
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return 'pending-'.Str::random(10);
        }
        if (mb_strlen($reference) > 40) {
            throw ValidationException::withMessages(['reference' => 'The reference can have at most 40 characters.']);
        }
        if (Loan::withoutGlobalScopes()->where('employee_id', $employee->id)->where('status', '!=', 'cancelled')->whereRaw('lower(reference) = ?', [mb_strtolower($reference)])->exists()) {
            throw ValidationException::withMessages(['reference' => 'This employee already has a loan with that reference.']);
        }

        return $reference;
    }

    private function money(mixed $v, string $field): string
    {
        $s = str_replace([',', ' '], '', trim((string) $v));
        if (! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $s)) {
            throw ValidationException::withMessages([$field => 'Enter an amount with up to 2 decimals.']);
        }

        return Decimal::make($s);
    }

    private function rate(?string $v, ?string $default): string
    {
        $s = str_replace([',', ' ', '%'], '', trim((string) $v));
        if ($s === '') {
            if ($default === null) {
                throw ValidationException::withMessages(['new_rate' => 'Enter the interest rate.']);
            }
            $s = $default;
        }
        if (! preg_match('/^\d{1,3}(\.\d{1,4})?$/', $s) || Decimal::cmp($s, '100') > 0) {
            throw ValidationException::withMessages(['rate' => 'The rate must be from 0 to 100 (up to 4 decimals).', 'new_rate' => 'The rate must be from 0 to 100 (up to 4 decimals).']);
        }

        return Decimal::make($s);
    }

    private function note(?string $n): ?string
    {
        $n = trim((string) $n);

        return $n === '' ? null : mb_substr($n, 0, 255);
    }
}
