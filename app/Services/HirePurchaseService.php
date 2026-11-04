<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\HirePurchase;
use App\Models\HirePurchaseChange;
use App\Models\HirePurchaseInstallment;
use App\Models\HirePurchaseVendor;
use App\Models\PayComponent;
use App\Models\User;
use App\Payroll\Decimal;
use App\Payroll\FormulaException;
use App\Payroll\HirePurchase\HirePurchaseSchedule;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * All hire-purchase rules live here (a future approval engine hooks into create()). Installments are never edited:
 * a change replaces the unpaid ones and adds new ones; a stop marks the unpaid ones stopped. Nothing is deleted.
 * Amounts are never written to the audit log. A pay run calls dueForPeriod() and, when it locks, recordDeduction().
 */
class HirePurchaseService
{
    /** An employee who is inactive here or in MiHCM, or whose last working day has passed. The pay run only FLAGS these; a person decides to stop. */
    public static function hasLeft(Employee $e): bool
    {
        return ! $e->is_active || ! $e->mihcm_active || $e->mihcm_deleted
            || ($e->last_working_date !== null && $e->last_working_date->lt(today()));
    }

    /* ------------------------------ reading ------------------------------ */

    public function pending(HirePurchase $hp): Collection
    {
        return $hp->installments()->where('status', 'pending')->get();
    }

    /** Total of unpaid installments. */
    public function remaining(Collection $pending): string
    {
        return Decimal::round($pending->reduce(fn ($c, $i) => Decimal::add($c, (string) $i->amount), '0'), 2);
    }

    /** @return array{count: int, total: string, next: ?HirePurchaseInstallment} for a list of pending installments (due-month order) */
    public function summary(Collection $pending): array
    {
        return ['count' => $pending->count(), 'total' => $this->remaining($pending), 'next' => $pending->first()];
    }

    /* ------------------------------ creating ------------------------------ */

    /** $d: amount, term, first_due_month (any date in the month), item_description, reference, notes */
    public function create(Employee $employee, HirePurchaseVendor $vendor, PayComponent $component, array $d, User $by): HirePurchase
    {
        $amount = $this->money($d['amount'] ?? null, 'amount');
        $term = (int) ($d['term'] ?? 0);
        $first = Carbon::parse($d['first_due_month'])->startOfMonth();
        $item = trim((string) ($d['item_description'] ?? ''));

        if (self::hasLeft($employee)) {
            throw ValidationException::withMessages(['employee_id' => 'This employee has left or is inactive, so no hire purchase can be added.']);
        }
        if (! $vendor->is_active) {
            throw ValidationException::withMessages(['hire_purchase_vendor_id' => 'This vendor is inactive.']);
        }
        $component->loadMissing('versions');
        if (! $component->is_active || $component->type !== 'deduction' || ! $component->latestVersion()) {
            throw ValidationException::withMessages(['pay_component_id' => 'Choose an active deduction component that has a calculation.']);
        }
        if ($item === '') {
            throw ValidationException::withMessages(['item_description' => 'Describe what was bought.']);
        }
        $rows = $this->schedule($amount, $term, $first->toDateString(), 1);

        return DB::transaction(function () use ($employee, $vendor, $component, $d, $by, $amount, $term, $first, $item, $rows) {
            Employee::withoutGlobalScopes()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $reference = $this->reference($employee, $d['reference'] ?? null);

            $hp = new HirePurchase;
            $hp->forceFill([
                'employee_id' => $employee->id, 'hire_purchase_vendor_id' => $vendor->id, 'pay_component_id' => $component->id,
                'reference' => $reference, 'item_description' => mb_substr($item, 0, 255),
                'installment_amount' => $amount, 'term_months' => $term, 'first_due_month' => $first->toDateString(),
                'status' => 'active', 'notes' => $this->note($d['notes'] ?? null),
                'approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), // approval engine hooks in here later
                'created_by' => $by->id, 'created_by_name' => $by->name,
            ])->save();
            if (trim((string) ($d['reference'] ?? '')) === '') { // no reference given: use HP-000123
                $hp->forceFill(['reference' => 'HP-'.str_pad((string) $hp->id, 6, '0', STR_PAD_LEFT)])->save();
            }

            foreach ($rows as $r) {
                $this->newInstallment($hp, 1, $r['seq'], $r['due_month'], $r['amount']);
            }

            Audit::log('hire_purchase.created', $hp, "Hire purchase {$hp->reference} created ({$vendor->name})", context: [
                'employee_id' => $employee->id, 'vendor' => $vendor->code, 'component' => $component->code, 'installments' => $term, 'first_due_month' => $first->format('Y-m'),
            ]);

            return $hp;
        });
    }

    /* ------------------------------ edit future deductions ------------------------------ */

    /**
     * What a change would do (nothing saved): the unpaid installments from $from are replaced by $newCount installments of $newAmount,
     * starting in $restart (default: the same month; a later month skips the months in between).
     *
     * @return array{month: Carbon, restart: Carbon, amount: string, count: int, affected: Collection, rows: array, old_amount: string, old_count: int, old_total: string, new_total: string, old_first: Carbon, old_last: Carbon, new_first: Carbon, new_last: Carbon}
     */
    public function changePlan(HirePurchase $hp, Carbon $from, string $newAmount, int $newCount, ?Carbon $restart = null): array
    {
        $month = $from->copy()->startOfMonth();
        $restart = ($restart ?? $month)->copy()->startOfMonth();
        $amount = $this->money($newAmount, 'new_amount');

        if ($hp->status !== 'active') {
            throw ValidationException::withMessages(['new_amount' => 'Only an active hire purchase can be changed.']);
        }
        if ($restart->lt($month)) {
            throw ValidationException::withMessages(['restart_month' => 'The new schedule cannot start before the month the change applies from.']);
        }
        if ($hp->installments()->where('status', 'deducted')->whereDate('due_month', '>=', $month->toDateString())->exists()) {
            throw ValidationException::withMessages(['effective_month' => 'Installments from that month were already deducted. Choose a later month.']);
        }

        $affected = $this->pending($hp)->filter(fn ($i) => $i->due_month->gte($month))->values();
        if ($affected->isEmpty()) {
            throw ValidationException::withMessages(['effective_month' => 'There are no unpaid installments from that month.']);
        }

        $rows = $this->schedule($amount, $newCount, $restart->toDateString(), (int) $affected[0]->seq);

        $same = count($rows) === $affected->count();
        foreach ($affected as $k => $i) {
            $same = $same && isset($rows[$k]) && Decimal::cmp((string) $i->amount, $amount) === 0 && $i->due_month->toDateString() === $rows[$k]['due_month'];
        }
        if ($same) {
            throw ValidationException::withMessages(['new_amount' => 'Nothing would change: these are the installments already planned.']);
        }

        return [
            'month' => $month, 'restart' => $restart, 'amount' => $amount, 'count' => $newCount, 'affected' => $affected, 'rows' => $rows,
            'old_amount' => Decimal::round((string) $affected[0]->amount, 2), 'old_count' => $affected->count(), 'old_total' => $this->remaining($affected),
            'new_total' => HirePurchaseSchedule::total($rows),
            'old_first' => $affected->first()->due_month->copy(), 'old_last' => $affected->last()->due_month->copy(),
            'new_first' => Carbon::parse($rows[0]['due_month']), 'new_last' => Carbon::parse($rows[count($rows) - 1]['due_month']),
        ];
    }

    public function changeFuture(HirePurchase $hp, Carbon $from, string $newAmount, int $newCount, ?Carbon $restart, string $note, User $by): HirePurchaseChange
    {
        return DB::transaction(function () use ($hp, $from, $newAmount, $newCount, $restart, $note, $by) {
            $hp = HirePurchase::withoutGlobalScopes()->whereKey($hp->id)->lockForUpdate()->firstOrFail();
            $plan = $this->changePlan($hp, $from, $newAmount, $newCount, $restart);
            $version = ((int) $hp->installments()->reorder()->max('schedule_version')) + 1;

            $plan['affected']->each(fn ($i) => $i->update(['status' => 'replaced']));
            foreach ($plan['rows'] as $r) {
                $this->newInstallment($hp, $version, $r['seq'], $r['due_month'], $r['amount']);
            }

            $change = new HirePurchaseChange;
            $change->forceFill([
                'hire_purchase_id' => $hp->id, 'effective_month' => $plan['month']->toDateString(), 'restart_month' => $plan['restart']->toDateString(),
                'old_amount' => $plan['old_amount'], 'new_amount' => $plan['amount'], 'old_remaining' => $plan['old_count'], 'new_remaining' => $plan['count'],
                'old_total' => $plan['old_total'], 'new_total' => $plan['new_total'], 'schedule_version' => $version,
                'change_note' => mb_substr(trim($note), 0, 500), 'created_by' => $by->id, 'created_by_name' => $by->name,
            ])->save();

            Audit::log('hire_purchase.changed', $hp, "Future deductions of {$hp->reference} changed from ".$plan['month']->format('M Y'), context: [
                'effective_month' => $plan['month']->format('Y-m'), 'restart_month' => $plan['restart']->format('Y-m'),
                'installments_before' => $plan['old_count'], 'installments_after' => $plan['count'], 'reason' => $change->change_note, 'schedule_version' => $version,
            ]);

            return $change;
        });
    }

    /* ------------------------------ stop ------------------------------ */

    /** Final: every unpaid installment is marked stopped and the hire purchase closes. A restart is a new hire purchase. */
    public function stop(HirePurchase $hp, string $reason, User $by): int
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for stopping the deductions.']);
        }

        return DB::transaction(function () use ($hp, $reason, $by) {
            $hp = HirePurchase::withoutGlobalScopes()->whereKey($hp->id)->lockForUpdate()->firstOrFail();
            if ($hp->status !== 'active') {
                throw ValidationException::withMessages(['reason' => 'Only an active hire purchase can be stopped.']);
            }
            $pending = $this->pending($hp);
            $pending->each(fn ($i) => $i->update(['status' => 'stopped']));
            $hp->forceFill(['status' => 'stopped', 'stopped_on' => today()->toDateString(), 'closed_reason' => mb_substr(trim($reason), 0, 500)])->save();

            Audit::log('hire_purchase.stopped', $hp, "Deductions of {$hp->reference} stopped", context: ['installments_stopped' => $pending->count(), 'reason' => trim($reason)]);

            return $pending->count();
        });
    }

    /* ------------------------------ payroll ------------------------------ */

    /**
     * What payroll deducts for a month, per employee. Includes unpaid installments of EARLIER months (flagged overdue) so a run can stop and ask.
     * Employees who have left are NOT filtered out: the run flags them (see hasLeft()) and a person decides.
     *
     * @return array<int, array{total: string, overdue: bool, lines: array}> keyed by employee id
     */
    public function dueForPeriod(Carbon $month, ?array $employeeIds = null, bool $includeOverdue = true): array
    {
        $first = $month->copy()->startOfMonth();

        $installments = HirePurchaseInstallment::with(['purchase.vendor'])->where('status', 'pending')
            ->whereDate('due_month', $includeOverdue ? '<=' : '=', $first->toDateString())
            ->whereHas('purchase', fn ($h) => $h->where('status', 'active')->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds)))
            ->orderBy('due_month')->orderBy('seq')->get();

        $out = [];
        foreach ($installments as $i) {
            $e = $i->purchase->employee_id;
            $out[$e] ??= ['total' => '0', 'overdue' => false, 'lines' => []];
            $overdue = $i->due_month->lt($first);
            $out[$e]['total'] = Decimal::add($out[$e]['total'], (string) $i->amount);
            $out[$e]['overdue'] = $out[$e]['overdue'] || $overdue;
            $out[$e]['lines'][] = [
                'installment_id' => $i->id, 'hire_purchase_id' => $i->hire_purchase_id, 'reference' => $i->purchase->reference, 'item' => $i->purchase->item_description,
                'vendor_id' => $i->purchase->hire_purchase_vendor_id, 'vendor_code' => $i->purchase->vendor->code, 'vendor_name' => $i->purchase->vendor->name,
                'seq' => $i->seq, 'due_month' => $i->due_month->toDateString(), 'overdue' => $overdue,
                'amount' => Decimal::round((string) $i->amount, 2), 'pay_component_id' => $i->purchase->pay_component_id,
            ];
        }
        foreach ($out as $e => $row) {
            $out[$e]['total'] = Decimal::round($row['total'], 2);
        }

        return $out;
    }

    /** Amount per pay component for one employee's lines. @return array<int, string> */
    public function componentAmounts(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $out[$l['pay_component_id']] = Decimal::add($out[$l['pay_component_id']] ?? '0', $l['amount']);
        }

        return array_map(fn ($v) => Decimal::round($v, 2), $out);
    }

    /** Marks one installment as deducted (call inside a transaction). A pay run calls this when it locks. */
    public function recordDeduction(HirePurchaseInstallment $installment, Carbon $on, ?User $by = null): void
    {
        $hp = HirePurchase::withoutGlobalScopes()->whereKey($installment->hire_purchase_id)->lockForUpdate()->firstOrFail();
        $installment = HirePurchaseInstallment::withoutGlobalScopes()->whereKey($installment->id)->lockForUpdate()->firstOrFail();
        if ($installment->status !== 'pending' || $hp->status !== 'active') {
            throw ValidationException::withMessages(['month' => "Installment {$installment->seq} of {$hp->reference} is not due for deduction."]);
        }

        $installment->update(['status' => 'deducted', 'deducted_on' => $on->toDateString()]);

        if (! HirePurchaseInstallment::withoutGlobalScopes()->where('hire_purchase_id', $hp->id)->where('status', 'pending')->exists()) {
            $hp->forceFill(['status' => 'completed'])->save();
        }
    }

    /** Records every pending installment of a month as deducted (until payroll does it). @return array{count: int, total: string} */
    public function recordMonth(Carbon $month, User $by, ?int $schemeId = null): array
    {
        $first = $month->copy()->startOfMonth();
        $on = $first->copy()->endOfMonth();

        return DB::transaction(function () use ($first, $on, $by, $schemeId) {
            $list = HirePurchaseInstallment::with('purchase')->where('status', 'pending')->whereDate('due_month', $first->toDateString())
                ->whereHas('purchase', fn ($h) => $h->where('status', 'active')->when($schemeId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('scheme_id', $schemeId))))->get();
            $total = '0';
            foreach ($list as $i) {
                $total = Decimal::add($total, (string) $i->amount);
                $this->recordDeduction($i, $on, $by);
            }
            $r = ['count' => $list->count(), 'total' => Decimal::round($total, 2)];
            Audit::log('hire_purchase.deductions_recorded', null, "Hire purchase installments of {$first->format('M Y')} recorded as deducted ({$r['count']})", context: ['count' => $r['count'], 'month' => $first->format('Y-m'), 'scheme_id' => $schemeId]);

            return $r;
        });
    }

    /* ------------------------------ internals ------------------------------ */

    private function schedule(string $amount, int $count, string $first, int $seq): array
    {
        try {
            return HirePurchaseSchedule::build($amount, $count, $first, $seq);
        } catch (FormulaException $e) {
            throw ValidationException::withMessages([str_contains($e->getMessage(), 'number of installments') ? 'term' : 'amount' => $e->getMessage()]);
        }
    }

    private function newInstallment(HirePurchase $hp, int $version, int $seq, string $dueMonth, string $amount): HirePurchaseInstallment
    {
        $i = new HirePurchaseInstallment;
        $i->forceFill(['hire_purchase_id' => $hp->id, 'schedule_version' => $version, 'seq' => $seq, 'due_month' => $dueMonth, 'amount' => $amount, 'status' => 'pending'])->save();

        return $i;
    }

    /** The reference, checked for duplicates among the employee's hire purchases. A blank one gets a temporary value, replaced by HP-000123. */
    private function reference(Employee $employee, ?string $reference): string
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return 'pending-'.Str::random(10);
        }
        if (mb_strlen($reference) > 40) {
            throw ValidationException::withMessages(['reference' => 'The reference can have at most 40 characters.']);
        }
        if (HirePurchase::withoutGlobalScopes()->where('employee_id', $employee->id)->whereRaw('lower(reference) = ?', [mb_strtolower($reference)])->exists()) {
            throw ValidationException::withMessages(['reference' => 'This employee already has a hire purchase with that reference.']);
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

    private function note(?string $n): ?string
    {
        $n = trim((string) $n);

        return $n === '' ? null : mb_substr($n, 0, 255);
    }
}
